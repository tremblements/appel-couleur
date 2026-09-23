<?php
/**
 * Plugin Name: Dépôt Appel à Couleur
 * Description: Zone de dépôt de fichiers (nouvelles, poèmes, photos) vers Dropbox avec vérification de ticket HelloAsso et compteurs de dépôts.
 * Version: 1.0.0
 * Requires PHP: 8.1
 * Author: Tremblements
 * Text Domain: depot-appel-couleur
 */

if (!defined('ABSPATH')) {
    exit;
}

define('DAC_VERSION', '1.0.0');
define('DAC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('DAC_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once DAC_PLUGIN_DIR . 'includes/class-dac-settings.php';
require_once DAC_PLUGIN_DIR . 'includes/class-dac-helloasso.php';
require_once DAC_PLUGIN_DIR . 'includes/class-dac-dropbox.php';
require_once DAC_PLUGIN_DIR . 'includes/class-dac-counters.php';
require_once DAC_PLUGIN_DIR . 'includes/class-dac-upload.php';
require_once DAC_PLUGIN_DIR . 'includes/class-dac-shortcode.php';

register_activation_hook(__FILE__, array('DAC_Counters', 'activate'));

add_action('init', function () {
    DAC_Shortcode::register();
});

DAC_Settings::init();
DAC_Upload::init();
