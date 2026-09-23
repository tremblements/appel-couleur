<?php
/**
 * Désinstallation : supprime la table des dépôts et les options.
 *
 * @package appel-couleur-depots
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

$table = $wpdb->prefix . 'acd_submissions';
$wpdb->query("DROP TABLE IF EXISTS {$table}");

delete_option('acd_helloasso_client_id');
delete_option('acd_helloasso_client_secret');
delete_option('acd_helloasso_organization_slug');
delete_option('acd_helloasso_sandbox');
delete_option('acd_helloasso_refresh_token');
delete_option('acd_dropbox_access_token');
delete_option('acd_verify_tickets');
delete_option('acd_allow_public');
delete_option('acd_verified_tickets');
delete_transient('acd_helloasso_access_token');
