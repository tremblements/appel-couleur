<?php
if (!defined('ABSPATH')) {
    exit;
}

class CLP_Settings
{
    const OPTION_KEY = 'clp_settings';
    const SETTINGS_GROUP = 'clp_settings_group';
    const SETTINGS_PAGE = 'contribalapelle';

    public static function init(): void
    {
        add_action('admin_menu', array(__CLASS__, 'add_menu'));
        add_action('admin_init', array(__CLASS__, 'register_settings'));
        add_action('wp_ajax_clp_admin_test_ha', array(__CLASS__, 'test_helloasso'));
    }

    public static function test_helloasso(): void
    {
        check_ajax_referer('clp_admin_test_ha', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json(array('success' => false, 'message' => 'Accès refusé.'), 403);
        }
        $result = CLP_HelloAsso::count_authorized_orders();
        if (!$result['ok']) {
            $message = 'Échec de la connexion : ' . self::ha_reason_message($result['reason']);
            if ($result['reason'] === 'configuration-incomplete' && !empty($result['missing'])) {
                $message = 'Configuration incomplète — champs vides : ' . implode(', ', $result['missing'])
                    . '. Saisissez-les ci-dessus puis cliquez sur « Enregistrer les modifications » en bas de page avant de tester.';
            }
            wp_send_json(array('success' => false, 'message' => $message));
        }
        $s = self::get();
        wp_send_json(array(
            'success' => true,
            'message' => sprintf(
                'Billetterie trouvée : organisation « %s », formulaire %s « %s » — %d commande(s) au total, dont %d payée(s) (state = Authorized). Seules les commandes payées valident un dépôt.',
                $s['helloasso_org_slug'],
                $s['helloasso_form_type'],
                $s['helloasso_form_slug'],
                isset($result['total']) ? (int) $result['total'] : 0,
                $result['count']
            ),
        ));
    }

    private static function ha_reason_message(string $reason): string
    {
        $messages = array(
            'configuration-incomplete' => 'Client ID / Client Secret / slug d\'organisation / slug de billetterie incomplet.',
            'auth-failed' => 'authentification OAuth refusée (vérifiez Client ID / Client Secret).',
            'network-error' => 'réseau injoignable.',
        );
        if (strncmp($reason, 'api-error-', 10) === 0) {
            return 'l\'API renvoie ' . $reason . ' (vérifiez le slug d\'organisation, le type de formulaire et le slug de la billetterie).';
        }
        return isset($messages[$reason]) ? $messages[$reason] : $reason;
    }

    public static function defaults(): array
    {
        return array(
            'appel_name' => 'appel',
            'window_open' => '',
            'window_close' => '',
            'label_fiction_500' => 'Fiction — 500 mots',
            'label_fiction_250' => 'Fiction — 250 mots',
            'label_haiku' => 'Haïku (5-7-5)',
            'label_poeme_long' => 'Poème long (40 à 100 lignes)',
            'label_poeme_court' => 'Poème court',
            'label_image' => 'Image (photographie ou illustration)',
            'helloasso_api_url' => 'https://api.helloasso.com/v5',
            'helloasso_client_id' => '',
            'helloasso_client_secret' => '',
            'helloasso_org_slug' => '',
            'helloasso_form_type' => 'Event',
            'helloasso_form_slug' => '',
            'helloasso_ticket_field' => 'item_id',
            'allow_email' => '1',
            'dropbox_token' => '',
            'max_file_mb' => 25,
            'email_enabled' => '1',
            'privacy_notice' => "En déposant une contribution, vous acceptez que votre identifiant HelloAsso (numéro de ticket ou adresse e-mail utilisée pour l'achat), le type de contribution, la date de dépôt et votre adresse e-mail soient traités par l'association éditrice de la revue Pourtant, pour l'organisation de l'appel à contributions (intérêt légitime). Ces données sont conservées jusqu'à la publication du numéro, puis supprimées. L'inscription via la billetterie HelloAsso relève de la politique de confidentialité de HelloAsso.",
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

    public static function subcategories(): array
    {
        return array(
            'fiction_500' => array('type' => 'texte', 'folder' => 'fiction'),
            'fiction_250' => array('type' => 'texte', 'folder' => 'fiction'),
            'haiku' => array('type' => 'texte', 'folder' => 'poesie'),
            'poeme_long' => array('type' => 'texte', 'folder' => 'poesie'),
            'poeme_court' => array('type' => 'texte', 'folder' => 'poesie'),
            'image' => array('type' => 'image', 'folder' => 'image'),
        );
    }

    public static function label_keys(): array
    {
        return array(
            'fiction_500' => 'label_fiction_500',
            'fiction_250' => 'label_fiction_250',
            'haiku' => 'label_haiku',
            'poeme_long' => 'label_poeme_long',
            'poeme_court' => 'label_poeme_court',
            'image' => 'label_image',
        );
    }

    public static function subcategory_label(string $key): string
    {
        $settings = self::get();
        $map = self::label_keys();
        if (isset($map[$key]) && !empty($settings[$map[$key]])) {
            return (string) $settings[$map[$key]];
        }
        $defaults = self::defaults();
        return isset($map[$key]) ? (string) $defaults[$map[$key]] : $key;
    }

    public static function mimes(string $type): array
    {
        if ($type === 'image') {
            return array(
                'jpg' => 'image/jpeg',
                'jpeg' => 'image/jpeg',
                'png' => 'image/png',
                'tif' => 'image/tiff',
                'tiff' => 'image/tiff',
            );
        }
        return array(
            'odt' => 'application/vnd.oasis.opendocument.text',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'pdf' => 'application/pdf',
        );
    }

    public static function allowed_extensions(string $type): array
    {
        return array_keys(self::mimes($type));
    }

    public static function dropbox_root(): string
    {
        $settings = self::get();
        $name = preg_replace('/[^A-Za-z0-9_-]/', '-', (string) $settings['appel_name']);
        if (!is_string($name) || trim($name, '-') === '') {
            $name = 'appel';
        }
        return '/Depots-' . trim($name, '-');
    }

    public static function add_menu(): void
    {
        add_options_page(
            'Contribalapelle',
            'Contribalapelle',
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
        $text_keys = array(
            'appel_name', 'label_fiction_500', 'label_fiction_250', 'label_haiku',
            'label_poeme_long', 'label_poeme_court', 'label_image',
            'helloasso_api_url', 'helloasso_client_id', 'helloasso_client_secret',
            'helloasso_org_slug', 'helloasso_form_type', 'helloasso_form_slug',
            'helloasso_ticket_field', 'dropbox_token',
        );
        foreach ($text_keys as $key) {
            if (array_key_exists($key, $input)) {
                $clean[$key] = sanitize_text_field((string) $input[$key]);
            }
        }
        foreach (array('window_open', 'window_close') as $key) {
            if (array_key_exists($key, $input)) {
                $date = CLP_Window::parse_date((string) $input[$key]);
                $clean[$key] = $date !== null ? $date->format('Y-m-d H:i') : '';
            }
        }
        if (array_key_exists('max_file_mb', $input)) {
            $clean['max_file_mb'] = min(500, max(1, (int) $input['max_file_mb']));
        }
        $clean['email_enabled'] = !empty($input['email_enabled']) ? '1' : '';
        $clean['allow_email'] = !empty($input['allow_email']) ? '1' : '';
        if (array_key_exists('privacy_notice', $input)) {
            $clean['privacy_notice'] = wp_kses_post((string) $input['privacy_notice']);
        }
        if (!in_array($clean['helloasso_ticket_field'], array('item_id', 'order_id', 'custom_field'), true)) {
            $clean['helloasso_ticket_field'] = 'item_id';
        }
        if (!in_array($clean['helloasso_form_type'], array('Event', 'Donation', 'Membership', 'PaymentForm', 'Shop', 'CrowdFunding', 'Checkout'), true)) {
            $clean['helloasso_form_type'] = 'Event';
        }
        return $clean;
    }

    public static function render_page(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Accès refusé.');
        }
        $s = self::get();
        $o = self::OPTION_KEY;
        ?>
        <div class="wrap">
            <h1>Contribalapelle — Paramètres</h1>
            <form method="post" action="options.php">
                <?php settings_fields(self::SETTINGS_GROUP); ?>

                <h2>Appel et fenêtre de dépôt</h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="clp-appel">Nom de l'appel</label></th>
                        <td>
                            <input type="text" class="regular-text" id="clp-appel" name="<?php echo esc_attr($o); ?>[appel_name]" value="<?php echo esc_attr($s['appel_name']); ?>">
                            <p class="description">Dossier Dropbox créé : <code>Depots-&lt;nom-de-l'appel&gt;/</code> (sous-dossiers <code>fiction</code>, <code>poesie</code>, <code>image</code>).</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="clp-open">Ouverture du dépôt</label></th>
                        <td><input type="text" class="regular-text" id="clp-open" placeholder="JJ/MM/AAAA HH:MM" name="<?php echo esc_attr($o); ?>[window_open]" value="<?php echo esc_attr($s['window_open']); ?>">
                            <p class="description">Fuseau horaire de référence : Europe/Paris.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="clp-close">Clôture du dépôt</label></th>
                        <td><input type="text" class="regular-text" id="clp-close" placeholder="JJ/MM/AAAA HH:MM" name="<?php echo esc_attr($o); ?>[window_close]" value="<?php echo esc_attr($s['window_close']); ?>"></td>
                    </tr>
                </table>

                <h2>Libellés des sous-catégories</h2>
                <table class="form-table">
                    <?php foreach (self::label_keys() as $key => $label_key) : ?>
                    <tr>
                        <th scope="row"><label for="clp-<?php echo esc_attr($key); ?>"><?php echo esc_html(ucfirst(str_replace('_', ' ', $key))); ?></label></th>
                        <td><input type="text" class="regular-text" id="clp-<?php echo esc_attr($key); ?>" name="<?php echo esc_attr($o); ?>[<?php echo esc_attr($label_key); ?>]" value="<?php echo esc_attr($s[$label_key]); ?>"></td>
                    </tr>
                    <?php endforeach; ?>
                </table>

                <h2>HelloAsso</h2>
                <table class="form-table">
                    <tr><th scope="row">URL de l'API</th><td><input type="text" class="regular-text" name="<?php echo esc_attr($o); ?>[helloasso_api_url]" value="<?php echo esc_attr($s['helloasso_api_url']); ?>"></td></tr>
                    <tr><th scope="row">Client ID</th><td><input type="text" class="regular-text" name="<?php echo esc_attr($o); ?>[helloasso_client_id]" value="<?php echo esc_attr($s['helloasso_client_id']); ?>"></td></tr>
                    <tr><th scope="row">Client Secret</th><td><input type="password" class="regular-text" name="<?php echo esc_attr($o); ?>[helloasso_client_secret]" value="<?php echo esc_attr($s['helloasso_client_secret']); ?>"></td></tr>
                    <tr><th scope="row">Slug de l'organisation</th><td><input type="text" class="regular-text" name="<?php echo esc_attr($o); ?>[helloasso_org_slug]" value="<?php echo esc_attr($s['helloasso_org_slug']); ?>"></td></tr>
                    <tr>
                        <th scope="row">Type de formulaire</th>
                        <td>
                            <select name="<?php echo esc_attr($o); ?>[helloasso_form_type]">
                                <?php foreach (array('Event', 'Donation', 'Membership', 'PaymentForm', 'Shop', 'CrowdFunding', 'Checkout') as $type) : ?>
                                    <option value="<?php echo esc_attr($type); ?>" <?php selected($s['helloasso_form_type'], $type); ?>><?php echo esc_html($type); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr><th scope="row">Slug de la billetterie (formulaire)</th><td><input type="text" class="regular-text" name="<?php echo esc_attr($o); ?>[helloasso_form_slug]" value="<?php echo esc_attr($s['helloasso_form_slug']); ?>"></td></tr>
                    <tr>
                        <th scope="row">Champ de vérification du ticket</th>
                        <td>
                            <select name="<?php echo esc_attr($o); ?>[helloasso_ticket_field]">
                                <option value="item_id" <?php selected($s['helloasso_ticket_field'], 'item_id'); ?>>Numéro d'article acheté (ID item)</option>
                                <option value="order_id" <?php selected($s['helloasso_ticket_field'], 'order_id'); ?>>Numéro de commande</option>
                                <option value="custom_field" <?php selected($s['helloasso_ticket_field'], 'custom_field'); ?>>Champ personnalisé de la campagne</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Identification par e-mail</th>
                        <td><label><input type="checkbox" name="<?php echo esc_attr($o); ?>[allow_email]" value="1" <?php checked($s['allow_email'], '1'); ?>> Les déposants peuvent aussi s'identifier avec l'adresse e-mail utilisée pour l'achat du billet HelloAsso (en plus du numéro de ticket)</label></td>
                    </tr>
                </table>
                <p>
                    <button type="button" class="button" id="clp-test-ha">Tester la connexion à la billetterie</button>
                    <span id="clp-test-ha-result" style="margin-left:0.5em;"></span>
                </p>
                <p class="description">Le test lit les réglages <strong>enregistrés</strong> : cliquez d'abord sur « Enregistrer les modifications » en bas de page.</p>
                <script>
                (function () {
                    var btn = document.getElementById('clp-test-ha');
                    var out = document.getElementById('clp-test-ha-result');
                    if (!btn) { return; }
                    btn.addEventListener('click', function () {
                        out.textContent = 'Test en cours…';
                        var fd = new FormData();
                        fd.append('action', 'clp_admin_test_ha');
                        fd.append('nonce', <?php echo wp_json_encode(wp_create_nonce('clp_admin_test_ha')); ?>);
                        fetch(<?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>, { method: 'POST', body: fd, credentials: 'same-origin' })
                            .then(function (r) { return r.json(); })
                            .then(function (data) { out.textContent = data.message || 'Erreur inconnue.'; })
                            .catch(function () { out.textContent = 'Erreur réseau.'; });
                    });
                })();
                </script>

                <h2>Dropbox et limites</h2>
                <table class="form-table">
                    <tr><th scope="row">Access Token Dropbox</th><td><input type="password" class="regular-text" name="<?php echo esc_attr($o); ?>[dropbox_token]" value="<?php echo esc_attr($s['dropbox_token']); ?>"></td></tr>
                    <tr>
                        <th scope="row">Poids maximum par fichier (Mo)</th>
                        <td><input type="number" min="1" max="500" name="<?php echo esc_attr($o); ?>[max_file_mb]" value="<?php echo esc_attr((string) $s['max_file_mb']); ?>"></td>
                    </tr>
                </table>

                <h2>Message de réussite et confidentialité</h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">Envoi de l'e-mail de confirmation</th>
                        <td><label><input type="checkbox" name="<?php echo esc_attr($o); ?>[email_enabled]" value="1" <?php checked($s['email_enabled'], '1'); ?>> Envoyer un e-mail de confirmation après chaque dépôt réussi (repli à l'écran si l'e-mail est introuvable)</label></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="clp-privacy">Avis affiché sur le formulaire (RGPD)</label></th>
                        <td><textarea id="clp-privacy" class="large-text" rows="5" name="<?php echo esc_attr($o); ?>[privacy_notice]"><?php echo esc_textarea($s['privacy_notice']); ?></textarea></td>
                    </tr>
                </table>

                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
}
