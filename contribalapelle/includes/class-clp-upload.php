<?php
if (!defined('ABSPATH')) {
    exit;
}

class CLP_Upload
{
    public static function init(): void
    {
        add_action('wp_ajax_clp_verify', array(__CLASS__, 'handle_verify'));
        add_action('wp_ajax_nopriv_clp_verify', array(__CLASS__, 'handle_verify'));
        add_action('wp_ajax_clp_deposit', array(__CLASS__, 'handle_deposit'));
        add_action('wp_ajax_nopriv_clp_deposit', array(__CLASS__, 'handle_deposit'));
    }

    public static function handle_verify(): void
    {
        check_ajax_referer('clp_verify', 'nonce');
        $ticket = self::ticket_input();

        $window = CLP_Window::status();
        if ($window['status'] !== 'open') {
            CLP_DB::log('verify', $ticket, 'refused', 'hors-periode', $window['status']);
            wp_send_json(array(
                'success' => false,
                'reason' => 'hors-periode',
                'message' => self::window_message($window),
            ), 403);
        }

        if ($ticket === '') {
            CLP_DB::log('verify', '', 'refused', 'ticket-manquant');
            wp_send_json(array(
                'success' => false,
                'reason' => 'ticket-manquant',
                'message' => 'Merci de saisir votre numéro de ticket.',
            ), 400);
        }

        $row = CLP_DB::get_ticket($ticket);
        if (is_array($row) && $row['status'] === 'deposited') {
            CLP_DB::log('verify', $ticket, 'success', 'deja-depose', (string) $row['subcategory']);
            wp_send_json(array(
                'success' => true,
                'valid' => true,
                'already_deposited' => true,
                'subcategory' => (string) $row['subcategory'],
                'email_found' => !empty($row['payer_email']),
            ));
        }

        if (is_array($row) && $row['status'] === 'pending') {
            CLP_DB::log('verify', $ticket, 'success', 'correspondance-locale');
            wp_send_json(array(
                'success' => true,
                'valid' => true,
                'already_deposited' => false,
                'subcategory' => '',
                'email_found' => !empty($row['payer_email']),
            ));
        }

        $verification = CLP_HelloAsso::verify_ticket($ticket);
        if (!$verification['valid']) {
            CLP_DB::log('verify', $ticket, 'refused', $verification['reason']);
            wp_send_json(array(
                'success' => false,
                'reason' => $verification['reason'],
                'message' => self::verify_message($verification['reason']),
            ), 403);
        }

        CLP_DB::upsert_pending($ticket, $verification['order_id'], $verification['email']);
        CLP_DB::log('verify', $ticket, 'success', 'api', 'commande ' . $verification['order_id']);
        wp_send_json(array(
            'success' => true,
            'valid' => true,
            'already_deposited' => false,
            'subcategory' => '',
            'email_found' => $verification['email'] !== '',
        ));
    }

    public static function handle_deposit(): void
    {
        check_ajax_referer('clp_deposit', 'nonce');
        list($payload, $status) = self::process_deposit();
        wp_send_json($payload, $status);
    }

    private static function process_deposit(): array
    {
        $ticket = self::ticket_input();

        $window = CLP_Window::status();
        if ($window['status'] !== 'open') {
            CLP_DB::log('deposit', $ticket, 'refused', 'hors-periode', $window['status']);
            return array(array(
                'success' => false,
                'reason' => 'hors-periode',
                'message' => self::window_message($window),
            ), 403);
        }

        if ($ticket === '') {
            CLP_DB::log('deposit', '', 'refused', 'ticket-manquant');
            return array(array(
                'success' => false,
                'reason' => 'ticket-manquant',
                'message' => 'Merci de saisir votre numéro de ticket.',
            ), 400);
        }

        $raw_sub = isset($_POST['subcategory']) ? wp_unslash($_POST['subcategory']) : '';
        $subcategory = is_string($raw_sub) ? sanitize_key($raw_sub) : '';
        $subs = CLP_Settings::subcategories();
        if (!isset($subs[$subcategory])) {
            CLP_DB::log('deposit', $ticket, 'refused', 'categorie-invalide', $subcategory);
            return array(array(
                'success' => false,
                'reason' => 'categorie-invalide',
                'message' => 'Type de contribution invalide.',
            ), 400);
        }
        $type = $subs[$subcategory]['type'];

        if (empty($_FILES['file']) || !is_array($_FILES['file'])) {
            CLP_DB::log('deposit', $ticket, 'refused', 'fichier-manquant');
            return array(array(
                'success' => false,
                'reason' => 'fichier-manquant',
                'message' => 'Aucun fichier reçu.',
            ), 400);
        }
        $file = $_FILES['file'];
        if (isset($file['error']) && is_array($file['error'])) {
            CLP_DB::log('deposit', $ticket, 'refused', 'fichier-multiple');
            return array(array(
                'success' => false,
                'reason' => 'fichier-multiple',
                'message' => 'Un seul fichier par dépôt.',
            ), 400);
        }

        $settings = CLP_Settings::get();
        $max_mb = max(1, (int) $settings['max_file_mb']);
        $max_bytes = $max_mb * 1048576;

        $error_code = isset($file['error']) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;
        if ($error_code === UPLOAD_ERR_NO_FILE) {
            CLP_DB::log('deposit', $ticket, 'refused', 'fichier-manquant');
            return array(array(
                'success' => false,
                'reason' => 'fichier-manquant',
                'message' => 'Aucun fichier reçu.',
            ), 400);
        }
        if ($error_code === UPLOAD_ERR_INI_SIZE || $error_code === UPLOAD_ERR_FORM_SIZE) {
            CLP_DB::log('deposit', $ticket, 'refused', 'fichier-trop-lourd', 'limite PHP ' . $error_code);
            return array(array(
                'success' => false,
                'reason' => 'fichier-trop-lourd',
                'message' => sprintf('Fichier trop lourd : la limite est de %d Mo.', $max_mb),
            ), 413);
        }
        if ($error_code !== UPLOAD_ERR_OK) {
            CLP_DB::log('deposit', $ticket, 'refused', 'upload-erreur', 'code ' . $error_code);
            return array(array(
                'success' => false,
                'reason' => 'upload-erreur',
                'message' => "Erreur lors du téléversement (code {$error_code}). Réessayez.",
            ), 400);
        }

        $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        if (!in_array($ext, CLP_Settings::allowed_extensions($type), true)) {
            CLP_DB::log('deposit', $ticket, 'refused', 'format-non-accepte', $ext);
            return array(array(
                'success' => false,
                'reason' => 'format-non-accepte',
                'message' => sprintf('Format non accepté. Formats acceptés : %s.', implode(', ', CLP_Settings::allowed_extensions($type))),
            ), 415);
        }

        if ((int) ($file['size'] ?? 0) > $max_bytes) {
            CLP_DB::log('deposit', $ticket, 'refused', 'fichier-trop-lourd', (string) ($file['size'] ?? 0));
            return array(array(
                'success' => false,
                'reason' => 'fichier-trop-lourd',
                'message' => sprintf('Fichier trop lourd : la limite est de %d Mo.', $max_mb),
            ), 413);
        }

        $lock_key = 'clp_lock_' . md5($ticket);
        if (get_transient($lock_key) !== false) {
            CLP_DB::log('deposit', $ticket, 'refused', 'double-soumission');
            return array(array(
                'success' => false,
                'reason' => 'double-soumission',
                'message' => "Un dépôt pour ce numéro vient d'être enregistré : cette soumission est refusée.",
            ), 409);
        }
        set_transient($lock_key, wp_generate_uuid4(), 90);

        $temp_file = '';
        try {
            $row = CLP_DB::get_ticket($ticket);
            $replacement = is_array($row) && $row['status'] === 'deposited';

            if ($replacement && (string) $row['subcategory'] !== $subcategory) {
                CLP_DB::log('deposit', $ticket, 'refused', 'seconde-contribution', $subcategory);
                return array(array(
                    'success' => false,
                    'reason' => 'seconde-contribution',
                    'message' => "Ce numéro de ticket a déjà une contribution déposée : seul le remplacement du même type de contribution est autorisé.",
                ), 409);
            }

            if (!$replacement) {
                if (!is_array($row)) {
                    $verification = CLP_HelloAsso::verify_ticket($ticket);
                    if (!$verification['valid']) {
                        CLP_DB::log('deposit', $ticket, 'refused', $verification['reason']);
                        return array(array(
                            'success' => false,
                            'reason' => $verification['reason'],
                            'message' => self::verify_message($verification['reason']),
                        ), 403);
                    }
                    CLP_DB::upsert_pending($ticket, $verification['order_id'], $verification['email']);
                    $row = CLP_DB::get_ticket($ticket);
                } else {
                    CLP_DB::log('deposit', $ticket, 'success', 'correspondance-locale', 'étape de dépôt');
                }
            }

            $email = '';
            if (is_array($row) && !empty($row['payer_email'])) {
                $email = (string) $row['payer_email'];
            }
            $raw_email = isset($_POST['email']) ? wp_unslash($_POST['email']) : '';
            $email_fallback = is_string($raw_email) ? sanitize_email($raw_email) : '';
            if ($email === '' && $email_fallback !== '') {
                $email = $email_fallback;
            }

            require_once ABSPATH . 'wp-admin/includes/file.php';
            $move = wp_handle_upload($file, array(
                'test_form' => false,
                'test_type' => true,
                'mimes' => CLP_Settings::mimes($type),
            ));
            if (!is_array($move) || empty($move['file'])) {
                $err = is_array($move) && isset($move['error']) ? (string) $move['error'] : 'wp_handle_upload';
                CLP_DB::log('deposit', $ticket, 'refused', 'enregistrement-local', $err);
                return array(array(
                    'success' => false,
                    'reason' => 'enregistrement-local',
                    'message' => "Échec de l'enregistrement du fichier sur le serveur. Réessayez.",
                ), 500);
            }
            $temp_file = (string) $move['file'];

            $target = CLP_Dropbox::target_path($subcategory, $ext, $ticket);
            $upload = CLP_Dropbox::upload($temp_file, $target);
            if (!$upload['success']) {
                CLP_DB::log('deposit', $ticket, 'refused', 'dropbox-indisponible', (string) $upload['error']);
                return array(array(
                    'success' => false,
                    'reason' => 'dropbox-indisponible',
                    'message' => "Le service de dépôt est indisponible pour le moment. Réessayez plus tard : votre ticket n'a pas été consommé.",
                ), 502);
            }

            if ($replacement && is_array($row) && !empty($row['dropbox_path']) && (string) $row['dropbox_path'] !== $target) {
                CLP_Dropbox::delete((string) $row['dropbox_path']);
            }

            CLP_DB::mark_deposited($ticket, $subcategory, $target, $email);
            CLP_DB::log('deposit', $ticket, 'success', 'depot', $target);

            if ($settings['email_enabled'] === '1') {
                if ($email !== '') {
                    self::send_confirmation($email);
                } else {
                    CLP_DB::log('deposit', $ticket, 'success', 'email-introuvable', "message de réussite affiché à l'écran");
                }
            }

            return array(array(
                'success' => true,
                'message' => 'Votre contribution a bien été déposée. Merci !',
            ), 200);
        } catch (Throwable $e) {
            CLP_DB::log('deposit', $ticket, 'refused', 'erreur-interne', $e->getMessage());
            return array(array(
                'success' => false,
                'reason' => 'erreur-interne',
                'message' => 'Une erreur est survenue. Réessayez plus tard.',
            ), 500);
        } finally {
            if ($temp_file !== '' && file_exists($temp_file)) {
                wp_delete_file($temp_file);
            }
            delete_transient($lock_key);
        }
    }

    private static function send_confirmation(string $to): void
    {
        wp_mail(
            $to,
            'Votre contribution a bien été déposée',
            "Bonjour,\n\nVotre contribution a bien été déposée. Merci !\n\nL'équipe de la revue"
        );
    }

    private static function ticket_input(): string
    {
        $raw_input = isset($_POST['ticket']) ? wp_unslash($_POST['ticket']) : '';
        $raw = is_string($raw_input) ? sanitize_text_field($raw_input) : '';
        $clean = preg_replace('/[^A-Za-z0-9_-]/', '', $raw);
        return is_string($clean) ? substr($clean, 0, 64) : '';
    }

    private static function window_message(array $window): string
    {
        if ($window['status'] === 'before') {
            return sprintf('Le dépôt n\'est pas encore ouvert. Ouverture le %s (heure de Paris).', CLP_Window::format_label($window['open']));
        }
        if ($window['status'] === 'closed') {
            return sprintf('La période de dépôt est close depuis le %s (heure de Paris).', CLP_Window::format_label($window['close']));
        }
        return 'La période de dépôt n\'est pas configurée. Contactez l\'équipe de la revue.';
    }

    private static function verify_message(string $reason): string
    {
        $messages = array(
            'configuration-incomplete' => "La vérification des tickets n'est pas configurée. Contactez l'équipe de la revue.",
            'auth-failed' => 'Impossible de vérifier votre ticket pour le moment. Réessayez plus tard.',
            'network-error' => 'Impossible de joindre HelloAsso. Réessayez plus tard.',
            'not-found' => "Numéro de ticket invalide. Vérifiez votre saisie ; si vous n'êtes pas encore inscrit à l'appel, inscrivez-vous via la billetterie HelloAsso.",
        );
        if (strncmp($reason, 'api-error-', 10) === 0) {
            return 'Impossible de vérifier votre ticket pour le moment. Réessayez plus tard.';
        }
        return isset($messages[$reason]) ? $messages[$reason] : 'Numéro de ticket invalide.';
    }
}
