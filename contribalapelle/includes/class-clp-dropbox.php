<?php
if (!defined('ABSPATH')) {
    exit;
}

class CLP_Dropbox
{
    const API_UPLOAD = 'https://content.dropboxapi.com/2/files/upload';
    const API_DELETE = 'https://api.dropboxapi.com/2/files/delete_v2';
    const OAUTH_TOKEN = 'https://api.dropboxapi.com/oauth2/token';

    public static function upload(string $local_path, string $dropbox_path): array
    {
        if (!is_readable($local_path)) {
            return array('success' => false, 'error' => 'fichier-illisible');
        }

        $args = array(
            'path' => self::normalize_path($dropbox_path),
            'mode' => array('.tag' => 'overwrite'),
            'autorename' => false,
            'mute' => false,
            'strict_conflict' => false,
        );

        $response = self::call(self::API_UPLOAD, array(
            'timeout' => 120,
            'headers' => array(
                'Dropbox-API-Arg' => wp_json_encode($args),
                'Content-Type' => 'application/octet-stream',
            ),
            'body' => file_get_contents($local_path),
        ));

        if ($response['error'] !== '') {
            return array('success' => false, 'error' => $response['error']);
        }
        if ($response['code'] === 401 || $response['code'] === 403) {
            return array('success' => false, 'error' => 'dropbox-token-expire');
        }
        if ($response['code'] >= 200 && $response['code'] < 300 && is_array($response['body']) && !empty($response['body']['name'])) {
            return array('success' => true, 'path' => isset($response['body']['path_display']) ? (string) $response['body']['path_display'] : self::normalize_path($dropbox_path));
        }

        $summary = is_array($response['body']) && isset($response['body']['error_summary']) ? (string) $response['body']['error_summary'] : '';
        return array('success' => false, 'error' => $summary !== '' ? $summary : 'erreur-http-' . $response['code']);
    }

    public static function delete(string $dropbox_path): bool
    {
        $response = self::call(self::API_DELETE, array(
            'timeout' => 30,
            'headers' => array('Content-Type' => 'application/json'),
            'body' => wp_json_encode(array('path' => self::normalize_path($dropbox_path))),
        ));
        if ($response['error'] !== '') {
            return false;
        }
        return $response['code'] >= 200 && $response['code'] < 300;
    }

    public static function target_path(string $subcategory, string $ext, string $ticket, string $code = ''): string
    {
        $subs = CLP_Settings::subcategories();
        $folder = isset($subs[$subcategory]) ? $subs[$subcategory]['folder'] : 'fiction_500';
        $date = CLP_Window::now()->format('ymd');
        $safe_ticket = preg_replace('/[^A-Za-z0-9_-]/', '-', $ticket);
        if (!is_string($safe_ticket) || $safe_ticket === '') {
            $safe_ticket = 'ticket';
        }
        $filename = $date . '-' . $safe_ticket;
        if ($code !== '') {
            $safe_code = preg_replace('/[^\p{L}\p{N}-]/u', '', $code);
            if (is_string($safe_code) && $safe_code !== '') {
                $filename .= '-' . $safe_code;
            }
        }
        $filename .= '.' . strtolower($ext);
        return self::normalize_path(CLP_Settings::dropbox_root() . '/' . $folder . '/' . $filename);
    }

    public static function normalize_path(string $path): string
    {
        $path = '/' . ltrim($path, '/');
        return preg_replace('#/+#', '/', $path);
    }

    private static function access_token(): string
    {
        $cached = get_transient('clp_dropbox_access_token');
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $s = CLP_Settings::get();
        if (!empty($s['dropbox_refresh_token']) && !empty($s['dropbox_client_id']) && !empty($s['dropbox_client_secret'])) {
            $response = wp_remote_post(self::OAUTH_TOKEN, array(
                'timeout' => 20,
                'body' => array(
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $s['dropbox_refresh_token'],
                    'client_id' => $s['dropbox_client_id'],
                    'client_secret' => $s['dropbox_client_secret'],
                ),
            ));
            if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
                $body = json_decode(wp_remote_retrieve_body($response), true);
                if (is_array($body) && !empty($body['access_token'])) {
                    $expires = isset($body['expires_in']) ? (int) $body['expires_in'] : 14400;
                    set_transient('clp_dropbox_access_token', (string) $body['access_token'], max(300, $expires - 300));
                    return (string) $body['access_token'];
                }
            }
            return '';
        }

        return (string) $s['dropbox_token'];
    }

    private static function call(string $url, array $request_args): array
    {
        $token = self::access_token();
        if ($token === '') {
            return array('error' => 'dropbox-non-configure', 'code' => 0, 'body' => null);
        }
        if (!isset($request_args['headers']) || !is_array($request_args['headers'])) {
            $request_args['headers'] = array();
        }
        $request_args['headers']['Authorization'] = 'Bearer ' . $token;

        $response = wp_remote_request($url, $request_args);
        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 401) {
            delete_transient('clp_dropbox_access_token');
            $token = self::access_token();
            if ($token !== '') {
                $request_args['headers']['Authorization'] = 'Bearer ' . $token;
                $response = wp_remote_request($url, $request_args);
            }
        }
        if (is_wp_error($response)) {
            return array('error' => $response->get_error_message(), 'code' => 0, 'body' => null);
        }
        return array(
            'error' => '',
            'code' => wp_remote_retrieve_response_code($response),
            'body' => json_decode(wp_remote_retrieve_body($response), true),
        );
    }
}
