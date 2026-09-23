<?php
/**
 * Table personnalisée des dépôts + helpers de compteurs.
 */

if (!defined('ABSPATH')) {
    exit;
}

class ACD_Submissions
{
    const TABLE_SUFFIX = 'acd_submissions';

    public static function table_name()
    {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_SUFFIX;
    }

    public static function activate()
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table = self::table_name();
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            type VARCHAR(20) NOT NULL,
            ticket_number VARCHAR(191) NOT NULL DEFAULT '',
            original_filename VARCHAR(255) NOT NULL DEFAULT '',
            dropbox_path VARCHAR(512) NOT NULL DEFAULT '',
            ip VARCHAR(45) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY type (type),
            KEY ticket_number (ticket_number)
        ) {$charset};";

        dbDelta($sql);
    }

    public static function insert($type, $ticket_number, $original_filename, $dropbox_path)
    {
        global $wpdb;

        $wpdb->insert(
            self::table_name(),
            array(
                'type' => sanitize_key($type),
                'ticket_number' => sanitize_text_field($ticket_number),
                'original_filename' => sanitize_file_name($original_filename),
                'dropbox_path' => sanitize_text_field($dropbox_path),
                'ip' => isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field($_SERVER['REMOTE_ADDR']) : '',
                'created_at' => current_time('mysql'),
            ),
            array('%s', '%s', '%s', '%s', '%s', '%s')
        );

        return (int) $wpdb->insert_id;
    }

    /**
     * Nombre de dépôts pour un type donné (ou tous les types).
     *
     * @param string|null $type
     * @return int
     */
    public static function count($type = null)
    {
        global $wpdb;
        $table = self::table_name();

        if (null === $type) {
            return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        }

        return (int) $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE type = %s", sanitize_key($type))
        );
    }
}
