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
        add_action('wp_ajax_clp_check_deposit', array(__CLASS__, 'handle_check_deposit'));
        add_action('wp_ajax_nopriv_clp_check_deposit', array(__CLASS__, 'handle_check_deposit'));
    }

    public static function handle_check_deposit(): void
    {
        $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
        if (!wp_verify_nonce($nonce, 'clp_check_deposit')) {
            CLP_DB::log('check', '', 'refused', 'nonce-invalide', 'session expirée : recharger la page');
            wp_send_json(array('success' => false, 'message' => 'Session expirée : rechargez la page (Ctrl+F5) puis réessayez.'), 403);
        }

        $raw = isset($_POST['email']) ? wp_unslash($_POST['email']) : '';
        $email = is_string($raw) ? sanitize_email($raw) : '';
        if ($email === '') {
            wp_send_json(array('success' => false, 'message' => 'Merci de saisir votre adresse e-mail.'), 400);
        }

        try {
            $throttle_key = 'clp_check_' . md5(strtolower($email));
            if (get_transient($throttle_key) !== false) {
                wp_send_json(array('success' => false, 'message' => "Un e-mail vient déjà d'être envoyé il y a moins d'une minute. Réessayez dans un instant."), 429);
            }
            set_transient($throttle_key, '1', 60);

            $candidates = array();
            if (in_array($email, self::test_identifiers(), true)) {
                $candidates = array(array('ticket' => 'test' . substr(md5(strtolower($email)), 0, 10), 'order_id' => 'TEST', 'email' => $email, 'name' => ''));
            } else {
                $candidates = CLP_HelloAsso::find_by_email($email);
            }

            $found = null;
            $found_name = '';
            foreach ($candidates as $candidate) {
                if (!is_array($candidate) || empty($candidate['ticket'])) {
                    continue;
                }
                $row = CLP_DB::get_ticket((string) $candidate['ticket']);
                if (is_array($row) && $row['status'] === 'deposited') {
                    $found = $row;
                    $found_name = isset($candidate['name']) ? (string) $candidate['name'] : '';
                    break;
                }
            }

            if ($found === null) {
                CLP_DB::log('check', $email, 'refused', 'aucun-depot', 'vérification de dépôt');
                wp_send_json(array(
                    'success' => false,
                    'message' => "Aucun dépôt trouvé pour cette adresse (billet non payé, ou aucun dépôt effectué avec cette adresse).",
                ), 404);
            }

            if ($found_name !== '' && empty($found['payer_name'])) {
                CLP_DB::set_payer_name((string) $found['ticket'], $found_name);
                $found['payer_name'] = $found_name;
            }
            $to = !empty($found['payer_email']) ? (string) $found['payer_email'] : $email;
            $sent = self::send_deposit_receipt($to, (string) ($found['payer_name'] ?? ''), $found);
            CLP_DB::log('check', (string) $found['ticket'], 'success', $sent ? 'mail-envoye' : 'echec-envoi', 'vérification de dépôt');
            wp_send_json(array(
                'success' => true,
                'message' => sprintf("Un e-mail vient de vous être envoyé à %s.", $to),
                'email_sent' => $sent,
            ));
        } catch (Throwable $e) {
            CLP_DB::log('check', $email, 'refused', 'erreur-interne', $e->getMessage());
            wp_send_json(array(
                'success' => false,
                'message' => 'Une erreur est survenue côté serveur. Réessayez plus tard (le détail est dans le journal admin).',
            ), 500);
        }
    }

    public static function handle_verify(): void
    {
        check_ajax_referer('clp_verify', 'nonce');
        $input = self::identifier_input();

        $window = CLP_Window::status();
        if ($window['status'] !== 'open') {
            CLP_DB::log('verify', $input, 'refused', 'hors-periode', $window['status']);
            wp_send_json(array(
                'success' => false,
                'reason' => 'hors-periode',
                'message' => self::window_message($window),
            ), 403);
        }

        if ($input === '') {
            CLP_DB::log('verify', '', 'refused', 'identifiant-manquant');
            wp_send_json(array(
                'success' => false,
                'reason' => 'identifiant-manquant',
                'message' => 'Merci de saisir votre numéro de ticket ou votre e-mail HelloAsso.',
            ), 400);
        }

        $resolved = self::resolve_identifier($input);
        if (!$resolved['ok']) {
            CLP_DB::log('verify', $input, 'refused', $resolved['reason']);
            wp_send_json(array(
                'success' => false,
                'reason' => $resolved['reason'],
                'message' => self::verify_message($resolved['reason']),
            ), 403);
        }
        $ticket = $resolved['ticket'];

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

        if (!is_array($row)) {
            CLP_DB::upsert_pending($ticket, $resolved['order_id'], $resolved['email'], isset($resolved['name']) ? (string) $resolved['name'] : '');
            CLP_DB::log('verify', $ticket, 'success', !empty($resolved['test']) ? 'test' : 'api', 'commande ' . $resolved['order_id']);
        } else {
            CLP_DB::log('verify', $ticket, 'success', 'correspondance-locale');
        }

        wp_send_json(array(
            'success' => true,
            'valid' => true,
            'already_deposited' => false,
            'subcategory' => '',
            'email_found' => $resolved['email'] !== '',
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
        $input = self::identifier_input();

        $window = CLP_Window::status();
        if ($window['status'] !== 'open') {
            CLP_DB::log('deposit', $input, 'refused', 'hors-periode', $window['status']);
            return array(array(
                'success' => false,
                'reason' => 'hors-periode',
                'message' => self::window_message($window),
            ), 403);
        }

        if ($input === '') {
            CLP_DB::log('deposit', '', 'refused', 'identifiant-manquant');
            return array(array(
                'success' => false,
                'reason' => 'identifiant-manquant',
                'message' => 'Merci de saisir votre numéro de ticket ou votre e-mail HelloAsso.',
            ), 400);
        }

        $raw_sub = isset($_POST['subcategory']) ? wp_unslash($_POST['subcategory']) : '';
        $subcategory = is_string($raw_sub) ? sanitize_key($raw_sub) : '';
        $subs = CLP_Settings::subcategories();
        if (!isset($subs[$subcategory])) {
            CLP_DB::log('deposit', $input, 'refused', 'categorie-invalide', $subcategory);
            return array(array(
                'success' => false,
                'reason' => 'categorie-invalide',
                'message' => 'Type de contribution invalide.',
            ), 400);
        }
        $type = $subs[$subcategory]['type'];

        if (empty($_FILES['file']) || !is_array($_FILES['file'])) {
            CLP_DB::log('deposit', $input, 'refused', 'fichier-manquant');
            return array(array(
                'success' => false,
                'reason' => 'fichier-manquant',
                'message' => 'Aucun fichier reçu.',
            ), 400);
        }
        $file = $_FILES['file'];
        if (isset($file['error']) && is_array($file['error'])) {
            CLP_DB::log('deposit', $input, 'refused', 'fichier-multiple');
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
            CLP_DB::log('deposit', $input, 'refused', 'fichier-manquant');
            return array(array(
                'success' => false,
                'reason' => 'fichier-manquant',
                'message' => 'Aucun fichier reçu.',
            ), 400);
        }
        if ($error_code === UPLOAD_ERR_INI_SIZE || $error_code === UPLOAD_ERR_FORM_SIZE) {
            CLP_DB::log('deposit', $input, 'refused', 'fichier-trop-lourd', 'limite PHP ' . $error_code);
            return array(array(
                'success' => false,
                'reason' => 'fichier-trop-lourd',
                'message' => sprintf('Fichier trop lourd : la limite est de %d Mo.', $max_mb),
            ), 413);
        }
        if ($error_code !== UPLOAD_ERR_OK) {
            CLP_DB::log('deposit', $input, 'refused', 'upload-erreur', 'code ' . $error_code);
            return array(array(
                'success' => false,
                'reason' => 'upload-erreur',
                'message' => "Erreur lors du téléversement (code {$error_code}). Réessayez.",
            ), 400);
        }

        $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        if (!in_array($ext, CLP_Settings::allowed_extensions($type), true)) {
            CLP_DB::log('deposit', $input, 'refused', 'format-non-accepte', $ext);
            return array(array(
                'success' => false,
                'reason' => 'format-non-accepte',
                'message' => sprintf('Format non accepté. Formats acceptés : %s.', implode(', ', CLP_Settings::allowed_extensions($type))),
            ), 415);
        }

        if ((int) ($file['size'] ?? 0) > $max_bytes) {
            CLP_DB::log('deposit', $input, 'refused', 'fichier-trop-lourd', (string) ($file['size'] ?? 0));
            return array(array(
                'success' => false,
                'reason' => 'fichier-trop-lourd',
                'message' => sprintf('Fichier trop lourd : la limite est de %d Mo.', $max_mb),
            ), 413);
        }

        $lock_key = 'clp_lock_' . md5($input);
        if (get_transient($lock_key) !== false) {
            CLP_DB::log('deposit', $input, 'refused', 'double-soumission');
            return array(array(
                'success' => false,
                'reason' => 'double-soumission',
                'message' => "Un dépôt pour cet identifiant vient d'être enregistré : cette soumission est refusée.",
            ), 409);
        }
        set_transient($lock_key, wp_generate_uuid4(), 90);

        $temp_file = '';
        try {
            $resolved = self::resolve_identifier($input);
            if (!$resolved['ok']) {
                CLP_DB::log('deposit', $input, 'refused', $resolved['reason']);
                return array(array(
                    'success' => false,
                    'reason' => $resolved['reason'],
                    'message' => self::verify_message($resolved['reason']),
                ), 403);
            }
            $ticket = $resolved['ticket'];

            $row = CLP_DB::get_ticket($ticket);
            $replacement = is_array($row) && $row['status'] === 'deposited';

            if ($replacement) {
                $raw_confirm = isset($_POST['confirm_replace']) ? wp_unslash($_POST['confirm_replace']) : '';
                if ($raw_confirm !== '1') {
                    CLP_DB::log('deposit', $ticket, 'refused', 'confirmation-remplacement-requise', $subcategory);
                    return array(array(
                        'success' => false,
                        'reason' => 'confirmation-remplacement-requise',
                        'message' => "Vous avez déjà déposé une contribution avec ce billet : cochez la case de confirmation pour effacer la précédente et la remplacer par ce nouveau fichier.",
                    ), 409);
                }
            }

            if (!$replacement) {
                if (!is_array($row)) {
                    CLP_DB::upsert_pending($ticket, $resolved['order_id'], $resolved['email'], isset($resolved['name']) ? (string) $resolved['name'] : '');
                    $row = CLP_DB::get_ticket($ticket);
                } else {
                    CLP_DB::log('deposit', $ticket, 'success', 'correspondance-locale', 'étape de dépôt');
                }
            }

            $human_code = '';
            if (is_array($row) && !empty($row['human_code'])) {
                $human_code = (string) $row['human_code'];
            }
            if ($human_code === '') {
                $human_code = CLP_Codes::pick();
            }

            $email = '';
            if (is_array($row) && !empty($row['payer_email'])) {
                $email = (string) $row['payer_email'];
            }
            if ($email === '' && $resolved['email'] !== '') {
                $email = $resolved['email'];
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
                CLP_DB::log('deposit', $input, 'refused', 'enregistrement-local', $err);
                return array(array(
                    'success' => false,
                    'reason' => 'enregistrement-local',
                    'message' => "Échec de l'enregistrement du fichier sur le serveur. Réessayez.",
                ), 500);
            }
            $temp_file = (string) $move['file'];

            $target = CLP_Dropbox::target_path($subcategory, $ext, $ticket, $human_code);
            $upload = CLP_Dropbox::upload($temp_file, $target);
            if (!$upload['success']) {
                CLP_DB::log('deposit', $input, 'refused', 'dropbox-indisponible', (string) $upload['error']);
                $msg = "Le service de dépôt est indisponible pour le moment. Réessayez plus tard : votre billet n'a pas été consommé.";
                if ((string) $upload['error'] === 'dropbox-token-expire') {
                    $msg = "Le jeton Dropbox a expiré ou est invalide : régénérez-le dans la Dropbox App Console (expiration « No expiration » si proposée) et collez-le dans Réglages → Contribalapelle. Votre billet n'a pas été consommé.";
                }
                return array(array(
                    'success' => false,
                    'reason' => 'dropbox-indisponible',
                    'message' => $msg,
                ), 502);
            }

            if ($replacement && is_array($row) && !empty($row['dropbox_path']) && (string) $row['dropbox_path'] !== $target) {
                CLP_Dropbox::delete((string) $row['dropbox_path']);
            }

            CLP_DB::mark_deposited($ticket, $subcategory, $target, $email, $human_code);
            CLP_DB::log('deposit', $ticket, 'success', 'depot', $target);

            $email_sent = false;
            if ($settings['email_enabled'] === '1') {
                if ($email !== '') {
                    $email_sent = self::send_confirmation(
                        $email,
                        is_array($row) ? (string) ($row['payer_name'] ?? '') : '',
                        (string) ($file['name'] ?? ''),
                        CLP_Settings::subcategory_label($subcategory),
                        basename($target),
                        $replacement
                    );
                } else {
                    CLP_DB::log('deposit', $ticket, 'success', 'email-introuvable', "message de réussite affiché à l'écran");
                }
            }

            return array(array(
                'success' => true,
                'message' => 'Votre contribution a bien été déposée. Merci !',
                'uploaded_name' => (string) ($file['name'] ?? ''),
                'subcategory_label' => CLP_Settings::subcategory_label($subcategory),
                'filename' => basename($target),
                'code' => $human_code,
                'size' => size_format(max(0, (int) ($file['size'] ?? 0))),
                'replaced' => $replacement,
                'email' => $email,
                'email_sent' => $email_sent,
            ), 200);
        } catch (Throwable $e) {
            CLP_DB::log('deposit', $input, 'refused', 'erreur-interne', $e->getMessage());
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

    private static function resolve_identifier(string $input): array
    {
        $result = array('ok' => false, 'reason' => '', 'ticket' => '', 'order_id' => '', 'email' => '');
        if ($input === '') {
            $result['reason'] = 'identifiant-manquant';
            return $result;
        }

        $cache_key = 'clp_res_' . md5(strtolower($input));
        $cached = get_transient($cache_key);
        if (is_array($cached) && !empty($cached['ok']) && !empty($cached['ticket'])) {
            return $cached;
        }

        $test_ids = self::test_identifiers();
        if ($test_ids !== array() && in_array($input, $test_ids, true)) {
            $is_email = is_email($input);
            $result = array(
                'ok' => true,
                'reason' => '',
                'ticket' => $is_email ? 'test' . substr(md5(strtolower($input)), 0, 10) : $input,
                'order_id' => 'TEST',
                'email' => $is_email ? sanitize_email($input) : '',
                'test' => true,
            );
            set_transient($cache_key, $result, 30 * MINUTE_IN_SECONDS);
            return $result;
        }

        if (is_email($input)) {
            $settings = CLP_Settings::get();
            if (($settings['allow_email'] ?? '') !== '1') {
                $result['reason'] = 'email-not-allowed';
                return $result;
            }
            $candidates = CLP_HelloAsso::find_by_email(sanitize_email($input));
            if ($candidates === array()) {
                $result['reason'] = 'not-found';
                return $result;
            }
            $chosen = $candidates[0];
            foreach ($candidates as $candidate) {
                if (!is_array($candidate) || empty($candidate['ticket'])) {
                    continue;
                }
                $row = CLP_DB::get_ticket((string) $candidate['ticket']);
                if (!is_array($row) || $row['status'] !== 'deposited') {
                    $chosen = $candidate;
                    break;
                }
            }
            $result = array(
                'ok' => true,
                'reason' => '',
                'ticket' => (string) $chosen['ticket'],
                'order_id' => isset($chosen['order_id']) ? (string) $chosen['order_id'] : '',
                'email' => sanitize_email($input),
                'name' => isset($chosen['name']) ? (string) $chosen['name'] : '',
            );
        } else {
            $verification = CLP_HelloAsso::verify_ticket($input);
            if (!$verification['valid']) {
                $result['reason'] = $verification['reason'];
                return $result;
            }
            $result = array(
                'ok' => true,
                'reason' => '',
                'ticket' => $input,
                'order_id' => $verification['order_id'],
                'email' => $verification['email'],
                'name' => isset($verification['name']) ? (string) $verification['name'] : '',
            );
        }

        set_transient($cache_key, $result, 30 * MINUTE_IN_SECONDS);
        return $result;
    }

    private static function test_identifiers(): array
    {
        $settings = CLP_Settings::get();
        $raw = isset($settings['test_ids']) ? (string) $settings['test_ids'] : '';
        $parts = preg_split('/[\s,;]+/', $raw);
        if (!is_array($parts)) {
            return array();
        }
        $out = array();
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part !== '') {
                $out[] = $part;
            }
        }
        return $out;
    }

    private static function identifier_input(): string
    {
        $raw_input = isset($_POST['ticket']) ? wp_unslash($_POST['ticket']) : '';
        if (!is_string($raw_input)) {
            return '';
        }
        $raw = sanitize_text_field($raw_input);
        if (is_email($raw)) {
            return sanitize_email($raw);
        }
        $clean = preg_replace('/[^A-Za-z0-9_-]/', '', $raw);
        return is_string($clean) ? substr($clean, 0, 64) : '';
    }

    private static function send_deposit_receipt(string $to, string $name, array $row): bool
    {
        $appel = CLP_Settings::appel_label();
        $hello = $name !== '' ? 'Bonjour ' . $name . ',' : 'Bonjour,';
        $lines = array(
            $hello,
            '',
            'Vous avez bien déposé une contribution pour l\'appel « ' . $appel . ' ».',
            '',
            'Nom anonymisé du fichier déposé : ' . basename((string) ($row['dropbox_path'] ?? '')),
            '',
            'Code d\'anonymisation qui vous a été attribué : ' . (string) ($row['human_code'] ?? ''),
            '',
            'Catégorie : ' . CLP_Settings::subcategory_label((string) ($row['subcategory'] ?? '')),
            '',
            'Date du dépôt : ' . (string) ($row['deposited_at'] ?? '') . ' (heure de Paris)',
            '',
            'L\'équipe de la revue Pourtant',
        );
        return (bool) wp_mail($to, 'Votre dépôt pour l\'appel « ' . $appel . ' » — confirmation et code d\'anonymisation', implode("\n", $lines));
    }

    private static function send_confirmation(string $to, string $name, string $uploaded_name, string $subcategory_label, string $anon_name, bool $replaced): bool
    {
        $appel = CLP_Settings::appel_label();
        $hello = $name !== '' ? 'Bonjour ' . $name . ',' : 'Bonjour,';
        $lines = array(
            $hello,
            '',
            'Votre contribution a bien été déposée. Merci !',
            '',
            'Document déposé : ' . $uploaded_name,
            '',
            'Nature de la contribution : ' . $subcategory_label,
            '',
            'Enregistré dans notre espace sous le nom anonymisé : ' . $anon_name,
        );
        if ($replaced) {
            $lines[] = '';
            $lines[] = 'Ce dépôt remplace votre contribution précédente, qui a été effacée.';
        }
        $lines[] = '';
        $lines[] = 'L\'équipe de la revue Pourtant';
        return (bool) wp_mail($to, 'Votre dépôt pour l\'appel « ' . $appel . ' »', implode("\n", $lines));
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
            'configuration-incomplete' => "La vérification des billets n'est pas configurée. Contactez l'équipe de la revue.",
            'auth-failed' => 'Impossible de vérifier votre identifiant pour le moment. Réessayez plus tard.',
            'network-error' => 'Impossible de joindre HelloAsso. Réessayez plus tard.',
            'email-not-allowed' => "L'identification par e-mail n'est pas activée : saisissez votre numéro de ticket.",
            'not-found' => "Numéro de ticket ou e-mail introuvable. Vérifiez votre saisie ; si vous n'êtes pas encore inscrit à l'appel, inscrivez-vous via la billetterie HelloAsso.",
        );
        if (strncmp($reason, 'api-error-', 10) === 0) {
            return 'Impossible de vérifier votre identifiant pour le moment. Réessayez plus tard.';
        }
        return isset($messages[$reason]) ? $messages[$reason] : 'Identifiant invalide.';
    }
}
