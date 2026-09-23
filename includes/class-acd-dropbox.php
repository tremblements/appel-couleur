<?php
/**
 * Client minimaliste pour l'API Dropbox (Content API v2).
 *
 * Upload : POST https://content.dropboxapi.com/2/files/upload
 * avec l'en-tête Dropbox-API-Arg (chemin de destination en mode "overwrite").
 *
 * @see https://www.dropbox.com/developers/documentation/http/documentation#files-upload
 */

if (!defined('ABSPATH')) {
    exit;
}

class ACD_Dropbox
{
    const UPLOAD_URL = 'https://content.dropboxapi.com/2/files/upload';

    private $access_token;

    public function __construct($access_token)
    {
        $this->access_token = $access_token;
    }

    public static function from_options()
    {
        return new self(acd_get_option('dropbox_access_token'));
    }

    public function is_configured()
    {
        return !empty($this->access_token);
    }

    /**
     * Envoie un fichier dans le dossier Dropbox indiqué.
     *
     * @param string $folder Dossier de destination, ex. "/nouvelles".
     * @param string $filename Nom du fichier final dans Dropbox.
     * @param string $contents Contenu binaire du fichier.
     * @return true|WP_Error
     */
    public function upload($folder, $filename, $contents)
    {
        if (!$this->is_configured()) {
            return new WP_Error('acd_dropbox_not_configured', 'Dropbox n\'est pas configuré.');
        }

        $path = '/' . trim($folder, '/') . '/' . rawurlencode_basename($filename);
        $args = array(
            'path' => $path,
            'mode' => 'overwrite',
            'autorename' => false,
            'mute' => true,
        );

        $response = wp_remote_post(self::UPLOAD_URL, array(
            'timeout' => 60,
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->access_token,
                'Dropbox-API-Arg' => wp_json_encode($args),
                'Content-Type' => 'application/octet-stream',
            ),
            'body' => $contents,
        ));

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (200 !== $code || empty($body['id'])) {
            $message = isset($body['error_summary']) ? $body['error_summary'] : 'erreur inconnue';
            return new WP_Error(
                'acd_dropbox_upload_failed',
                'Échec de l\'envoi Dropbox (' . $message . ').'
            );
        }

        return true;
    }
}

if (!function_exists('rawurlencode_basename')) {
    function rawurlencode_basename($filename)
    {
        $parts = preg_split('/\.(?=[^.]+$)/', $filename);
        if (2 === count($parts)) {
            return rawurlencode($parts[0]) . '.' . rawurlencode($parts[1]);
        }
        return rawurlencode($filename);
    }
}
