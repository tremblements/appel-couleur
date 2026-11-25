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
        $settings = CLP_Settings::get();

        if (empty($settings['helloasso_client_id']) || empty($settings['helloasso_client_secret'])
            || empty($settings['helloasso_org_slug']) || empty($settings['helloasso_form_slug'])) {
            $result['reason'] = 'configuration-incomplete';
            return $result;
        }

        $token = self::get_access_token();
        if (is_wp_error($token)) {
            $result['reason'] = 'auth-failed';
            return $result;
        }

        $url = sprintf(
            '%s/organizations/%s/forms/%s/%s/orders',
            untrailingslashit($settings['helloasso_api_url']),
            rawurlencode($settings['helloasso_org_slug']),
            rawurlencode($settings['helloasso_form_type']),
            rawurlencode($settings['helloasso_form_slug'])
        );

        return self::search_orders($settings, $token, $url, $ticket, false);
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

    private static function search_orders(array $settings, string $token, string $url, string $ticket, bool $retried): array
    {
        $result = array('valid' => false, 'reason' => '', 'order_id' => '', 'email' => '');
        $mode = $settings['helloasso_ticket_field'];
        $page = 1;
        $max_pages = 50;

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
                $result['reason'] = 'network-error';
                return $result;
            }

            $code = wp_remote_retrieve_response_code($response);
            if ($code === 401 && !$retried) {
                delete_transient('clp_ha_access_token');
                $token = self::get_access_token();
                if (is_wp_error($token)) {
                    $result['reason'] = 'auth-failed';
                    return $result;
                }
                return self::search_orders($settings, $token, $url, $ticket, true);
            }
            if ($code !== 200) {
                $result['reason'] = 'api-error-' . $code;
                return $result;
            }

            $body = json_decode(wp_remote_retrieve_body($response), true);
            $orders = is_array($body) && isset($body['data']) && is_array($body['data']) ? $body['data'] : array();
            if ($orders === array()) {
                break;
            }

            foreach ($orders as $order) {
                if (!is_array($order)) {
                    continue;
                }
                if (!in_array((string) ($order['state'] ?? ''), self::ORDER_STATES_OK, true)) {
                    continue;
                }

                if ($mode === 'order_id' && isset($order['id']) && self::ticket_matches($ticket, (string) $order['id'])) {
                    return self::found($order);
                }

                $items = isset($order['items']) && is_array($order['items']) ? $order['items'] : array();
                foreach ($items as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    if (in_array((string) ($item['state'] ?? ''), self::ITEM_STATES_BAD, true)) {
                        continue;
                    }
                    if ($mode === 'item_id' && isset($item['id']) && self::ticket_matches($ticket, (string) $item['id'])) {
                        return self::found($order);
                    }
                    if ($mode === 'custom_field') {
                        $fields = isset($item['customFields']) && is_array($item['customFields']) ? $item['customFields'] : array();
                        foreach ($fields as $field) {
                            $answer = is_array($field) ? (string) ($field['answer'] ?? '') : '';
                            if ($answer !== '' && self::ticket_matches($ticket, $answer)) {
                                return self::found($order);
                            }
                        }
                    }
                }
            }

            $total_pages = is_array($body) && isset($body['pagination']['totalPages']) ? (int) $body['pagination']['totalPages'] : 1;
            if ($page >= $total_pages) {
                break;
            }
            $page++;
        }

        $result['reason'] = 'not-found';
        return $result;
    }

    private static function found(array $order): array
    {
        $email = '';
        if (isset($order['payer']['email']) && is_string($order['payer']['email'])) {
            $email = $order['payer']['email'];
        } elseif (isset($order['user']['email']) && is_string($order['user']['email'])) {
            $email = $order['user']['email'];
        }
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
