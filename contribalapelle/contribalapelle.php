<?php
/**
 * Plugin Name: Contribalapelle
 * Description: Fonction « Dépôt contribution » de la revue Pourtant : dépôt d'un texte, poème ou image par un contributeur inscrit à l'appel (ticket HelloAsso vérifié), rangé dans Dropbox.
 * Version: 1.4.12
 * Requires PHP: 8.1
 * Author: Tremblements
 * Text Domain: contribalapelle
 */

if (!defined('ABSPATH')) {
    exit;
}

define('CLP_VERSION', '1.4.12');
define('CLP_PLUGIN_FILE', __FILE__);
define('CLP_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('CLP_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once CLP_PLUGIN_DIR . 'includes/class-clp-db.php';
require_once CLP_PLUGIN_DIR . 'includes/class-clp-codes.php';
require_once CLP_PLUGIN_DIR . 'includes/class-clp-settings.php';
require_once CLP_PLUGIN_DIR . 'includes/class-clp-window.php';
require_once CLP_PLUGIN_DIR . 'includes/class-clp-helloasso.php';
require_once CLP_PLUGIN_DIR . 'includes/class-clp-dropbox.php';
require_once CLP_PLUGIN_DIR . 'includes/class-clp-upload.php';
require_once CLP_PLUGIN_DIR . 'includes/class-clp-shortcode.php';
require_once CLP_PLUGIN_DIR . 'includes/class-clp-admin.php';

register_activation_hook(__FILE__, array('CLP_DB', 'activate'));

add_action('init', array('CLP_Shortcode', 'register'));
add_action('init', array('CLP_DB', 'maybe_upgrade'));

CLP_Settings::init();
CLP_Upload::init();
CLP_Admin::init();
