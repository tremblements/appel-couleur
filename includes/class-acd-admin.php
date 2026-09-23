<?php
/**
 * Page de réglages : identifiants HelloAsso, Dropbox, options de vérification.
 */

if (!defined('ABSPATH')) {
    exit;
}

class ACD_Admin
{
    const SLUG = 'acd-settings';

    public static function init()
    {
        add_action('admin_menu', array(__CLASS__, 'add_menu'));
        add_action('admin_init', array(__CLASS__, 'register_settings'));
    }

    public static function add_menu()
    {
        add_menu_page(
            'Appel Couleur',
            'Appel Couleur',
            'manage_options',
            self::SLUG,
            array(__CLASS__, 'render_page'),
            'dashicons-upload',
            58
        );
    }

    public static function register_settings()
    {
        register_setting('acd_settings', 'acd_helloasso_client_id', array('sanitize_callback' => 'sanitize_text_field'));
        register_setting('acd_settings', 'acd_helloasso_client_secret', array('sanitize_callback' => 'acd_sanitize_secret'));
        register_setting('acd_settings', 'acd_helloasso_organization_slug', array('sanitize_callback' => 'sanitize_text_field'));
        register_setting('acd_settings', 'acd_helloasso_sandbox', array('sanitize_callback' => 'acd_sanitize_checkbox'));
        register_setting('acd_settings', 'acd_dropbox_access_token', array('sanitize_callback' => 'acd_sanitize_secret'));
        register_setting('acd_settings', 'acd_verify_tickets', array('sanitize_callback' => 'acd_sanitize_checkbox'));
        register_setting('acd_settings', 'acd_allow_public', array('sanitize_callback' => 'acd_sanitize_checkbox'));
    }

    public static function render_page()
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Appel Couleur — Dépôts', 'appel-couleur-depots'); ?></h1>
            <p>
                <?php
                printf(
                    esc_html__('Comptes actuels — Nouvelles : %1$s, Poèmes : %2$s, Photos : %3$s', 'appel-couleur-depots'),
                    esc_html(ACD_Submissions::count('nouvelle')),
                    esc_html(ACD_Submissions::count('poeme')),
                    esc_html(ACD_Submissions::count('photo'))
                );
                ?>
            </p>
            <form method="post" action="options.php">
                <?php
                settings_fields('acd_settings');
                do_settings_sections(self::SLUG);
                ?>
                <h2>HelloAsso</h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="acd-client-id"><?php esc_html_e('Client ID', 'appel-couleur-depots'); ?></label></th>
                        <td><input type="text" id="acd-client-id" class="regular-text" name="acd_helloasso_client_id" value="<?php echo esc_attr(acd_get_option('helloasso_client_id')); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="acd-client-secret"><?php esc_html_e('Client Secret', 'appel-couleur-depots'); ?></label></th>
                        <td><input type="password" id="acd-client-secret" class="regular-text" name="acd_helloasso_client_secret" value="<?php echo esc_attr(acd_get_option('helloasso_client_secret')); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="acd-org-slug"><?php esc_html_e('Slug de l\'organisation', 'appel-couleur-depots'); ?></label></th>
                        <td><input type="text" id="acd-org-slug" class="regular-text" name="acd_helloasso_organization_slug" value="<?php echo esc_attr(acd_get_option('helloasso_organization_slug')); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Mode sandbox', 'appel-couleur-depots'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="acd_helloasso_sandbox" value="1" <?php checked(acd_get_option('helloasso_sandbox'), 1); ?>>
                                <?php esc_html_e('Utiliser l\'environnement sandbox HelloAsso (tests)', 'appel-couleur-depots'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Vérification des tickets', 'appel-couleur-depots'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="acd_verify_tickets" value="1" <?php checked(acd_get_option('verify_tickets'), 1); ?>>
                                <?php esc_html_e('Vérifier chaque numéro de ticket via l\'API HelloAsso avant d\'accepter le dépôt', 'appel-couleur-depots'); ?>
                            </label>
                        </td>
                    </tr>
                </table>

                <h2>Dropbox</h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="acd-dropbox-token"><?php esc_html_e('Access token', 'appel-couleur-depots'); ?></label></th>
                        <td><input type="password" id="acd-dropbox-token" class="regular-text" name="acd_dropbox_access_token" value="<?php echo esc_attr(acd_get_option('dropbox_access_token')); ?>"></td>
                    </tr>
                </table>

                <h2><?php esc_html_e('Accès au formulaire', 'appel-couleur-depots'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e('Dépôt public', 'appel-couleur-depots'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="acd_allow_public" value="1" <?php checked(acd_get_option('allow_public'), 1); ?>>
                                <?php esc_html_e('Autoriser les visiteurs non connectés à déposer (le ticket HelloAsso sert alors d\'identification)', 'appel-couleur-depots'); ?>
                            </label>
                        </td>
                    </tr>
                </table>

                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
}

function acd_get_option($key)
{
    $defaults = array(
        'helloasso_client_id' => '',
        'helloasso_client_secret' => '',
        'helloasso_organization_slug' => '',
        'helloasso_sandbox' => 0,
        'dropbox_access_token' => '',
        'verify_tickets' => 1,
        'allow_public' => 0,
    );

    $value = get_option('acd_' . $key, isset($defaults[$key]) ? $defaults[$key] : null);
    return $value;
}

function acd_sanitize_checkbox($value)
{
    return $value ? 1 : 0;
}

function acd_sanitize_secret($value)
{
    return trim(sanitize_text_field((string) $value));
}
