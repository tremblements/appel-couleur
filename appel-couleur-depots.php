<?php
/**
 * Appel Couleur Dépôts
 *
 * @package appel-couleur-depots
 */

if (!defined('ABSPATH')) {
    exit;
}

define('ACD_VERSION', '0.1');
define('ACD_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('ACD_MAX_FILE_SIZE', 20 * 1024 * 1024);

require_once ACD_PLUGIN_DIR . 'includes/class-acd-helloasso.php';
require_once ACD_PLUGIN_DIR . 'includes/class-acd-dropbox.php';
require_once ACD_PLUGIN_DIR . 'includes/class-acd-submissions.php';
require_once ACD_PLUGIN_DIR . 'includes/class-acd-shortcode.php';
require_once ACD_PLUGIN_DIR . 'includes/class-acd-admin.php';

register_activation_hook(__FILE__, array('ACD_Submissions', 'activate'));

function acd_init()
{
    ACD_Shortcode::init();
    ACD_Admin::init();
}
add_action('plugins_loaded', 'acd_init');

function acd_plugin_settings_link($links)
{
    $url = admin_url('admin.php?page=acd-settings');
    array_unshift($links, '<a href="' . esc_url($url) . '">' . esc_html__('Réglages', 'appel-couleur-depots') . '</a>');
    return $links;
}
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'acd_plugin_settings_link');
