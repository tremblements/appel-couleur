<?php
if (!defined('ABSPATH')) {
    exit;
}

class DAC_Settings
{
    const OPTION_KEY = 'dac_settings';
    const SETTINGS_GROUP = 'dac_settings_group';
    const SETTINGS_PAGE = 'depot-appel-couleur';

    public static function init(): void
    {
        add_action('admin_menu', array(__CLASS__, 'add_menu'));
        add_action('admin_init', array(__CLASS__, 'register_settings'));
    }

    public static function defaults(): array
    {
        return array(
            'helloasso_api_url' => 'https://api.helloasso.com/v5',
            'helloasso_client_id' => '',
            'helloasso_client_secret' => '',
            'helloasso_org_slug' => '',
            'helloasso_form_type' => 'Event',
            'helloasso_form_slug' => '',
            'helloasso_ticket_field' => 'item_id',
            'dropbox_access_token' => '',
            'dropbox_root' => '/depot-appel-couleur',
            'folder_nouvelles' => 'nouvelles',
            'folder_poemes' => 'poemes',
            'folder_photos' => 'photos',
            'max_file_mb' => 20,
        );
    }

    public static function get(): array
    {
        $saved = get_option(self::OPTION_KEY, array());
        if (!is_array($saved)) {
            $saved = array();
        }
        return array_merge(self::defaults(), $saved);
    }

    public static function add_menu(): void
    {
        add_options_page(
            'Dépôt Appel à Couleur',
            'Dépôt Appel à Couleur',
            'manage_options',
            self::SETTINGS_PAGE,
            array(__CLASS__, 'render_page')
        );
    }

    public static function register_settings(): void
    {
        register_setting(self::SETTINGS_GROUP, self::OPTION_KEY, array(
            'type' => 'array',
            'sanitize_callback' => array(__CLASS__, 'sanitize'),
        ));
    }

    public static function sanitize($input): array
    {
        $clean = self::defaults();
        if (!is_array($input)) {
            return $clean;
        }
        foreach ($clean as $key => $default) {
            if (!array_key_exists($key, $input)) {
                continue;
            }
            $value = $input[$key];
            if (in_array($key, array('max_file_mb'), true)) {
                $clean[$key] = max(1, (int) $value);
            } else {
                $clean[$key] = sanitize_text_field((string) $value);
            }
        }
        $clean['dropbox_root'] = '/' . trim($clean['dropbox_root'], '/');
        return $clean;
    }

    public static function render_page(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Accès refusé.');
        }
        $settings = self::get();
        ?>
        <div class="wrap">
            <h1>Dépôt Appel à Couleur — Paramètres</h1>
            <form method="post" action="options.php">
                <?php settings_fields(self::SETTINGS_GROUP); ?>
                <h2>HelloAsso</h2>
                <table class="form-table">
                    <tr><th>Client ID</th><td><input type="text" class="regular-text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[helloasso_client_id]" value="<?php echo esc_attr($settings['helloasso_client_id']); ?>"></td></tr>
                    <tr><th>Client Secret</th><td><input type="password" class="regular-text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[helloasso_client_secret]" value="<?php echo esc_attr($settings['helloasso_client_secret']); ?>"></td></tr>
                    <tr><th>Slug organisation</th><td><input type="text" class="regular-text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[helloasso_org_slug]" value="<?php echo esc_attr($settings['helloasso_org_slug']); ?>"></td></tr>
                    <tr><th>Type de formulaire</th><td>
                        <select name="<?php echo esc_attr(self::OPTION_KEY); ?>[helloasso_form_type]">
                            <?php foreach (array('Event', 'Donation', 'Membership', 'PaymentForm', 'Shop', 'CrowdFunding', 'Checkout') as $type) : ?>
                                <option value="<?php echo esc_attr($type); ?>" <?php selected($settings['helloasso_form_type'], $type); ?>><?php echo esc_html($type); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td></tr>
                    <tr><th>Slug de la campagne (formulaire)</th><td><input type="text" class="regular-text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[helloasso_form_slug]" value="<?php echo esc_attr($settings['helloasso_form_slug']); ?>"></td></tr>
                    <tr><th>Champ de vérification du ticket</th><td>
                        <select name="<?php echo esc_attr(self::OPTION_KEY); ?>[helloasso_ticket_field]">
                            <option value="item_id" <?php selected($settings['helloasso_ticket_field'], 'item_id'); ?>>Numéro d'article acheté (ID item)</option>
                            <option value="order_id" <?php selected($settings['helloasso_ticket_field'], 'order_id'); ?>>Numéro de commande</option>
                            <option value="custom_field" <?php selected($settings['helloasso_ticket_field'], 'custom_field'); ?>>Champ personnalisé de la campagne</option>
                        </select>
                    </td></tr>
                </table>
                <h2>Dropbox</h2>
                <table class="form-table">
                    <tr><th>Access Token (app)</th><td><input type="password" class="regular-text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[dropbox_access_token]" value="<?php echo esc_attr($settings['dropbox_access_token']); ?>"></td></tr>
                    <tr><th>Dossier racine Dropbox</th><td><input type="text" class="regular-text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[dropbox_root]" value="<?php echo esc_attr($settings['dropbox_root']); ?>"></td></tr>
                    <tr><th>Dossier nouvelles</th><td><input type="text" class="regular-text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[folder_nouvelles]" value="<?php echo esc_attr($settings['folder_nouvelles']); ?>"></td></tr>
                    <tr><th>Dossier poèmes</th><td><input type="text" class="regular-text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[folder_poemes]" value="<?php echo esc_attr($settings['folder_poemes']); ?>"></td></tr>
                    <tr><th>Dossier photos</th><td><input type="text" class="regular-text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[folder_photos]" value="<?php echo esc_attr($settings['folder_photos']); ?>"></td></tr>
                    <tr><th>Taille max par fichier (Mo)</th><td><input type="number" min="1" name="<?php echo esc_attr(self::OPTION_KEY); ?>[max_file_mb]" value="<?php echo esc_attr($settings['max_file_mb']); ?>"></td></tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
}
