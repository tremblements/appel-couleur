<?php
if (!defined('ABSPATH')) {
    exit;
}

class DAC_Shortcode
{
    public static function register(): void
    {
        add_shortcode('depot_appel_couleur', array(__CLASS__, 'render'));
        add_action('wp_enqueue_scripts', array(__CLASS__, 'enqueue_assets'));
    }

    public static function enqueue_assets(): void
    {
        if (!is_singular()) {
            return;
        }
        $post = get_post();
        if (!$post instanceof WP_Post || !has_shortcode($post->post_content ?? '', 'depot_appel_couleur')) {
            return;
        }
        wp_enqueue_style('dac-style', DAC_PLUGIN_URL . 'assets/depot.css', array(), DAC_VERSION);
        wp_enqueue_script('dac-script', DAC_PLUGIN_URL . 'assets/depot.js', array(), DAC_VERSION, true);
        wp_localize_script('dac-script', 'DAC_DATA', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('dac_upload'),
        ));
    }

    public static function render($atts = array()): string
    {
        $atts = shortcode_atts(array('show_counters' => 'yes'), $atts, 'depot_appel_couleur');
        $show_counters = filter_var($atts['show_counters'], FILTER_VALIDATE_BOOLEAN);

        ob_start();
        ?>
        <div class="dac-depot" id="dac-depot">
            <?php if ($show_counters) : ?>
                <?php echo DAC_Counters::render(); ?>
            <?php endif; ?>
            <form class="dac-form" id="dac-form" enctype="multipart/form-data" method="post">
                <label class="dac-label" for="dac-ticket">Numéro de ticket HelloAsso</label>
                <input type="text" id="dac-ticket" name="ticket" class="dac-input" required>
                <p class="dac-hint">Le numéro figure sur votre billet / e-mail de confirmation HelloAsso.</p>

                <label class="dac-label">Type de dépôt</label>
                <div class="dac-categories">
                    <label class="dac-category"><input type="radio" name="category" value="nouvelle" required> Nouvelle (.docx, .odt)</label>
                    <label class="dac-category"><input type="radio" name="category" value="poeme" required> Poème (.docx, .odt)</label>
                    <label class="dac-category"><input type="radio" name="category" value="photo" required> Photographie (.jpg, .tiff)</label>
                </div>

                <label class="dac-label" for="dac-file">Fichier</label>
                <input type="file" id="dac-file" name="file" class="dac-file" required>

                <button type="submit" class="dac-submit">Déposer</button>
                <p class="dac-message" id="dac-message" aria-live="polite"></p>
            </form>
        </div>
        <?php
        return (string) ob_get_clean();
    }
}
