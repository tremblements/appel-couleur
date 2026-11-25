<?php
if (!defined('ABSPATH')) {
    exit;
}

class CLP_HelloAsso
{
    const ORDER_STATES_OK = array('Authorized');
    const ITEM_STATES_BAD = array('Canceled', 'Abandoned', 'Refused', 'Deleted');

    public static function verify_ticket(string $ticket): array
    {
        $result = array('valid' => false, 'reason' => '', 'order_id' => '', 'email' => '');
        $settings = self::settings_ready();
        if (is_wp_error($settings)) {
            $result['reason'] = 'configuration-incomplete';
            return $result;
        }
        $fetched = self::fetch_all_orders($settings);
        if ($fetched['error'] !== '') {
            $result['reason'] = $fetched['error'];
            return $result;
        }
        $mode = $settings['helloasso_ticket_field'];
        foreach ($fetched['orders'] as $order) {
            if (!is_array($order) || !self::order_ok($order)) {
                continue;
            }
            if ($mode === 'order_id' && isset($order['id']) && self::ticket_matches($ticket, (string) $order['id'])) {
                return self::found($order);
            }
            foreach (self::items_of($order) as $item) {
                if ($mode === 'item_id' && isset($item['id']) && self::ticket_matches($ticket, (string) $item['id'])) {
                    return self::found($order);
                }
                if ($mode === 'custom_field') {
                    foreach (self::custom_answers($item) as $answer) {
                        if ($answer !== '' && self::ticket_matches($ticket, $answer)) {
                            return self::found($order);
                        }
                    }
                }
            }
        }
        $result['reason'] = 'not-found';
        return $result;
    }

    public static function find_by_email(string $email): array
    {
        $candidates = array();
        $settings = self::settings_ready();
        if (is_wp_error($settings)) {
            return $candidates;
        }
        $fetched = self::fetch_all_orders($settings);
        if ($fetched['error'] !== '') {
            return $candidates;
        }
        $mode = $settings['helloasso_ticket_field'];
        $email_l = strtolower(trim($email));
        foreach ($fetched['orders'] as $order) {
            if (!is_array($order) || !self::order_ok($order)) {
                continue;
            }
            $payer_email = self::payer_email($order);
            if ($payer_email === '' || !hash_equals(strtolower(trim($payer_email)), $email_l)) {
                continue;
            }
            $order_id = isset($order['id']) ? (string) $order['id'] : '';
            if ($mode === 'order_id') {
                $candidates[] = array('ticket' => $order_id, 'order_id' => $order_id, 'email' => $payer_email);
                continue;
            }
            foreach (self::items_of($order) as $item) {
                if ($mode === 'item_id' && isset($item['id'])) {
                    $candidates[] = array('ticket' => (string) $item['id'], 'order_id' => $order_id, 'email' => $payer_email);
                } elseif ($mode === 'custom_field') {
                    foreach (self::custom_answers($item) as $answer) {
                        if ($answer !== '') {
                            $candidates[] = array('ticket' => $answer, 'order_id' => $order_id, 'email' => $payer_email);
                        }
                    }
                }
            }
        }
        return $candidates;
    }

    public static function missing_config(): array
    {
        $s = CLP_Settings::get();
        $missing = array();
        if (empty($s['helloasso_client_id'])) {
            $missing[] = 'Client ID';
        }
        if (empty($s['helloasso_client_secret'])) {
            $missing[] = 'Client Secret';
        }
        if (empty($s['helloasso_org_slug'])) {
            $missing[] = "Slug de l'organisation";
        }
        if (empty($s['helloasso_form_slug'])) {
            $missing[] = 'Slug de la billetterie';
        }
        return $missing;
    }

    public static function count_authorized_orders(): array
    {
        $out = array('ok' => false, 'reason' => '', 'count' => 0, 'total' => 0, 'missing' => array());
        $missing = self::missing_config();
        if ($missing !== array()) {
            $out['reason'] = 'configuration-incomplete';
            $out['missing'] = $missing;
            return $out;
        }
        $settings = self::settings_ready();
        if (is_wp_error($settings)) {
            $out['reason'] = 'configuration-incomplete';
            return $out;
        }
        $fetched = self::fetch_all_orders($settings);
        if ($fetched['error'] !== '') {
            $out['reason'] = $fetched['error'];
            return $out;
        }
        $out['total'] = count($fetched['orders']);
        foreach ($fetched['orders'] as $order) {
            if (is_array($order) && self::order_ok($order)) {
                $out['count']++;
            }
        }
        $out['ok'] = true;
        return $out;
    }

    private static function settings_ready()
    {
        $settings = CLP_Settings::get();
        if (empty($settings['helloasso_client_id']) || empty($settings['helloasso_client_secret'])
            || empty($settings['helloasso_org_slug']) || empty($settings['helloasso_form_slug'])) {
            return new WP_Error('clp_ha_config', 'Configuration HelloAsso incomplète.');
        }
        return $settings;
    }

    private static function fetch_all_orders(array $settings): array
    {
        $out = array('error' => '', 'orders' => array());
        $token = self::get_access_token();
        if (is_wp_error($token)) {
            $out['error'] = 'auth-failed';
            return $out;
        }

        $url = sprintf(
            '%s/organizations/%s/forms/%s/%s/orders',
            untrailingslashit($settings['helloasso_api_url']),
            rawurlencode($settings['helloasso_org_slug']),
            rawurlencode($settings['helloasso_form_type']),
            rawurlencode($settings['helloasso_form_slug'])
        );

        $page = 1;
        $max_pages = 50;
        $retried = false;

        while ($page <= $max_pages) {
            $paged_url = add_query_arg(array(
                'pageIndex' => $page,
                'pageSize' => 100,
                'withDetails' => 'true',
            ), $url);

            $response = wp_remote_get($paged_url, array(
                'timeout' => 20,
                'headers' => array(
                    'Authorization' => 'Bearer ' . $token,
                    'Accept' => 'application/json',
                ),
            ));

            if (is_wp_error($response)) {
                $out['error'] = 'network-error';
                return $out;
            }

            $code = wp_remote_retrieve_response_code($response);
            if ($code === 401 && !$retried) {
                $retried = true;
                delete_transient('clp_ha_access_token');
                $token = self::get_access_token();
                if (is_wp_error($token)) {
                    $out['error'] = 'auth-failed';
                    return $out;
                }
                continue;
            }
            if ($code !== 200) {
                $out['error'] = 'api-error-' . $code;
                return $out;
            }

            $body = json_decode(wp_remote_retrieve_body($response), true);
            $orders = is_array($body) && isset($body['data']) && is_array($body['data']) ? $body['data'] : array();
            foreach ($orders as $order) {
                $out['orders'][] = $order;
            }
            if ($orders === array()) {
                break;
            }
            $total_pages = is_array($body) && isset($body['pagination']['totalPages']) ? (int) $body['pagination']['totalPages'] : 1;
            if ($page >= $total_pages) {
                break;
            }
            $page++;
        }

        return $out;
    }

    private static function get_access_token()
    {
        $cached = get_transient('clp_ha_access_token');
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $settings = CLP_Settings::get();
        $base = untrailingslashit($settings['helloasso_api_url']);
        $oauth_url = preg_replace('#/v5$#', '', $base);
        $oauth_url = untrailingslashit($oauth_url) . '/oauth2/token';

        $response = wp_remote_post($oauth_url, array(
            'timeout' => 15,
            'body' => array(
                'grant_type' => 'client_credentials',
                'client_id' => $settings['helloasso_client_id'],
                'client_secret' => $settings['helloasso_client_secret'],
            ),
        ));

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if ($code !== 200 || !is_array($body) || empty($body['access_token'])) {
            return new WP_Error('clp_ha_auth', 'Authentification HelloAsso refusée.');
        }

        $expires = isset($body['expires_in']) ? (int) $body['expires_in'] : 1800;
        set_transient('clp_ha_access_token', (string) $body['access_token'], max(60, min(1500, $expires - 60)));
        return (string) $body['access_token'];
    }

    private static function order_ok(array $order): bool
    {
        return in_array((string) ($order['state'] ?? ''), self::ORDER_STATES_OK, true);
    }

    private static function items_of(array $order): array
    {
        $items = isset($order['items']) && is_array($order['items']) ? $order['items'] : array();
        $good = array();
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            if (in_array((string) ($item['state'] ?? ''), self::ITEM_STATES_BAD, true)) {
                continue;
            }
            $good[] = $item;
        }
        return $good;
    }

    private static function custom_answers(array $item): array
    {
        $answers = array();
        $fields = isset($item['customFields']) && is_array($item['customFields']) ? $item['customFields'] : array();
        foreach ($fields as $field) {
            if (is_array($field) && isset($field['answer']) && is_string($field['answer'])) {
                $answers[] = $field['answer'];
            }
        }
        return $answers;
    }

    private static function payer_email(array $order): string
    {
        if (isset($order['payer']['email']) && is_string($order['payer']['email'])) {
            return $order['payer']['email'];
        }
        if (isset($order['user']['email']) && is_string($order['user']['email'])) {
            return $order['user']['email'];
        }
        return '';
    }

    private static function found(array $order): array
    {
        $email = self::payer_email($order);
        return array(
            'valid' => true,
            'reason' => '',
            'order_id' => isset($order['id']) ? (string) $order['id'] : '',
            'email' => is_email($email) ? $email : '',
        );
    }

    private static function ticket_matches(string $input, string $value): bool
    {
        return trim($input) !== '' && hash_equals(trim($value), trim($input));
    }
}
