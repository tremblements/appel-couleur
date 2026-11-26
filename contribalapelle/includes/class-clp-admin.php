<?php
if (!defined('ABSPATH')) {
    exit;
}

class CLP_Admin
{
    public static function init(): void
    {
        add_action('admin_menu', array(__CLASS__, 'menu'));
    }

    public static function menu(): void
    {
        add_menu_page(
            'Contribalapelle — Suivi des dépôts',
            'Contribalapelle',
            'manage_options',
            'contribalapelle-suivi',
            array(__CLASS__, 'render_page'),
            'dashicons-upload',
            80
        );
    }

    public static function render_page(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Accès refusé.');
        }
        $logs = CLP_DB::recent_logs(200);
        $tickets = CLP_DB::recent_tickets(200);
        ?>
        <div class="wrap">
            <h1>Contribalapelle — Suivi des dépôts</h1>
            <p>Chaque tentative (vérification ou dépôt, réussie ou refusée) est journalisée. Les données sont supprimées après la publication du numéro de la revue.</p>

            <h2>Journal des opérations</h2>
            <table class="widefat striped">
                <thead>
                    <tr><th>Date (Paris)</th><th>Action</th><th>Ticket</th><th>Statut</th><th>Motif</th><th>Détail</th></tr>
                </thead>
                <tbody>
                <?php foreach ($logs as $log) : ?>
                    <tr>
                        <td><?php echo esc_html((string) $log['created_at']); ?></td>
                        <td><?php echo esc_html((string) $log['action']); ?></td>
                        <td><?php echo esc_html((string) $log['ticket']); ?></td>
                        <td><?php echo esc_html((string) $log['status']); ?></td>
                        <td><?php echo esc_html((string) $log['reason']); ?></td>
                        <td><?php echo esc_html((string) $log['detail']); ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($logs === array()) : ?>
                    <tr><td colspan="6">Aucune opération enregistrée.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>

            <h2>Tickets et contributions déposées</h2>
            <table class="widefat striped">
                <thead>
                    <tr><th>Ticket</th><th>Commande HelloAsso</th><th>E-mail</th><th>Sous-catégorie</th><th>Code humain</th><th>Chemin Dropbox</th><th>Dépôt</th><th>Mise à jour</th></tr>
                </thead>
                <tbody>
                <?php foreach ($tickets as $t) : ?>
                    <tr>
                        <td><?php echo esc_html((string) $t['ticket']); ?></td>
                        <td><?php echo esc_html((string) $t['order_id']); ?></td>
                        <td><?php echo esc_html((string) $t['payer_email']); ?></td>
                        <td><?php echo esc_html((string) $t['subcategory']); ?></td>
                        <td><?php echo esc_html((string) ($t['human_code'] ?? '')); ?></td>
                        <td><?php echo esc_html((string) $t['dropbox_path']); ?></td>
                        <td><?php echo esc_html((string) $t['deposited_at']); ?></td>
                        <td><?php echo esc_html((string) $t['updated_at']); ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($tickets === array()) : ?>
                    <tr><td colspan="8">Aucun ticket enregistré.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
}
