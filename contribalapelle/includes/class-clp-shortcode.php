<?php
if (!defined('ABSPATH')) {
    exit;
}

class CLP_Shortcode
{
    const SHORTCODE = 'contribalapelle';

    public static function register(): void
    {
        add_shortcode(self::SHORTCODE, array(__CLASS__, 'render'));
        add_shortcode('clp_preselection', array(__CLASS__, 'render_preselection'));
        add_action('wp_enqueue_scripts', array(__CLASS__, 'enqueue_assets'));
    }

    public static function enqueue_assets(): void
    {
        if (!is_singular()) {
            return;
        }
        $post = get_post();
        if (!$post instanceof WP_Post || !has_shortcode((string) ($post->post_content ?? ''), self::SHORTCODE)) {
            return;
        }
        wp_enqueue_style('clp-style', CLP_PLUGIN_URL . 'assets/clp.css', array(), CLP_VERSION);
        wp_enqueue_script('clp-script', CLP_PLUGIN_URL . 'assets/clp.js', array(), CLP_VERSION, true);

        $window = CLP_Window::status();
        $settings = CLP_Settings::get();
        $subs = array();
        foreach (CLP_Settings::subcategories() as $key => $def) {
            $subs[] = array(
                'key' => $key,
                'type' => $def['type'],
                'label' => CLP_Settings::subcategory_label($key),
            );
        }

        wp_localize_script('clp-script', 'CLP_DATA', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce_verify' => wp_create_nonce('clp_verify'),
            'nonce_deposit' => wp_create_nonce('clp_deposit'),
            'nonce_check' => wp_create_nonce('clp_check_deposit'),
            'read_only' => $window['status'] !== 'open',
            'window' => array(
                'status' => $window['status'],
                'open_label' => CLP_Window::format_label($window['open']),
                'close_label' => CLP_Window::format_label($window['close']),
            ),
            'subcategories' => $subs,
            'max_mb' => max(1, (int) $settings['max_file_mb']),
            'ext' => array(
                'texte' => CLP_Settings::allowed_extensions('texte'),
                'image' => CLP_Settings::allowed_extensions('image'),
            ),
        ));
    }

    public static function render($atts = array()): string
    {
        $atts = shortcode_atts(array(), $atts, self::SHORTCODE);
        $window = CLP_Window::status();
        $settings = CLP_Settings::get();
        $read_only = $window['status'] !== 'open';
        $disabled = $read_only ? ' disabled' : '';

        if ($window['status'] === 'before') {
            $notice = sprintf('Le dépôt n\'est pas encore ouvert. Ouverture le %s (heure de Paris).', esc_html(CLP_Window::format_label($window['open'])));
        } elseif ($window['status'] === 'closed') {
            $notice = sprintf('La période de dépôt est close depuis le %s (heure de Paris).', esc_html(CLP_Window::format_label($window['close'])));
        } elseif ($window['status'] === 'open') {
            $notice = sprintf('Dépôt ouvert jusqu\'au %s (heure de Paris).', esc_html(CLP_Window::format_label($window['close'])));
        } else {
            $notice = 'La période de dépôt n\'est pas configurée pour le moment.';
        }

        $hidden = $read_only ? '' : ' clp-hidden';

        ob_start();
        ?>
        <div class="clp-depot" id="clp-depot">
            <p class="clp-notice" id="clp-notice"><?php echo $notice; ?></p>
            <?php if (($settings['show_progress'] ?? '') === '1') : ?>
                <?php
                $deposits = CLP_DB::count_deposits();
                $total_items = CLP_HelloAsso::count_items_cached();
                $pct = $total_items > 0 ? min(100, (int) round($deposits / $total_items * 100)) : 0;
                ?>
                <div class="clp-progress-wrap">
                    <div class="clp-progress"><div class="clp-progress-bar" style="width: <?php echo esc_attr((string) $pct); ?>%;"></div></div>
                    <p class="clp-progress-text"><?php echo esc_html($deposits . ' dépôt(s) effectué(s)' . ($total_items > 0 ? ' sur ' . $total_items . ' billet(s)' : '')); ?></p>
                </div>
            <?php endif; ?>
            <?php if (!$read_only) : ?>
                <button type="button" class="clp-toggle" id="clp-toggle">Je dépose</button>
            <?php endif; ?>
            <form class="clp-form<?php echo $hidden; ?><?php echo $read_only ? ' clp-readonly' : ''; ?>" id="clp-form" enctype="multipart/form-data" method="post"<?php echo $read_only ? ' aria-disabled="true"' : ''; ?>>
                <div class="clp-step" id="clp-step-ticket">
                    <label class="clp-label" for="clp-ticket">Numéro de ticket ou e-mail HelloAsso</label>
                    <input type="text" id="clp-ticket" name="ticket" class="clp-input" required<?php echo $disabled; ?>>
                    <p class="clp-hint">Saisissez le numéro figurant sur votre billet HelloAsso, ou l'adresse e-mail utilisée lors de l'achat du billet.</p>
                    <label class="clp-label" for="clp-email">Votre e-mail (facultatif)</label>
                    <input type="email" id="clp-email" name="email" class="clp-input"<?php echo $disabled; ?>>
                    <p class="clp-hint">Utilisé uniquement pour le message de confirmation si HelloAsso ne le fournit pas.</p>
                    <button type="button" class="clp-btn" id="clp-verify-btn"<?php echo $disabled; ?>>Vérifier</button>
                    <p class="clp-message" id="clp-verify-message" aria-live="polite"></p>
                </div>

                <div class="clp-step clp-hidden" id="clp-step-cat">
                    <span class="clp-label">Type de contribution</span>
                    <label class="clp-radio"><input type="radio" name="clp-type" value="texte" checked<?php echo $disabled; ?>> Texte</label>
                    <label class="clp-radio"><input type="radio" name="clp-type" value="image"<?php echo $disabled; ?>> Image</label>
                    <label class="clp-label" for="clp-subcategory">Sous-catégorie</label>
                    <select id="clp-subcategory" name="subcategory" class="clp-input"<?php echo $disabled; ?>></select>
                    <button type="button" class="clp-btn clp-next" id="clp-to-file"<?php echo $disabled; ?>>Continuer</button>
                </div>

                <div class="clp-step clp-hidden" id="clp-step-file">
                    <label class="clp-label" for="clp-file">Fichier</label>
                    <input type="file" id="clp-file" name="file" class="clp-file" required<?php echo $disabled; ?>>
                    <p class="clp-hint" id="clp-file-hint"></p>
                    <label class="clp-confirm clp-hidden" id="clp-confirm-wrap"><input type="checkbox" id="clp-confirm-replace" value="1"<?php echo $disabled; ?>> J'ai déjà déposé une contribution avec ce billet : je confirme vouloir l'effacer et la remplacer par ce nouveau fichier.</label>
                    <button type="submit" class="clp-btn clp-submit" id="clp-submit"<?php echo $disabled; ?>>Valider le dépôt</button>
                    <p class="clp-message" id="clp-deposit-message" aria-live="polite"></p>
                    <p class="clp-message clp-detail" id="clp-deposit-detail" aria-live="polite"></p>
                </div>

                <p class="clp-privacy"><?php echo wp_kses_post($settings['privacy_notice']); ?></p>
            </form>
            <div class="clp-check-wrap">
                <button type="button" class="clp-check-toggle" id="clp-check-toggle">Vérification dépôt</button>
                <div class="clp-check clp-hidden" id="clp-check">
                    <label class="clp-label" for="clp-check-email">Adresse e-mail utilisée pour l'achat du billet HelloAsso</label>
                    <input type="email" id="clp-check-email" class="clp-input">
                    <button type="button" class="clp-btn" id="clp-check-btn">Envoyer la vérification</button>
                    <p class="clp-message" id="clp-check-message" aria-live="polite"></p>
                </div>
            </div>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    public static function render_preselection($atts = array()): string
    {
        $rows = CLP_DB::preselected_list();
        ob_start();
        ?>
        <div class="clp-preselect">
            <h3 class="clp-preselect-title">Contributions présélectionnées par le comité de lecture</h3>
            <?php if ($rows === array()) : ?>
                <p>Aucune contribution présélectionnée pour le moment.</p>
            <?php else : ?>
                <ul class="clp-preselect-list">
                    <?php foreach ($rows as $row) : ?>
                        <li><strong><?php echo esc_html((string) ($row['human_code'] ?? '')); ?></strong> — <?php echo esc_html(CLP_Settings::cat_label((string) ($row['subcategory'] ?? ''))); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
        <?php
        return (string) ob_get_clean();
    }
}
