<?php
if (!defined('ABSPATH')) {
    exit;
}

class DAC_Upload
{
    private const ALLOWED = array(
        'nouvelle' => array('docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'odt' => 'application/vnd.oasis.opendocument.text'),
        'poeme' => array('docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'odt' => 'application/vnd.oasis.opendocument.text'),
        'photo' => array('jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'tiff' => 'image/tiff'),
    );

    public static function init(): void
    {
        add_action('wp_ajax_dac_upload', array(__CLASS__, 'handle'));
        add_action('wp_ajax_nopriv_dac_upload', array(__CLASS__, 'handle'));
    }

    public static function handle(): void
    {
        check_ajax_referer('dac_upload', 'nonce');

        $result = array('success' => false, 'message' => '');
        $status = 400;
        $temp_file = '';

        try {
            $category = isset($_POST['category']) ? sanitize_key($_POST['category']) : '';
            $ticket = isset($_POST['ticket']) ? sanitize_text_field(wp_unslash($_POST['ticket'])) : '';

            if (!array_key_exists($category, self::ALLOWED)) {
                throw new RuntimeException('Catégorie de dépôt invalide.');
            }
            if ($ticket === '') {
                throw new RuntimeException('Merci de saisir votre numéro de ticket HelloAsso.');
            }

            $verification = DAC_HelloAsso::verify_ticket($ticket);
            if (!$verification['valid']) {
                $status = 403;
                throw new RuntimeException(self::verification_message($verification['reason']));
            }

            if (empty($_FILES['file']) || !isset($_FILES['file']['tmp_name'])) {
                throw new RuntimeException('Aucun fichier reçu.');
            }

            require_once ABSPATH . 'wp-admin/includes/file.php';
            $file = $_FILES['file'];
            $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
            $allowed = self::ALLOWED[$category];

            if (!array_key_exists($ext, $allowed)) {
                $status = 415;
                throw new RuntimeException(sprintf(
                    'Format non accepté pour cette catégorie. Formats autorisés : %s.',
                    implode(', ', array_keys($allowed))
                ));
            }

            $settings = DAC_Settings::get();
            $max_bytes = ((int) $settings['max_file_mb']) * 1024 * 1024;
            if ((int) $file['size'] > $max_bytes) {
                $status = 413;
                throw new RuntimeException(sprintf(
                    'Fichier trop volumineux (max %d Mo).',
                    (int) $settings['max_file_mb']
                ));
            }

            $overrides = array(
                'test_form' => false,
                'mimes' => $allowed,
            );
            $move = wp_handle_upload($file, $overrides);
            if (!is_array($move) || !isset($move['file'])) {
                throw new RuntimeException('Échec de l\'enregistrement du fichier sur le serveur.');
            }
            $temp_file = (string) $move['file'];

            $target = DAC_Dropbox::target_path($category, basename((string) $file['name']), $ticket);
            $upload = DAC_Dropbox::upload($temp_file, $target, $ticket);

            if (!$upload['success']) {
                throw new RuntimeException('Erreur lors de l\'envoi vers Dropbox : ' . $upload['error']);
            }

            DAC_Counters::increment($category);
            $result = array(
                'success' => true,
                'message' => 'Votre dépôt a bien été enregistré. Merci !',
                'category' => $category,
                'counters' => DAC_Counters::all(),
            );
            $status = 200;
        } catch (RuntimeException $e) {
            $result['message'] = $e->getMessage();
        }

        if ($temp_file !== '' && is_string($temp_file) && file_exists($temp_file)) {
            wp_delete_file($temp_file);
        }

        wp_send_json($result, $status);
    }

    private static function verification_message(string $reason): string
    {
        $messages = array(
            'configuration-incomplete' => 'La vérification des tickets n\'est pas configurée. Contactez l\'administrateur du site.',
            'auth-failed' => 'Impossible de vérifier votre ticket pour le moment. Réessayez plus tard.',
            'network-error' => 'Impossible de joindre HelloAsso. Réessayez plus tard.',
            'not-found' => 'Numéro de ticket introuvable. Vérifiez votre saisie ou contactez l\'association.',
        );
        return $messages[$reason] ?? 'Votre ticket n\'a pas pu être validé.';
    }
}
