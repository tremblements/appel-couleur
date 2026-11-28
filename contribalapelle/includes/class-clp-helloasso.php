<?php
if (!defined('ABSPATH')) {
    exit;
}

class CLP_HelloAsso
{
    const ORDER_STATE_OK = 'Authorized';
    const ITEM_STATE_OK = 'Processed';

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
            if (!is_array($order) || !self::order_paid($order)) {
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
            if (!is_array($order) || !self::order_paid($order)) {
                continue;
            }
            $payer_email = self::payer_email($order);
            if ($payer_email === '' || !hash_equals(strtolower(trim($payer_email)), $email_l)) {
                continue;
            }
            $order_id = isset($order['id']) ? (string) $order['id'] : '';
            if ($mode === 'order_id') {
                $candidates[] = array('ticket' => $order_id, 'order_id' => $order_id, 'email' => $payer_email, 'name' => self::payer_name($order));
                continue;
            }
            foreach (self::items_of($order) as $item) {
                if ($mode === 'item_id' && isset($item['id'])) {
                    $candidates[] = array('ticket' => (string) $item['id'], 'order_id' => $order_id, 'email' => $payer_email, 'name' => self::payer_name($order));
                } elseif ($mode === 'custom_field') {
                    foreach (self::custom_answers($item) as $answer) {
                        if ($answer !== '') {
                            $candidates[] = array('ticket' => $answer, 'order_id' => $order_id, 'email' => $payer_email, 'name' => self::payer_name($order));
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
        $out = array('ok' => false, 'reason' => '', 'count' => 0, 'total' => 0, 'states' => array(), 'missing' => array());
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
        $states = array();
        foreach ($fetched['orders'] as $order) {
            $state = is_array($order) ? (string) ($order['state'] ?? '') : '';
            $key = $state !== '' ? $state : '(sans état — liste light)';
            $states[$key] = isset($states[$key]) ? $states[$key] + 1 : 1;
            if (is_array($order) && self::order_paid($order)) {
                $out['count']++;
            }
        }
        $out['states'] = $states;
        $out['ok'] = true;
        return $out;
    }

    public static function count_items_cached(): int
    {
        $cached = get_transient('clp_ha_items_count');
        if (is_string($cached) || is_int($cached)) {
            return (int) $cached;
        }
        $count = self::count_items();
        set_transient('clp_ha_items_count', (string) $count, 300);
        return $count;
    }

    public static function count_items(): int
    {
        $settings = self::settings_ready();
        if (is_wp_error($settings)) {
            return 0;
        }
        $token = self::get_access_token();
        if (is_wp_error($token)) {
            return 0;
        }
        $url = sprintf(
            '%s/organizations/%s/forms/%s/%s/items',
            untrailingslashit($settings['helloasso_api_url']),
            rawurlencode($settings['helloasso_org_slug']),
            rawurlencode($settings['helloasso_form_type']),
            rawurlencode($settings['helloasso_form_slug'])
        );
        $count = 0;
        $page = 0;
        $max_pages = 100;
        $retried = false;
        $continuation = '';
        while ($page < $max_pages) {
            $page++;
            $args = array('pageSize' => 100);
            if ($continuation !== '') {
                $args['continuationToken'] = $continuation;
            }
            $response = wp_remote_get(add_query_arg($args, $url), array(
                'timeout' => 20,
                'headers' => array(
                    'Authorization' => 'Bearer ' . $token,
                    'Accept' => 'application/json',
                ),
            ));
            if (is_wp_error($response)) {
                break;
            }
            $code = wp_remote_retrieve_response_code($response);
            if ($code === 401 && !$retried) {
                $retried = true;
                delete_transient('clp_ha_access_token');
                $token = self::get_access_token();
                if (is_wp_error($token)) {
                    break;
                }
                $page--;
                continue;
            }
            if ($code !== 200) {
                break;
            }
            $body = json_decode(wp_remote_retrieve_body($response), true);
            $data = is_array($body) && isset($body['data']) && is_array($body['data']) ? $body['data'] : array();
            foreach ($data as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $state = (string) ($item['state'] ?? '');
                if ($state === '' || $state === self::ITEM_STATE_OK) {
                    $count++;
                }
            }
            if ($data === array()) {
                break;
            }
            $continuation = is_array($body) && isset($body['pagination']['continuationToken']) && is_string($body['pagination']['continuationToken'])
                ? $body['pagination']['continuationToken']
                : '';
            if ($continuation === '') {
                break;
            }
        }
        return $count;
    }

    public static function list_forms(string $form_type): array
    {
        $forms = array();
        $settings = self::settings_ready();
        if (is_wp_error($settings)) {
            return $forms;
        }
        $token = self::get_access_token();
        if (is_wp_error($token)) {
            return $forms;
        }
        $url = sprintf(
            '%s/organizations/%s/forms',
            untrailingslashit($settings['helloasso_api_url']),
            rawurlencode($settings['helloasso_org_slug'])
        );
        $page = 0;
        $max_pages = 20;
        $retried = false;
        $continuation = '';
        while ($page < $max_pages) {
            $page++;
            $args = array(
                'formTypes' => $form_type,
                'pageSize' => 100,
            );
            if ($continuation !== '') {
                $args['continuationToken'] = $continuation;
            }
            $paged_url = add_query_arg($args, $url);
            $response = wp_remote_get($paged_url, array(
                'timeout' => 20,
                'headers' => array(
                    'Authorization' => 'Bearer ' . $token,
                    'Accept' => 'application/json',
                ),
            ));
            if (is_wp_error($response)) {
                break;
            }
            $code = wp_remote_retrieve_response_code($response);
            if ($code === 401 && !$retried) {
                $retried = true;
                delete_transient('clp_ha_access_token');
                $token = self::get_access_token();
                if (is_wp_error($token)) {
                    break;
                }
                $page--;
                continue;
            }
            if ($code !== 200) {
                break;
            }
            $body = json_decode(wp_remote_retrieve_body($response), true);
            $data = is_array($body) && isset($body['data']) && is_array($body['data']) ? $body['data'] : array();
            foreach ($data as $form) {
                if (!is_array($form)) {
                    continue;
                }
                $forms[] = array(
                    'id' => isset($form['id']) ? (string) $form['id'] : '',
                    'title' => isset($form['title']) ? (string) $form['title'] : '',
                    'formType' => isset($form['formType']) ? (string) $form['formType'] : '',
                    'state' => isset($form['state']) ? (string) $form['state'] : '',
                );
            }
            if ($data === array()) {
                break;
            }
            $continuation = is_array($body) && isset($body['pagination']['continuationToken']) && is_string($body['pagination']['continuationToken'])
                ? $body['pagination']['continuationToken']
                : '';
            if ($continuation === '') {
                break;
            }
        }
        return $forms;
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

        $page = 0;
        $max_pages = 50;
        $retried = false;
        $continuation = '';

        while ($page < $max_pages) {
            $page++;
            $args = array(
                'pageSize' => 100,
                'withDetails' => 'true',
            );
            if ($continuation !== '') {
                $args['continuationToken'] = $continuation;
            }
            $paged_url = add_query_arg($args, $url);

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
                $page--;
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
            $continuation = is_array($body) && isset($body['pagination']['continuationToken']) && is_string($body['pagination']['continuationToken'])
                ? $body['pagination']['continuationToken']
                : '';
            if ($continuation === '') {
                break;
            }
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

    private static function order_paid(array $order): bool
    {
        $state = (string) ($order['state'] ?? '');
        if ($state !== '' && $state !== self::ORDER_STATE_OK) {
            return false;
        }
        $items = isset($order['items']) && is_array($order['items']) ? $order['items'] : array();
        if ($items === array()) {
            return $state === '' ? true : $state === self::ORDER_STATE_OK;
        }
        $has_state = false;
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $item_state = (string) ($item['state'] ?? '');
            if ($item_state === '') {
                continue;
            }
            $has_state = true;
            if ($item_state === self::ITEM_STATE_OK) {
                return true;
            }
        }
        if (!$has_state) {
            return true;
        }
        return false;
    }

    private static function items_of(array $order): array
    {
        $items = isset($order['items']) && is_array($order['items']) ? $order['items'] : array();
        $good = array();
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $state = (string) ($item['state'] ?? '');
            if ($state !== '' && $state !== self::ITEM_STATE_OK) {
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

    private static function payer_name(array $order): string
    {
        if (isset($order['payer']['firstName']) && is_string($order['payer']['firstName']) && trim($order['payer']['firstName']) !== '') {
            return trim($order['payer']['firstName']);
        }
        if (isset($order['user']['firstName']) && is_string($order['user']['firstName']) && trim($order['user']['firstName']) !== '') {
            return trim($order['user']['firstName']);
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
            'name' => self::payer_name($order),
        );
    }

    private static function ticket_matches(string $input, string $value): bool
    {
        return trim($input) !== '' && hash_equals(trim($value), trim($input));
    }
}
