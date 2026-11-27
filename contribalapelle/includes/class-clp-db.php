<?php
if (!defined('ABSPATH')) {
    exit;
}

class CLP_DB
{
    const DB_VERSION = '3';

    public static function tickets_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'contribalapelle_tickets';
    }

    public static function logs_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'contribalapelle_logs';
    }

    public static function activate(): void
    {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $tickets = self::tickets_table();
        $logs = self::logs_table();

        $wpdb->query("CREATE TABLE IF NOT EXISTS {$tickets} (
            ticket VARCHAR(64) NOT NULL,
            order_id VARCHAR(64) NOT NULL DEFAULT '',
            payer_email VARCHAR(190) NOT NULL DEFAULT '',
            subcategory VARCHAR(32) NULL DEFAULT NULL,
            human_code VARCHAR(16) NULL DEFAULT NULL,
            preselected TINYINT(1) NOT NULL DEFAULT 0,
            dropbox_path VARCHAR(512) NULL DEFAULT NULL,
            status VARCHAR(16) NOT NULL DEFAULT 'pending',
            deposited_at DATETIME NULL DEFAULT NULL,
            updated_at DATETIME NULL DEFAULT NULL,
            PRIMARY KEY (ticket)
        ) {$charset};");

        $wpdb->query("CREATE TABLE IF NOT EXISTS {$logs} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            created_at DATETIME NOT NULL,
            action VARCHAR(16) NOT NULL DEFAULT '',
            ticket VARCHAR(64) NOT NULL DEFAULT '',
            status VARCHAR(16) NOT NULL DEFAULT '',
            reason VARCHAR(64) NOT NULL DEFAULT '',
            detail VARCHAR(512) NOT NULL DEFAULT '',
            PRIMARY KEY (id)
        ) {$charset};");

        update_option('clp_db_version', self::DB_VERSION);
    }

    public static function maybe_upgrade(): void
    {
        global $wpdb;
        if (get_option('clp_db_version') !== self::DB_VERSION) {
            self::activate();
            $table = self::tickets_table();
            $columns = $wpdb->get_results("SHOW COLUMNS FROM {$table} LIKE 'human_code'");
            if ($columns === array()) {
                $wpdb->query("ALTER TABLE {$table} ADD COLUMN human_code VARCHAR(16) NULL DEFAULT NULL AFTER subcategory");
            }
            $columns = $wpdb->get_results("SHOW COLUMNS FROM {$table} LIKE 'preselected'");
            if ($columns === array()) {
                $wpdb->query("ALTER TABLE {$table} ADD COLUMN preselected TINYINT(1) NOT NULL DEFAULT 0 AFTER human_code");
            }
            update_option('clp_db_version', self::DB_VERSION);
        }
    }

    public static function get_ticket(string $ticket): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM ' . self::tickets_table() . ' WHERE ticket = %s', $ticket),
            ARRAY_A
        );
        return is_array($row) ? $row : null;
    }

    public static function upsert_pending(string $ticket, string $order_id, string $email): void
    {
        global $wpdb;
        $now = CLP_Window::now()->format('Y-m-d H:i:s');
        $existing = self::get_ticket($ticket);
        if ($existing === null) {
            $wpdb->insert(self::tickets_table(), array(
                'ticket' => $ticket,
                'order_id' => $order_id,
                'payer_email' => $email,
                'status' => 'pending',
                'updated_at' => $now,
            ));
            return;
        }
        if ($existing['status'] === 'deposited') {
            return;
        }
        $data = array('updated_at' => $now);
        $where = array('ticket' => $ticket);
        if ($existing['order_id'] === '' && $order_id !== '') {
            $data['order_id'] = $order_id;
        }
        if ($existing['payer_email'] === '' && $email !== '') {
            $data['payer_email'] = $email;
        }
        if (count($data) > 1) {
            $wpdb->update(self::tickets_table(), $data, $where);
        }
    }

    public static function mark_deposited(string $ticket, string $subcategory, string $dropbox_path, string $email, string $human_code = ''): void
    {
        global $wpdb;
        $now = CLP_Window::now()->format('Y-m-d H:i:s');
        $existing = self::get_ticket($ticket);
        $data = array(
            'status' => 'deposited',
            'subcategory' => $subcategory,
            'human_code' => $human_code,
            'dropbox_path' => $dropbox_path,
            'updated_at' => $now,
        );
        if ($email !== '') {
            $data['payer_email'] = $email;
        }
        if ($existing !== null && !empty($existing['deposited_at'])) {
            $data['deposited_at'] = $existing['deposited_at'];
        } else {
            $data['deposited_at'] = $now;
        }
        $wpdb->update(self::tickets_table(), $data, array('ticket' => $ticket));
    }

    public static function count_deposits(): int
    {
        global $wpdb;
        $n = $wpdb->get_var("SELECT COUNT(*) FROM " . self::tickets_table() . " WHERE status = 'deposited'");
        return is_numeric($n) ? (int) $n : 0;
    }

    public static function set_preselected(string $ticket, int $value): void
    {
        global $wpdb;
        $wpdb->update(self::tickets_table(), array('preselected' => $value ? 1 : 0), array('ticket' => $ticket));
    }

    public static function preselected_list(): array
    {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT human_code, subcategory, deposited_at FROM " . self::tickets_table()
            . " WHERE preselected = 1 AND status = 'deposited' ORDER BY deposited_at ASC",
            ARRAY_A
        );
        return is_array($rows) ? $rows : array();
    }

    public static function used_codes(): array
    {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT human_code FROM " . self::tickets_table() . " WHERE human_code IS NOT NULL AND human_code <> ''",
            ARRAY_A
        );
        if (!is_array($rows)) {
            return array();
        }
        return array_values(array_map(function ($row) {
            return (string) $row['human_code'];
        }, $rows));
    }

    public static function log(string $action, string $ticket, string $status, string $reason = '', string $detail = ''): void
    {
        global $wpdb;
        $wpdb->insert(self::logs_table(), array(
            'created_at' => CLP_Window::now()->format('Y-m-d H:i:s'),
            'action' => $action,
            'ticket' => substr($ticket, 0, 64),
            'status' => $status,
            'reason' => substr($reason, 0, 64),
            'detail' => substr($detail, 0, 512),
        ));
    }

    public static function recent_logs(int $limit = 200): array
    {
        global $wpdb;
        $rows = $wpdb->get_results(
            'SELECT * FROM ' . self::logs_table() . ' ORDER BY id DESC LIMIT ' . absint($limit),
            ARRAY_A
        );
        return is_array($rows) ? $rows : array();
    }

    public static function recent_tickets(int $limit = 200): array
    {
        global $wpdb;
        $rows = $wpdb->get_results(
            'SELECT * FROM ' . self::tickets_table() . ' ORDER BY updated_at DESC LIMIT ' . absint($limit),
            ARRAY_A
        );
        return is_array($rows) ? $rows : array();
    }
}
