<?php
/**
 * Shortcode [appel_couleur_depots] : zone de dépôt + compteurs.
 *
 * Attributs :
 *  - types="nouvelle,poeme,photo"  catégories proposées (toutes par défaut)
 *  - show_counters="yes"            afficher les compteurs
 */

if (!defined('ABSPATH')) {
    exit;
}

class ACD_Shortcode
{
    const NONCE_ACTION = 'acd_submit';
    const NONCE_FIELD = 'acd_nonce';

    public static function init()
    {
        add_shortcode('appel_couleur_depots', array(__CLASS__, 'render'));
        add_action('wp_enqueue_scripts', array(__CLASS__, 'register_assets'));
    }

    public static function register_assets()
    {
        wp_register_style(
            'acd-upload',
            plugins_url('assets/css/acd-upload.css', dirname(__FILE__)),
            array(),
            ACD_VERSION
        );
    }

    /**
     * Types autorisés et libellés affichés.
     */
    public static function types()
    {
        return array(
            'nouvelle' => array(
                'label' => 'Une nouvelle',
                'folder' => 'nouvelles',
                'mimes' => array('docx', 'odt'),
            ),
            'poeme' => array(
                'label' => 'Un poème',
                'folder' => 'poemes',
                'mimes' => array('docx', 'odt'),
            ),
            'photo' => array(
                'label' => 'Une photographie',
                'folder' => 'photos',
                'mimes' => array('jpg', 'jpeg', 'tif', 'tiff'),
            ),
        );
    }

    private static function allowed_mime_list($type_key)
    {
        $types = self::types();
        return isset($types[$type_key]) ? $types[$type_key]['mimes'] : array();
    }

    /**
     * Rendu du shortcode.
     */
    public static function render($atts)
    {
        if (!is_user_logged_in() && !acd_get_option('allow_public')) {
            return '<div class="acd-notice acd-error"><p>'
                . esc_html__('Cette zone de dépôt est réservée aux personnes inscrites. Connectez-vous.', 'appel-couleur-depots')
                . '</p></div>';
        }

        wp_enqueue_style('acd-upload');

        $atts = shortcode_atts(array(
            'types' => 'nouvelle,poeme,photo',
            'show_counters' => 'yes',
        ), $atts, 'appel_couleur_depots');

        $available = array_keys(self::types());
        $selected = array_filter(array_map('trim', explode(',', (string) $atts['types'])));
        $selected = array_values(array_intersect($available, $selected));
        if (empty($selected)) {
            $selected = $available;
        }

        $message = null;
        $message_type = 'ok';
        if ($_SERVER['REQUEST_METHOD'] === 'POST'
            && isset($_POST['acd_action'])
            && 'submit' === $_POST['acd_action']) {
            $result = self::handle_submission($selected);
            if (is_wp_error($result)) {
                $message = $result->get_error_message();
                $message_type = 'error';
            } else {
                $message = $result;
                $message_type = 'ok';
            }
        }

        ob_start();
        self::render_counters($atts['show_counters'] === 'yes');
        self::render_form($selected, $message, $message_type);
        return ob_get_clean();
    }

    private static function render_counters($show)
    {
        if (!$show) {
            return;
        }
        $types = self::types();
        ?>
        <div class="acd-counters">
            <?php foreach ($types as $key => $type) : ?>
                <div class="acd-counter">
                    <span class="acd-counter-number" data-acd-type="<?php echo esc_attr($key); ?>">
                        <?php echo esc_html(ACD_Submissions::count($key)); ?>
                    </span>
                    <span class="acd-counter-label">
                        <?php echo esc_html($type['label']); ?> <?php esc_html_e('déposé', 'appel-couleur-depots'); ?>
                    </span>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
    }

    private static function render_form($selected, $message, $message_type)
    {
        $types = self::types();
        ?>
        <?php if (null !== $message) : ?>
            <div class="acd-notice acd-<?php echo esc_attr($message_type); ?>">
                <p><?php echo esc_html($message); ?></p>
            </div>
        <?php endif; ?>

        <form class="acd-form" method="post" enctype="multipart/form-data">
            <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD); ?>
            <input type="hidden" name="acd_action" value="submit">

            <label for="acd-ticket"><?php esc_html_e('Votre numéro de ticket HelloAsso', 'appel-couleur-depots'); ?></label>
            <input type="text" id="acd-ticket" name="acd_ticket" required
                   value="<?php echo esc_attr(isset($_POST['acd_ticket']) ? sanitize_text_field(wp_unslash($_POST['acd_ticket'])) : ''); ?>">

            <label for="acd-category"><?php esc_html_e('Que déposez-vous ?', 'appel-couleur-depots'); ?></label>
            <select id="acd-category" name="acd_category">
                <?php foreach ($selected as $key) : ?>
                    <option value="<?php echo esc_attr($key); ?>">
                        <?php echo esc_html($types[$key]['label']); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label for="acd-file"><?php esc_html_e('Votre fichier', 'appel-couleur-depots'); ?></label>
            <input type="file" id="acd-file" name="acd_file" required
                   accept="<?php echo esc_attr(self::accept_attr($selected)); ?>">

            <button type="submit"><?php esc_html_e('Déposer', 'appel-couleur-depots'); ?></button>
        </form>
        <?php
    }

    private static function accept_attr($selected)
    {
        $exts = array();
        foreach ($selected as $key) {
            foreach (self::allowed_mime_list($key) as $ext) {
                $exts['.' . $ext] = true;
            }
        }
        return implode(',', array_keys($exts));
    }

    /**
     * Traitement du dépôt : vérification HelloAsso, puis envoi Dropbox,
     * puis enregistrement du dépôt pour les compteurs.
     *
     * @return string|WP_Error
     */
    private static function handle_submission($allowed_types)
    {
        if (!isset($_POST[self::NONCE_FIELD])
            || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST[self::NONCE_FIELD])), self::NONCE_ACTION)) {
            return new WP_Error('acd_invalid_nonce', 'Session expirée, merci de réessayer.');
        }

        $ticket = isset($_POST['acd_ticket']) ? sanitize_text_field(wp_unslash($_POST['acd_ticket'])) : '';
        $category = isset($_POST['acd_category']) ? sanitize_key(wp_unslash($_POST['acd_category'])) : '';

        if ('' === $ticket) {
            return new WP_Error('acd_missing_ticket', 'Merci d\'indiquer votre numéro de ticket.');
        }
        if (!in_array($category, $allowed_types, true)) {
            return new WP_Error('acd_invalid_category', 'Catégorie de dépôt invalide.');
        }

        if (empty($_FILES['acd_file']) || !is_uploaded_file($_FILES['acd_file']['tmp_name'])) {
            return new WP_Error('acd_missing_file', 'Merci de choisir un fichier à déposer.');
        }

        $file = $_FILES['acd_file'];
        if (!empty($file['error'])) {
            return new WP_Error('acd_upload_error', 'Erreur pendant l\'envoi du fichier (code ' . (int) $file['error'] . ').');
        }
        if ((int) $file['size'] <= 0 || (int) $file['size'] > ACD_MAX_FILE_SIZE) {
            return new WP_Error('acd_file_too_large', 'Le fichier dépasse la taille autorisée (20 Mo).');
        }

        $original_name = sanitize_file_name($file['name']);
        $ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
        $allowed_exts = self::allowed_mime_list($category);
        if (!in_array($ext, $allowed_exts, true)) {
            return new WP_Error(
                'acd_bad_extension',
                'Format non autorisé pour cette catégorie. Formats acceptés : ' . implode(', ', $allowed_exts) . '.'
            );
        }

        $types = self::types();
        $folder = $types[$category]['folder'];

        $helloasso = ACD_HelloAsso::from_options();
        if ($helloasso->is_configured() && acd_get_option('verify_tickets')) {
            $verified = $helloasso->verify_ticket($ticket);
            if (is_wp_error($verified)) {
                return $verified;
            }
        }

        $contents = file_get_contents($file['tmp_name']);
        if (false === $contents) {
            return new WP_Error('acd_read_failed', 'Impossible de lire le fichier déposé.');
        }

        $final_name = self::build_filename($category, $ticket, $original_name);
        $dropbox = ACD_Dropbox::from_options();
        $uploaded = $dropbox->upload($folder, $final_name, $contents);
        if (is_wp_error($uploaded)) {
            return $uploaded;
        }

        ACD_Submissions::insert($category, $ticket, $original_name, '/' . $folder . '/' . $final_name);

        return 'Merci ! Votre dépôt a bien été enregistré.';
    }

    /**
     * Nom de fichier unique pour éviter les collisions dans Dropbox :
     * <ticket>-<catégorie>-<horodatage>.<extension>
     */
    private static function build_filename($category, $ticket, $original_name)
    {
        $ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
        $slug = sanitize_key($ticket);
        return $slug . '-' . $category . '-' . gmdate('Ymd-His') . '.' . $ext;
    }
}
