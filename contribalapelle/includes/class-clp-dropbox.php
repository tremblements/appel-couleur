<?php
if (!defined('ABSPATH')) {
    exit;
}

class CLP_Dropbox
{
    const API_UPLOAD = 'https://content.dropboxapi.com/2/files/upload';
    const API_DELETE = 'https://api.dropboxapi.com/2/files/delete_v2';

    public static function upload(string $local_path, string $dropbox_path): array
    {
        if (!is_readable($local_path)) {
            return array('success' => false, 'error' => 'fichier-illisible');
        }

        $token = (string) CLP_Settings::get()['dropbox_token'];
        if ($token === '') {
            return array('success' => false, 'error' => 'dropbox-non-configure');
        }

        $args = array(
            'path' => self::normalize_path($dropbox_path),
            'mode' => 'overwrite',
            'autorename' => false,
            'mute' => false,
            'strict_conflict' => false,
        );

        $response = wp_remote_post(self::API_UPLOAD, array(
            'timeout' => 120,
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Dropbox-API-Arg' => wp_json_encode($args),
                'Content-Type' => 'application/octet-stream',
            ),
            'body' => file_get_contents($local_path),
        ));

        if (is_wp_error($response)) {
            return array('success' => false, 'error' => $response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code >= 200 && $code < 300 && is_array($body) && !empty($body['name'])) {
            return array('success' => true, 'path' => isset($body['path_display']) ? (string) $body['path_display'] : self::normalize_path($dropbox_path));
        }

        $summary = is_array($body) && isset($body['error_summary']) ? (string) $body['error_summary'] : '';
        return array('success' => false, 'error' => $summary !== '' ? $summary : 'erreur-http-' . $code);
    }

    public static function delete(string $dropbox_path): bool
    {
        $token = (string) CLP_Settings::get()['dropbox_token'];
        if ($token === '') {
            return false;
        }
        $response = wp_remote_post(self::API_DELETE, array(
            'timeout' => 30,
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
            ),
            'body' => wp_json_encode(array('path' => self::normalize_path($dropbox_path))),
        ));
        if (is_wp_error($response)) {
            return false;
        }
        $code = wp_remote_retrieve_response_code($response);
        return $code >= 200 && $code < 300;
    }

    public static function target_path(string $subcategory, string $ext, string $ticket): string
    {
        $subs = CLP_Settings::subcategories();
        $folder = isset($subs[$subcategory]) ? $subs[$subcategory]['folder'] : 'fiction';
        $date = CLP_Window::now()->format('ymd');
        $safe_ticket = preg_replace('/[^A-Za-z0-9_-]/', '-', $ticket);
        if (!is_string($safe_ticket) || $safe_ticket === '') {
            $safe_ticket = 'ticket';
        }
        $filename = $date . '-' . $safe_ticket . '.' . strtolower($ext);
        return self::normalize_path(CLP_Settings::dropbox_root() . '/' . $folder . '/' . $filename);
    }

    public static function normalize_path(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '';
        }
        if ($path[0] !== '/') {
            $path = '/' . $path;
        }
        return rtrim($path, '/');
    }
}
