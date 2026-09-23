<?php
if (!defined('ABSPATH')) {
    exit;
}

class DAC_Dropbox
{
    private const API_BASE = 'https://content.dropboxapi.com/2/files/upload';

    public static function upload(string $local_path, string $dropbox_path, string $ticket): array
    {
        if (!is_readable($local_path)) {
            return array('success' => false, 'error' => 'fichier-illisible');
        }

        $settings = DAC_Settings::get();
        $token = $settings['dropbox_access_token'];
        if ($token === '') {
            return array('success' => false, 'error' => 'dropbox-non-configue');
        }

        $args = array(
            'path' => self::normalize_path($dropbox_path),
            'mode' => 'add',
            'autorename' => true,
            'mute' => false,
            'strict_conflict' => false,
        );

        $response = wp_remote_post(self::API_BASE, array(
            'timeout' => 60,
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

        if ($code >= 200 && $code < 300 && !empty($body['name'])) {
            return array('success' => true, 'path' => $body['path_display'] ?? $dropbox_path, 'ticket' => $ticket);
        }

        $summary = is_array($body) ? ($body['error_summary'] ?? '') : '';
        return array('success' => false, 'error' => $summary !== '' ? $summary : 'erreur-http-' . $code);
    }

    public static function target_path(string $category, string $filename, string $ticket): string
    {
        $settings = DAC_Settings::get();
        $root = self::normalize_path($settings['dropbox_root']);
        $folder = '';
        if ($category === 'nouvelle') {
            $folder = trim($settings['folder_nouvelles'], '/');
        } elseif ($category === 'poeme') {
            $folder = trim($settings['folder_poemes'], '/');
        } elseif ($category === 'photo') {
            $folder = trim($settings['folder_photos'], '/');
        }
        $safe_filename = self::sanitize_filename($filename);
        $prefix = $ticket !== '' ? $ticket . '-' : '';
        return $root . '/' . $folder . '/' . $prefix . $safe_filename;
    }

    private static function normalize_path(string $path): string
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

    private static function sanitize_filename(string $filename): string
    {
        $filename = remove_accents($filename);
        $filename = preg_replace('/[^A-Za-z0-9._-]/', '-', $filename);
        if (!is_string($filename) || $filename === '') {
            $filename = 'depot';
        }
        $filename = preg_replace('/-+/', '-', $filename);
        return trim($filename, '-');
    }
}
