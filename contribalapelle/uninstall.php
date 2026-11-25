<?php
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

$wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . 'contribalapelle_tickets');
$wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . 'contribalapelle_logs');

delete_option('clp_settings');
delete_option('clp_db_version');
delete_transient('clp_ha_access_token');
