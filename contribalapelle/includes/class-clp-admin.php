<?php
if (!defined('ABSPATH')) {
    exit;
}

class CLP_Admin
{
    public static function init(): void
    {
        add_action('admin_menu', array(__CLASS__, 'menu'));
        add_action('admin_post_clp_admin_delete_ticket', array(__CLASS__, 'delete_ticket'));
        add_action('admin_post_clp_admin_purge', array(__CLASS__, 'purge'));
        add_action('admin_post_clp_admin_toggle_preselected', array(__CLASS__, 'toggle_preselected'));
    }

    public static function toggle_preselected(): void
    {
        check_admin_referer('clp_admin_toggle_preselected');
        if (!current_user_can('manage_options')) {
            wp_die('Accès refusé.');
        }
        $ticket = isset($_POST['ticket']) ? sanitize_text_field(wp_unslash($_POST['ticket'])) : '';
        $value = !empty($_POST['preselected']) ? 1 : 0;
        if ($ticket !== '') {
            CLP_DB::set_preselected($ticket, $value);
            CLP_DB::log('admin', $ticket, 'success', 'preselection', $value ? 'ajoutée' : 'retirée');
        }
        wp_safe_redirect(admin_url('admin.php?page=contribalapelle-suivi'));
        exit;
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

    public static function delete_ticket(): void
    {
        check_admin_referer('clp_admin_delete_ticket');
        if (!current_user_can('manage_options')) {
            wp_die('Accès refusé.');
        }
        $ticket = isset($_POST['ticket']) ? sanitize_text_field(wp_unslash($_POST['ticket'])) : '';
        if ($ticket !== '') {
            global $wpdb;
            $wpdb->delete(CLP_DB::tickets_table(), array('ticket' => $ticket), array('%s'));
            CLP_DB::log('admin', $ticket, 'success', 'suppression-ticket', 'manuelle (admin)');
        }
        wp_safe_redirect(admin_url('admin.php?page=contribalapelle-suivi'));
        exit;
    }

    public static function purge(): void
    {
        check_admin_referer('clp_admin_purge');
        if (!current_user_can('manage_options')) {
            wp_die('Accès refusé.');
        }
        global $wpdb;
        $wpdb->query('TRUNCATE TABLE ' . CLP_DB::tickets_table());
        $wpdb->query('TRUNCATE TABLE ' . CLP_DB::logs_table());
        CLP_DB::log('admin', '', 'success', 'purge-rgpd', 'toutes les données supprimées (tickets + journal)');
        wp_safe_redirect(admin_url('admin.php?page=contribalapelle-suivi'));
        exit;
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
            <h1>Contribalapelle — Suivi des dépôts <span class="clp-version">v<?php echo esc_html(CLP_VERSION); ?></span></h1>
            <p>Chaque tentative (vérification ou dépôt, réussie ou refusée) est journalisée. Les données sont supprimées après la publication du numéro de la revue.</p>
            <p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;">
                    <input type="hidden" name="action" value="clp_admin_purge">
                    <?php wp_nonce_field('clp_admin_purge'); ?>
                    <button type="submit" class="button" onclick="return confirm('Supprimer TOUTES les données (tickets + journal) ? Opération RGPD, à faire après la publication du numéro.');">Purger toutes les données (RGPD)</button>
                </form>
            </p>

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
                    <tr><th>Ticket</th><th>Commande HelloAsso</th><th>E-mail</th><th>Sous-catégorie</th><th>Code humain</th><th>Présélection</th><th>Chemin Dropbox</th><th>Dépôt</th><th>Mise à jour</th><th>Actions</th></tr>
                </thead>
                <tbody>
                <?php foreach ($tickets as $t) : ?>
                    <tr>
                        <td><?php echo esc_html((string) $t['ticket']); ?></td>
                        <td><?php echo esc_html((string) $t['order_id']); ?></td>
                        <td><?php echo esc_html((string) $t['payer_email']); ?></td>
                        <td><?php echo esc_html((string) $t['subcategory']); ?></td>
                        <td><?php echo esc_html((string) ($t['human_code'] ?? '')); ?></td>
                        <td>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                <input type="hidden" name="action" value="clp_admin_toggle_preselected">
                                <input type="hidden" name="ticket" value="<?php echo esc_attr((string) $t['ticket']); ?>">
                                <?php wp_nonce_field('clp_admin_toggle_preselected'); ?>
                                <input type="checkbox" name="preselected" value="1" <?php checked(!empty($t['preselected'])); ?> onchange="this.form.submit()" title="Cocher = contribution présélectionnée (visible via [clp_preselection])">
                            </form>
                        </td>
                        <td><?php echo esc_html((string) $t['dropbox_path']); ?></td>
                        <td><?php echo esc_html((string) $t['deposited_at']); ?></td>
                        <td><?php echo esc_html((string) $t['updated_at']); ?></td>
                        <td>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('Supprimer ce ticket de la base (le fichier Dropbox n\'est PAS supprimé) ?');">
                                <input type="hidden" name="action" value="clp_admin_delete_ticket">
                                <input type="hidden" name="ticket" value="<?php echo esc_attr((string) $t['ticket']); ?>">
                                <?php wp_nonce_field('clp_admin_delete_ticket'); ?>
                                <button type="submit" class="button button-small">Supprimer</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($tickets === array()) : ?>
                    <tr><td colspan="10">Aucun ticket enregistré.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
}
