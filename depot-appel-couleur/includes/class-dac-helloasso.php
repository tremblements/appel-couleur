<?php
if (!defined('ABSPATH')) {
    exit;
}

class DAC_HelloAsso
{
    public static function verify_ticket(string $ticket): array
    {
        $settings = DAC_Settings::get();
        $result = array('valid' => false, 'reason' => '', 'payer' => '');

        if (empty($settings['helloasso_client_id']) || empty($settings['helloasso_client_secret']) ||
            empty($settings['helloasso_org_slug']) || empty($settings['helloasso_form_slug'])) {
            $result['reason'] = 'configuration-incomplete';
            return $result;
        }

        $token = self::get_access_token($settings);
        if (is_wp_error($token)) {
            $result['reason'] = 'auth-failed';
            return $result;
        }

        $field = $settings['helloasso_ticket_field'];
        if ($field === 'order_id') {
            return self::verify_by_order_id($settings, $token, $ticket);
        }
        if ($field === 'custom_field') {
            return self::verify_by_custom_field($settings, $token, $ticket);
        }
        return self::verify_by_item_id($settings, $token, $ticket);
    }

    private static function get_access_token(array $settings)
    {
        $cached = get_transient('dac_ha_access_token');
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $base = untrailingslashit($settings['helloasso_api_url']);
        $oauth_url = str_replace('/v5', '', $base);
        if (substr($oauth_url, -7) !== '/oauth2') {
            $oauth_url = $oauth_url . '/oauth2';
        }
        $oauth_url .= '/token';

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
        if ($code !== 200 || empty($body['access_token'])) {
            return new WP_Error('dac_ha_auth', 'Authentification HelloAsso refusée.');
        }

        set_transient('dac_ha_access_token', $body['access_token'], min(1500, ((int) ($body['expires_in'] ?? 1800)) - 60));
        return $body['access_token'];
    }

    private static function verify_by_order_id(array $settings, string $token, string $ticket): array
    {
        $url = sprintf(
            '%s/organizations/%s/forms/%s/%s/orders',
            untrailingslashit($settings['helloasso_api_url']),
            rawurlencode($settings['helloasso_org_slug']),
            rawurlencode($settings['helloasso_form_type']),
            rawurlencode($settings['helloasso_form_slug'])
        );
        return self::search_orders_for_ticket($settings, $token, $url, $ticket, 'order_id');
    }

    private static function verify_by_item_id(array $settings, string $token, string $ticket): array
    {
        $url = sprintf(
            '%s/organizations/%s/forms/%s/%s/orders',
            untrailingslashit($settings['helloasso_api_url']),
            rawurlencode($settings['helloasso_org_slug']),
            rawurlencode($settings['helloasso_form_type']),
            rawurlencode($settings['helloasso_form_slug'])
        );
        return self::search_orders_for_ticket($settings, $token, $url, $ticket, 'item_id');
    }

    private static function verify_by_custom_field(array $settings, string $token, string $ticket): array
    {
        $url = sprintf(
            '%s/organizations/%s/forms/%s/%s/orders',
            untrailingslashit($settings['helloasso_api_url']),
            rawurlencode($settings['helloasso_org_slug']),
            rawurlencode($settings['helloasso_form_type']),
            rawurlencode($settings['helloasso_form_slug'])
        );
        return self::search_orders_for_ticket($settings, $token, $url, $ticket, 'custom_field');
    }

    private static function search_orders_for_ticket(array $settings, string $token, string $url, string $ticket, string $mode): array
    {
        $result = array('valid' => false, 'reason' => '', 'payer' => '');
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
                'headers' => array('Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'),
            ));

            if (is_wp_error($response)) {
                $result['reason'] = 'network-error';
                return $result;
            }

            $code = wp_remote_retrieve_response_code($response);
            if ($code !== 200) {
                $result['reason'] = 'api-error-' . $code;
                return $result;
            }

            $body = json_decode(wp_remote_retrieve_body($response), true);
            $orders = $body['data'] ?? array();
            if (!is_array($orders) || $orders === array()) {
                break;
            }

            foreach ($orders as $order) {
                if (!is_array($order)) {
                    continue;
                }
                $order_state = $order['state'] ?? '';
                if (in_array($order_state, array('Canceled', 'Abandoned', 'Refused', 'Deleted'), true)) {
                    continue;
                }

                if ($mode === 'order_id' && isset($order['id']) && self::ticket_matches($ticket, (string) $order['id'])) {
                    $result['valid'] = true;
                    $result['payer'] = self::payer_name($order);
                    return $result;
                }

                $items = $order['items'] ?? array();
                if (!is_array($items)) {
                    continue;
                }
                foreach ($items as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $item_state = $item['state'] ?? '';
                    if (in_array($item_state, array('Canceled', 'Abandoned', 'Refused', 'Deleted'), true)) {
                        continue;
                    }
                    if ($mode === 'item_id' && isset($item['id']) && self::ticket_matches($ticket, (string) $item['id'])) {
                        $result['valid'] = true;
                        $result['payer'] = self::payer_name($order);
                        return $result;
                    }
                    if ($mode === 'custom_field') {
                        $fields = $item['customFields'] ?? array();
                        if (is_array($fields)) {
                            foreach ($fields as $field) {
                                $answer = is_array($field) ? ($field['answer'] ?? '') : '';
                                if (is_string($answer) && self::ticket_matches($ticket, $answer)) {
                                    $result['valid'] = true;
                                    $result['payer'] = self::payer_name($order);
                                    return $result;
                                }
                            }
                        }
                    }
                }
            }

            $total_pages = $body['pagination']['totalPages'] ?? 1;
            if ($page >= (int) $total_pages) {
                break;
            }
            $page++;
        }

        $result['reason'] = 'not-found';
        return $result;
    }

    private static function ticket_matches(string $input, string $value): bool
    {
        return trim($input) !== '' && hash_equals(trim($value), trim($input));
    }

    private static function payer_name(array $order): string
    {
        $first = $order['payer']['firstName'] ?? '';
        $last = $order['payer']['lastName'] ?? '';
        $name = trim($first . ' ' . $last);
        if ($name === '') {
            $first = $order['user']['firstName'] ?? '';
            $last = $order['user']['lastName'] ?? '';
            $name = trim($first . ' ' . $last);
        }
        return $name;
    }
}
