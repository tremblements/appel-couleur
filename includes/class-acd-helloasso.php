<?php
/**
 * Client minimaliste pour l'API HelloAsso v5.
 *
 * Authentification OAuth 2.0 :
 *   POST https://api.helloasso.com/oauth2/token  (client_credentials / refresh_token)
 *
 * @see https://dev.helloasso.com/docs/api-overview
 */

if (!defined('ABSPATH')) {
    exit;
}

class ACD_HelloAsso
{
    const API_HOST = 'https://api.helloasso.com';
    const API_HOST_SANDBOX = 'https://api.helloasso-sandbox.com';

    private $client_id;
    private $client_secret;
    private $organization_slug;
    private $sandbox;

    public function __construct($client_id, $client_secret, $organization_slug, $sandbox = false)
    {
        $this->client_id = $client_id;
        $this->client_secret = $client_secret;
        $this->organization_slug = $organization_slug;
        $this->sandbox = (bool) $sandbox;
    }

    public static function from_options()
    {
        return new self(
            acd_get_option('helloasso_client_id'),
            acd_get_option('helloasso_client_secret'),
            acd_get_option('helloasso_organization_slug'),
            acd_get_option('helloasso_sandbox')
        );
    }

    private function api_host()
    {
        return $this->sandbox ? self::API_HOST_SANDBOX : self::API_HOST;
    }

    public function is_configured()
    {
        return !empty($this->client_id) && !empty($this->client_secret) && !empty($this->organization_slug);
    }

    /**
     * Récupère un access token (cache transitoire + refresh token).
     *
     * @return string|WP_Error
     */
    public function get_access_token()
    {
        if (!$this->is_configured()) {
            return new WP_Error('acd_helloasso_not_configured', 'HelloAsso n\'est pas configuré.');
        }

        $cached = get_transient('acd_helloasso_access_token');
        if (is_string($cached) && '' !== $cached) {
            return $cached;
        }

        $refresh_token = get_option('acd_helloasso_refresh_token');
        if (!empty($refresh_token)) {
            $data = $this->request_token(array(
                'grant_type' => 'refresh_token',
                'refresh_token' => $refresh_token,
            ));
            if (is_wp_error($data)) {
                delete_option('acd_helloasso_refresh_token');
                $data = $this->request_token($this->credentials_body());
            }
        } else {
            $data = $this->request_token($this->credentials_body());
        }

        if (is_wp_error($data)) {
            return $data;
        }

        if (!empty($data['refresh_token'])) {
            update_option('acd_helloasso_refresh_token', sanitize_text_field($data['refresh_token']), false);
        }
        $expires = isset($data['expires_in']) ? (int) $data['expires_in'] : 1800;
        set_transient('acd_helloasso_access_token', $data['access_token'], max(60, $expires - 120));

        return $data['access_token'];
    }

    private function credentials_body()
    {
        return array(
            'grant_type' => 'client_credentials',
            'client_id' => $this->client_id,
            'client_secret' => $this->client_secret,
        );
    }

    /**
     * @return array|WP_Error
     */
    private function request_token($body)
    {
        $response = wp_remote_post($this->api_host() . '/oauth2/token', array(
            'timeout' => 15,
            'headers' => array('Content-Type' => 'application/x-www-form-urlencoded'),
            'body' => $body,
        ));

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);

        if (200 !== $code || empty($data['access_token']) || !is_array($data)) {
            return new WP_Error(
                'acd_helloasso_auth_failed',
                'Échec d\'authentification HelloAsso (code ' . (int) $code . ').'
            );
        }

        return $data;
    }

    /**
     * Appel authentifié GET à l'API v5.
     *
     * @return array|WP_Error
     */
    public function get($path)
    {
        $token = $this->get_access_token();
        if (is_wp_error($token)) {
            return $token;
        }

        $response = $this->raw_get($path, $token);

        if (!is_wp_error($response) && 401 === wp_remote_retrieve_response_code($response)) {
            delete_transient('acd_helloasso_access_token');
            $token = $this->request_token($this->credentials_body());
            if (is_wp_error($token)) {
                return $token;
            }
            $response = $this->raw_get($path, $token);
        }

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            return new WP_Error('acd_helloasso_api_error', 'Erreur API HelloAsso (code ' . (int) $code . ').');
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($data)) {
            return new WP_Error('acd_helloasso_bad_response', 'Réponse HelloAsso illisible.');
        }

        return $data;
    }

    private function raw_get($path, $token)
    {
        return wp_remote_get($this->api_host() . '/v5' . $path, array(
            'timeout' => 15,
            'headers' => array('Authorization' => 'Bearer ' . $token),
        ));
    }

    /**
     * Vérifie qu'un numéro de ticket correspond à une commande de l'organisation.
     *
     * Stratégie v0.1 : le numéro saisi est comparé (sans casse) à l'identifiant
     * de chaque commande et aux réponses aux questions personnalisées des
     * articles vendus (le formulaire de vente peut demander le numéro de
     * ticket au participant).
     *
     * @param string $ticket_number
     * @return true|WP_Error
     */
    public function verify_ticket($ticket_number)
    {
        $ticket_number = sanitize_text_field($ticket_number);
        if ('' === $ticket_number) {
            return new WP_Error('acd_helloasso_empty_ticket', 'Numéro de ticket manquant.');
        }

        $orders = $this->fetch_all_orders();
        if (is_wp_error($orders)) {
            return $orders;
        }

        foreach ($orders as $order) {
            if ($this->order_matches_ticket($order, $ticket_number)) {
                $this->mark_ticket_verified($ticket_number, $order);
                return true;
            }
        }

        return new WP_Error(
            'acd_helloasso_ticket_not_found',
            'Ce numéro de ticket n\'a pas été reconnu. Vérifiez votre ticket ou contactez l\'équipe.'
        );
    }

    /**
     * @return array|WP_Error
     */
    private function fetch_all_orders()
    {
        $orders = array();
        $slug = rawurlencode($this->organization_slug);
        $path = '/organizations/' . $slug . '/orders?pageSize=100';

        for ($page = 0; $page < 20; $page++) {
            $data = $this->get($path);
            if (is_wp_error($data)) {
                return $data;
            }

            if (!empty($data['data']) && is_array($data['data'])) {
                foreach ($data['data'] as $order) {
                    if (is_array($order)) {
                        $orders[] = $order;
                    }
                }
            }

            if (empty($data['pagination']['continuationToken'])) {
                break;
            }
            $path = '/organizations/' . $slug . '/orders?pageSize=100&continuationToken='
                . rawurlencode((string) $data['pagination']['continuationToken']);
        }

        return $orders;
    }

    /**
     * @param array  $order
     * @param string $ticket_number
     * @return bool
     */
    private function order_matches_ticket($order, $ticket_number)
    {
        if (isset($order['id']) && 0 === strcasecmp((string) $order['id'], $ticket_number)) {
            return true;
        }

        foreach (array('ticket', 'ticketNumber', 'numeroTicket') as $field) {
            if (isset($order[$field]) && is_string($order[$field])
                && 0 === strcasecmp($order[$field], $ticket_number)) {
                return true;
            }
        }

        if (!empty($order['items']) && is_array($order['items'])) {
            foreach ($order['items'] as $item) {
                if (!is_array($item) || empty($item['customFields']) || !is_array($item['customFields'])) {
                    continue;
                }
                foreach ($item['customFields'] as $field) {
                    if (isset($field['answer']) && is_string($field['answer'])
                        && 0 === strcasecmp(trim($field['answer']), $ticket_number)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    private function mark_ticket_verified($ticket_number, $order)
    {
        $log = get_option('acd_verified_tickets', array());
        if (!is_array($log)) {
            $log = array();
        }
        $log[sanitize_text_field($ticket_number)] = array(
            'order_id' => isset($order['id']) ? sanitize_text_field((string) $order['id']) : '',
            'time' => time(),
        );
        update_option('acd_verified_tickets', $log, false);
    }
}
