<?php
if (!defined('ABSPATH')) exit;

// ============================================================
// ADMIN MENU
// ============================================================
add_action('admin_menu', function() {
    add_menu_page(
        'WonderShield',
        'WonderShield',
        'manage_options',
        'wondershield',
        'ws_render_page',
        plugins_url('wm-icon.svg', WS_PLUGIN_DIR . 'wondershield.php'),
        30
    );
});

// ============================================================
// UNBLOCK HANDLER
// ============================================================
add_action('admin_post_ws_unblock', 'ws_handle_unblock');
function ws_handle_unblock() {
    if (!current_user_can('manage_options')) wp_die('Forbidden');
    check_admin_referer('ws_unblock');
    $id = intval($_POST['block_id'] ?? 0);
    if ($id) {
        global $wpdb;
        $wpdb->delete(WS_TABLE_BLOCKS, ['id' => $id], ['%d']);
    }
    wp_redirect(admin_url('admin.php?page=wondershield&unblocked=1'));
    exit;
}

// ============================================================
// AJAX: LOAD MORE LOGS
// ============================================================
add_action('wp_ajax_ws_load_logs', function() {
    if (!current_user_can('manage_options')) wp_die('Forbidden');
    $offset = intval($_POST['offset'] ?? 0);
    global $wpdb;
    $logs = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM " . WS_TABLE_LOG . " ORDER BY created_at DESC LIMIT 50 OFFSET %d",
        $offset
    ));
    ob_start();
    foreach ($logs as $log) {
        ws_render_log_row($log);
    }
    $html = ob_get_clean();
    wp_send_json_success(['html' => $html, 'count' => count($logs)]);
});

// ============================================================
// STATS
// ============================================================
function ws_get_stats() {
    global $wpdb;
    $table = WS_TABLE_LOG;
    $stats = [];
    $stats['blocked_24h'] = (int)$wpdb->get_var(
        "SELECT COUNT(DISTINCT ip) FROM $table WHERE event_type='blocked' AND created_at > DATE_SUB(NOW(), INTERVAL 1 DAY)"
    );
    $stats['blocked_7d'] = (int)$wpdb->get_var(
        "SELECT COUNT(DISTINCT ip) FROM $table WHERE event_type='blocked' AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)"
    );
    $stats['blocked_30d'] = (int)$wpdb->get_var(
        "SELECT COUNT(DISTINCT ip) FROM $table WHERE event_type='blocked' AND created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)"
    );
    $stats['attempts_24h'] = (int)$wpdb->get_var(
        "SELECT COUNT(*) FROM $table WHERE event_type='attempt' AND created_at > DATE_SUB(NOW(), INTERVAL 1 DAY)"
    );
    $stats['xmlrpc_blocked'] = (int)$wpdb->get_var(
        "SELECT COUNT(*) FROM $table WHERE event_type='xmlrpc_blocked'"
    );
    $stats['probes_blocked'] = (int)$wpdb->get_var(
        "SELECT COUNT(*) FROM $table WHERE event_type='probe_blocked'"
    );
    $stats['total_events'] = (int)$wpdb->get_var("SELECT COUNT(*) FROM $table");
    $stats['active_blocks'] = (int)$wpdb->get_var(
        "SELECT COUNT(*) FROM " . WS_TABLE_BLOCKS . " WHERE expires_at > NOW()"
    );
    return $stats;
}

// ============================================================
// LOG ROW RENDERER
// ============================================================
function ws_render_log_row($log) {
    $badges = [
        'blocked'          => ['🛡', '#ef4444', '#fee2e2'],
        'blocked_hit'      => ['🚫', '#f97316', '#ffedd5'],
        'attempt'          => ['⚡', '#eab308', '#fef9c3'],
        'login_failed'     => ['✗',  '#ec4899', '#fce7f3'],
        'xmlrpc_blocked'   => ['🔒', '#8b5cf6', '#ede9fe'],
        'enum_blocked'     => ['👤', '#6366f1', '#e0e7ff'],
        'bad_agent_blocked'=> ['🤖', '#64748b', '#f1f5f9'],
        'probe_blocked'    => ['🕵', '#f97316', '#fff7ed'],
    ];
    $badge = $badges[$log->event_type] ?? ['?', '#6b7280', '#f9fafb'];
    $time_ago = human_time_diff(strtotime($log->created_at)) . ' ago';
    echo '<tr class="ws-log-row">';
    echo '<td><span class="ws-badge" style="background:' . $badge[2] . ';color:' . $badge[1] . '">' . $badge[0] . ' ' . esc_html($log->event_type) . '</span></td>';
    echo '<td class="ws-ip"><code>' . esc_html($log->ip) . '</code></td>';
    echo '<td class="ws-path"><code>' . esc_html($log->path) . '</code></td>';
    echo '<td class="ws-time" title="' . esc_attr($log->created_at) . '">' . esc_html($time_ago) . '</td>';
    echo '</tr>';
}

// ============================================================
// ADMIN PAGE
// ============================================================
function ws_render_page() {
    global $wpdb;
    if (!current_user_can('manage_options')) return;
    $stats = ws_get_stats();
    $active_blocks = $wpdb->get_results(
        "SELECT * FROM " . WS_TABLE_BLOCKS . " WHERE expires_at > NOW() ORDER BY blocked_at DESC"
    );
    $logs = $wpdb->get_results(
        "SELECT * FROM " . WS_TABLE_LOG . " ORDER BY created_at DESC LIMIT 50"
    );
    $unblocked = isset($_GET['unblocked']);
    include WS_PLUGIN_DIR . 'templates/admin-page.php';
}
