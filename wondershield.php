<?php
/**
 * Plugin Name: WonderShield
 * Plugin URI: https://wondermedia.co.uk
 * Description: Security hardening and brute force protection by Wonder Media
 * Version: 1.0.3
 * Author: Wonder Media Ltd
 * Author URI: https://wondermedia.co.uk
 * License: Proprietary
 */
if (!defined('ABSPATH')) exit;
define('WS_VERSION', '1.0.3');
define('WS_TABLE_LOG', $GLOBALS['wpdb']->prefix . 'wondershield_log');
define('WS_TABLE_BLOCKS', $GLOBALS['wpdb']->prefix . 'wondershield_blocks');
define('WS_MAX_ATTEMPTS', 5);
define('WS_ATTEMPT_WINDOW', 300);
define('WS_LOCKOUT_DURATION', 1800);
define('WS_LOG_MAX_ROWS', 1000);
define('WS_LOG_MAX_DAYS', 30);
define('WS_NOTIFY_EMAIL', 'hello@wondermedia.co.uk');
// ============================================================
// GITHUB AUTO-UPDATER
// ============================================================
require_once __DIR__ . '/includes/class-updater.php';
$ws_updater = new WonderShield_Updater(__FILE__);
$ws_updater->init();
// ============================================================
// ACTIVATION / DEACTIVATION
// ============================================================
register_activation_hook(__FILE__, 'ws_activate');
register_deactivation_hook(__FILE__, 'ws_deactivate');
function ws_activate() {
    global $wpdb;
    $charset = $wpdb->get_charset_collate();
    $sql1 = "CREATE TABLE IF NOT EXISTS " . WS_TABLE_LOG . " (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        event_type VARCHAR(30) NOT NULL,
        ip VARCHAR(45) NOT NULL,
        path VARCHAR(255) NOT NULL,
        user_agent TEXT,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_ip (ip),
        KEY idx_created (created_at),
        KEY idx_event (event_type)
    ) $charset;";
    $sql2 = "CREATE TABLE IF NOT EXISTS " . WS_TABLE_BLOCKS . " (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        ip VARCHAR(45) NOT NULL UNIQUE,
        reason VARCHAR(100) NOT NULL,
        blocked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        expires_at DATETIME NOT NULL,
        manual TINYINT(1) NOT NULL DEFAULT 0,
        PRIMARY KEY (id),
        KEY idx_ip (ip),
        KEY idx_expires (expires_at)
    ) $charset;";
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql1);
    dbDelta($sql2);
    if (!wp_next_scheduled('ws_daily_cleanup')) {
        wp_schedule_event(time(), 'daily', 'ws_daily_cleanup');
    }
}
function ws_deactivate() {
    wp_clear_scheduled_hook('ws_daily_cleanup');
}
// ============================================================
// HELPERS
// ============================================================
function ws_get_ip() {
    $keys = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'];
    foreach ($keys as $key) {
        if (!empty($_SERVER[$key])) {
            $ip = trim(explode(',', $_SERVER[$key])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }
    return '0.0.0.0';
}
function ws_is_blocked($ip) {
    global $wpdb;
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM " . WS_TABLE_BLOCKS . " WHERE ip = %s AND expires_at > NOW()",
        $ip
    ));
    return $row ? $row : false;
}
function ws_block_ip($ip, $reason = 'brute_force', $manual = 0) {
    global $wpdb;
    $expires = date('Y-m-d H:i:s', time() + WS_LOCKOUT_DURATION);
    $wpdb->replace(WS_TABLE_BLOCKS, [
        'ip' => $ip,
        'reason' => $reason,
        'blocked_at' => current_time('mysql'),
        'expires_at' => $expires,
        'manual' => $manual,
    ], ['%s','%s','%s','%s','%d']);
    ws_log($ip, 'blocked', $_SERVER['REQUEST_URI'] ?? '/', $_SERVER['HTTP_USER_AGENT'] ?? '');
    ws_send_notification($ip, $reason);
}
function ws_log($ip, $event_type, $path, $user_agent = '') {
    global $wpdb;
    $wpdb->insert(WS_TABLE_LOG, [
        'event_type' => $event_type,
        'ip' => $ip,
        'path' => substr($path, 0, 255),
        'user_agent' => substr($user_agent, 0, 500),
        'created_at' => current_time('mysql'),
    ], ['%s','%s','%s','%s','%s']);
}
function ws_send_notification($ip, $reason) {
    $subject = '[WonderShield] IP Blocked: ' . $ip;
    $body = "WonderShield has blocked an IP address on " . get_bloginfo('name') . ".\n\n";
    $body .= "IP: $ip\n";
    $body .= "Reason: $reason\n";
    $body .= "Time: " . current_time('mysql') . "\n";
    $body .= "Expires: " . date('Y-m-d H:i:s', time() + WS_LOCKOUT_DURATION) . "\n\n";
    $body .= "You can manage blocks at: " . admin_url('admin.php?page=wondershield') . "\n";
    wp_mail(WS_NOTIFY_EMAIL, $subject, $body);
}
function ws_count_recent_attempts($ip, $path_pattern, $window_seconds) {
    global $wpdb;
    $since = date('Y-m-d H:i:s', time() - $window_seconds);
    return (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM " . WS_TABLE_LOG . "
         WHERE ip = %s AND path LIKE %s AND event_type = 'attempt' AND created_at > %s",
        $ip, $path_pattern, $since
    ));
}
// ============================================================
// DAILY CLEANUP
// ============================================================
add_action('ws_daily_cleanup', 'ws_run_cleanup');
function ws_run_cleanup() {
    global $wpdb;
    $cutoff = date('Y-m-d H:i:s', strtotime('-' . WS_LOG_MAX_DAYS . ' days'));
    $wpdb->query("DELETE FROM " . WS_TABLE_LOG . " WHERE created_at < '$cutoff'");
    $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM " . WS_TABLE_LOG);
    if ($count > WS_LOG_MAX_ROWS) {
        $keep_id = (int) $wpdb->get_var(
            "SELECT id FROM " . WS_TABLE_LOG . " ORDER BY id DESC LIMIT 1 OFFSET " . (WS_LOG_MAX_ROWS - 1)
        );
        if ($keep_id) {
            $wpdb->query("DELETE FROM " . WS_TABLE_LOG . " WHERE id < $keep_id");
        }
    }
    $wpdb->query("DELETE FROM " . WS_TABLE_BLOCKS . " WHERE expires_at < NOW() AND manual = 0");
}
// ============================================================
// BLOCK PAGE
// ============================================================
function ws_block_response($message, $ip = null, $mins = null) {
    $ip       = $ip ?: ws_get_ip();
    $datetime = date('d M Y, H:i') . ' UTC';
    $subline  = $mins
        ? "Access will be restored in approximately <strong>{$mins} minute" . ($mins === 1 ? '' : 's') . "</strong>."
        : "If you believe this is a mistake, please contact the site owner.";
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Access Blocked — WonderShield</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Dosis:wght@400;600;700;800&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{min-height:100vh;display:flex;align-items:center;justify-content:center;
background:#07011a;font-family:"Dosis",sans-serif;overflow:hidden;position:relative;}
body::before{content:"";position:fixed;inset:0;
background:radial-gradient(ellipse 80% 60% at 50% 0%,rgba(86,0,255,0.18) 0%,transparent 70%),
radial-gradient(ellipse 50% 40% at 80% 80%,rgba(0,220,255,0.08) 0%,transparent 60%);
pointer-events:none;}
.ws-card{position:relative;background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.08);
border-radius:24px;padding:48px 40px;max-width:480px;width:90%;text-align:center;
backdrop-filter:blur(20px);box-shadow:0 0 80px rgba(86,0,255,0.15),0 24px 48px rgba(0,0,0,0.4);}
.ws-shield{width:80px;height:80px;margin:0 auto 24px;
background:linear-gradient(135deg,#5600FF,#00DCFF);
border-radius:22px;display:flex;align-items:center;justify-content:center;
box-shadow:0 0 40px rgba(86,0,255,0.5);}
.ws-shield svg{width:44px;height:44px;}
.ws-brand{font-size:11px;font-weight:700;letter-spacing:0.18em;text-transform:uppercase;
color:rgba(255,255,255,0.35);margin-bottom:20px;}
.ws-title{font-size:22px;font-weight:800;color:#fff;letter-spacing:0.02em;margin-bottom:10px;}
.ws-message{font-size:14px;color:rgba(255,255,255,0.5);line-height:1.6;margin-bottom:28px;}
.ws-message strong{color:rgba(255,255,255,0.75);}
.ws-pills{display:flex;flex-direction:column;gap:8px;margin-bottom:24px;}
.ws-info-pill{display:flex;align-items:center;gap:10px;
background:rgba(255,255,255,0.05);border:1px solid rgba(255,255,255,0.07);
border-radius:50px;padding:8px 16px;font-size:12px;}
.ws-info-pill .pill-icon{font-size:14px;flex-shrink:0;}
.ws-info-pill .pill-label{color:rgba(255,255,255,0.3);font-size:10px;font-weight:700;
letter-spacing:0.1em;text-transform:uppercase;white-space:nowrap;}
.ws-info-pill .pill-value{color:rgba(255,255,255,0.7);font-weight:600;margin-left:auto;
font-size:11px;letter-spacing:0.02em;}
.ws-logged{display:inline-flex;align-items:center;gap:6px;
font-size:10px;font-weight:700;letter-spacing:0.1em;text-transform:uppercase;
color:rgba(0,220,255,0.6);border:1px solid rgba(0,220,255,0.15);
border-radius:50px;padding:5px 14px;}
.ws-logged::before{content:"";width:6px;height:6px;border-radius:50%;
background:#00DCFF;box-shadow:0 0 8px #00DCFF;flex-shrink:0;}
</style></head><body>
<div class="ws-card">
    <div class="ws-shield">
        <svg viewBox="0 0 44 44" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path d="M22 4L6 10v12c0 9.5 6.8 18.4 16 20.6C31.2 40.4 38 31.5 38 22V10L22 4z"
                fill="rgba(255,255,255,0.15)" stroke="rgba(255,255,255,0.6)" stroke-width="1.5"/>
            <path d="M16 22l4 4 8-8" stroke="#fff" stroke-width="2.5"
                stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
    </div>
    <div class="ws-brand">WonderShield</div>
    <div class="ws-title">Access Blocked</div>
    <div class="ws-message">' . esc_html($message) . '<br><br>' . $subline . '</div>
    <div class="ws-pills">
        <div class="ws-info-pill">
            <span class="pill-icon">🌐</span>
            <span class="pill-label">IP Address</span>
            <span class="pill-value">' . esc_html($ip) . '</span>
        </div>
        <div class="ws-info-pill">
            <span class="pill-icon">🕐</span>
            <span class="pill-label">Date &amp; Time</span>
            <span class="pill-value">' . esc_html($datetime) . '</span>
        </div>
    </div>
    <div class="ws-logged">This event has been logged</div>
</div>
</body></html>';
    exit;
}
// ============================================================
// PROTECTION: XML-RPC
// ============================================================
add_filter('xmlrpc_enabled', '__return_false');
add_action('init', function() {
    if (isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], 'xmlrpc.php') !== false) {
        $ip = ws_get_ip();
        ws_log($ip, 'xmlrpc_blocked', $_SERVER['REQUEST_URI'], $_SERVER['HTTP_USER_AGENT'] ?? '');
        status_header(403);
        ws_block_response('XML-RPC access is disabled on this site.');
    }
});
// ============================================================
// PROTECTION: USER ENUMERATION
// ============================================================
add_action('init', function() {
    if (!is_admin() && isset($_REQUEST['author'])) {
        $ip = ws_get_ip();
        ws_log($ip, 'enum_blocked', $_SERVER['REQUEST_URI'] ?? '/', $_SERVER['HTTP_USER_AGENT'] ?? '');
        wp_redirect(home_url('/'), 301);
        exit;
    }
});
// Disable REST API user endpoint
add_filter('rest_endpoints', function($endpoints) {
    if (isset($endpoints['/wp/v2/users'])) unset($endpoints['/wp/v2/users']);
    if (isset($endpoints['/wp/v2/users/(?P<id>[\d]+)'])) unset($endpoints['/wp/v2/users/(?P<id>[\d]+)']);
    return $endpoints;
});
// ============================================================
// PROTECTION: BAD USER AGENTS
// ============================================================
add_action('init', function() {
    $bad_agents = ['sqlmap', 'nikto', 'masscan', 'nmap', 'zgrab', 'dirbuster', 'gobuster',
                   'wfuzz', 'hydra', 'medusa', 'metasploit', 'havij', 'acunetix', 'nessus',
                   'openvas', 'w3af', 'skipfish', 'whatweb', 'nuclei'];
    $ua = strtolower($_SERVER['HTTP_USER_AGENT'] ?? '');
    if (!$ua) return;
    foreach ($bad_agents as $bad) {
        if (strpos($ua, $bad) !== false) {
            $ip = ws_get_ip();
            ws_log($ip, 'bad_agent_blocked', $_SERVER['REQUEST_URI'] ?? '/', $_SERVER['HTTP_USER_AGENT'] ?? '');
            status_header(403);
            ws_block_response('Automated scanning tools are not permitted on this site.');
        }
    }
});
// ============================================================
// PROTECTION: WP-LOGIN + WP-ADMIN RATE LIMITING
// ============================================================
// wp-login.php: runs early at init priority 1 — no session available yet, that's fine
// because wp-login.php hammering is always suspicious regardless of auth state
add_action('init', function() {
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    if (strpos($uri, 'wp-login.php') === false) return;
    $ip = ws_get_ip();
    $block = ws_is_blocked($ip);
    if ($block) {
        ws_log($ip, 'blocked_hit', $uri, $_SERVER['HTTP_USER_AGENT'] ?? '');
        status_header(403);
        $expires = strtotime($block->expires_at);
        $mins = max(1, round(($expires - time()) / 60));
        ws_block_response('Your IP has been temporarily blocked due to repeated failed login attempts.', $ip, $mins);
    }
    ws_log($ip, 'attempt', $uri, $_SERVER['HTTP_USER_AGENT'] ?? '');
    $attempts = ws_count_recent_attempts($ip, '%wp-login%', WS_ATTEMPT_WINDOW);
    if ($attempts >= WS_MAX_ATTEMPTS) {
        ws_block_ip($ip, 'wp-login brute force');
        status_header(429);
        ws_block_response('Too many failed login attempts. Your IP has been temporarily blocked.', $ip, (int)(WS_LOCKOUT_DURATION / 60));
    }
}, 1);
// wp-admin: runs later after auth cookies are set — skip logged-in users entirely
// Only flag unauthenticated requests hammering wp-admin (bots, scanners)
add_action('admin_init', function() {
    // Logged-in user browsing admin normally — do nothing
    if (is_user_logged_in()) return;
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    $ip = ws_get_ip();
    $block = ws_is_blocked($ip);
    if ($block) {
        ws_log($ip, 'blocked_hit', $uri, $_SERVER['HTTP_USER_AGENT'] ?? '');
        status_header(403);
        $expires = strtotime($block->expires_at);
        $mins = max(1, round(($expires - time()) / 60));
        ws_block_response('Your IP has been temporarily blocked due to suspicious activity.', $ip, $mins);
    }
    ws_log($ip, 'attempt', $uri, $_SERVER['HTTP_USER_AGENT'] ?? '');
    $attempts = ws_count_recent_attempts($ip, '%wp-admin%', WS_ATTEMPT_WINDOW);
    if ($attempts >= (WS_MAX_ATTEMPTS * 3)) {
        ws_block_ip($ip, 'wp-admin flood');
        status_header(429);
        ws_block_response('Too many requests detected. Your IP has been temporarily blocked.', $ip, (int)(WS_LOCKOUT_DURATION / 60));
    }
});
// ============================================================
// PROTECTION: FAILED LOGIN HOOK
// ============================================================
add_action('wp_login_failed', function($username) {
    $ip = ws_get_ip();
    ws_log($ip, 'login_failed', '/wp-login.php', $_SERVER['HTTP_USER_AGENT'] ?? '');
    $attempts = ws_count_recent_attempts($ip, '%wp-login%', WS_ATTEMPT_WINDOW);
    if ($attempts >= WS_MAX_ATTEMPTS) {
        ws_block_ip($ip, 'failed login threshold');
    }
});
// ============================================================
// ALERT: NEW ADMIN USER
// ============================================================
add_action('user_register', function($user_id) {
    $user = get_userdata($user_id);
    if ($user && in_array('administrator', $user->roles ?? [])) {
        $subject = '[WonderShield] New Admin User Created on ' . get_bloginfo('name');
        $body = "A new administrator account has been created.\n\n";
        $body .= "Username: " . $user->user_login . "\n";
        $body .= "Email: " . $user->user_email . "\n";
        $body .= "Time: " . current_time('mysql') . "\n\n";
        $body .= "If you did not create this account, log in immediately and remove it.\n";
        wp_mail(WS_NOTIFY_EMAIL, $subject, $body);
    }
});
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
        'data:image/svg+xml;base64,' . base64_encode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path fill="#00DCFF" d="M12 2L4 6v6c0 5.25 3.5 10.15 8 11.35C16.5 22.15 20 17.25 20 12V6L12 2z"/></svg>'),
        30
    );
});
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
        'blocked'         => ['🛡', '#ef4444', '#fee2e2'],
        'blocked_hit'     => ['🚫', '#f97316', '#ffedd5'],
        'attempt'         => ['⚡', '#eab308', '#fef9c3'],
        'login_failed'    => ['✗', '#ec4899', '#fce7f3'],
        'xmlrpc_blocked'  => ['🔒', '#8b5cf6', '#ede9fe'],
        'enum_blocked'    => ['👤', '#6366f1', '#e0e7ff'],
        'bad_agent_blocked'=> ['🤖', '#64748b', '#f1f5f9'],
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
    ?>
    <style>
    @import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&family=Dosis:wght@600&display=swap');
    #wondershield-wrap * { box-sizing: border-box; }
    #wondershield-wrap {
        font-family: 'Plus Jakarta Sans', sans-serif;
        background: #f4f3ff;
        min-height: 100vh;
        margin: -20px -20px 0 -2px;
        padding: 0;
        color: #0f0230;
    }
    /* HEADER - stays dark/purple */
    .ws-header {
        background: linear-gradient(135deg, #0f0230 0%, #2d0a6e 100%);
        border-radius: 0 0 24px 24px;
        padding: 32px 36px 28px;
        display: flex;
        align-items: center;
        gap: 16px;
        margin-bottom: 0;
    }
    .ws-logo {
        width: 48px;
        height: 48px;
        background: linear-gradient(135deg, #5600FF, #00DCFF);
        border-radius: 14px;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        box-shadow: 0 4px 16px rgba(86,0,255,0.4);
    }
    .ws-logo svg { width: 26px; height: 26px; }
    .ws-header-text h1 {
        font-size: 24px;
        font-weight: 800;
        margin: 0;
        background: linear-gradient(90deg, #fff 30%, #00DCFF);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
        padding: 0;
        line-height: 1.2;
    }
    .ws-header-text p {
        font-family: 'Dosis', sans-serif;
        font-size: 11px;
        letter-spacing: 0.14em;
        text-transform: uppercase;
        color: rgba(0,220,255,0.55);
        margin: 6px 0 0;
        padding: 0;
    }
    .ws-version {
        margin-left: auto;
        font-family: 'Dosis', sans-serif;
        font-size: 11px;
        letter-spacing: 0.1em;
        color: rgba(255,255,255,0.2);
        text-transform: uppercase;
        align-self: flex-start;
    }
    /* SECURITY LAYERS BANNER */
    .ws-layers {
        background: linear-gradient(135deg, #f8f7ff 0%, #f0eeff 100%);
        border: 1px solid #e8e4ff;
        border-radius: 16px;
        padding: 18px 24px;
        margin: 24px 32px 0;
        overflow: hidden;
    }
    .ws-layers-title {
        font-family: 'Dosis', sans-serif;
        font-size: 10px;
        font-weight: 600;
        letter-spacing: 0.14em;
        text-transform: uppercase;
        color: #8b7fb8;
        margin-bottom: 14px;
        text-align: center;
    }
    .ws-layers-flow {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 0;
        flex-wrap: nowrap;
    }
    .ws-pill {
        display: flex;
        align-items: center;
        gap: 5px;
        padding: 7px 13px;
        border-radius: 50px;
        font-family: 'Dosis', sans-serif;
        font-size: 11px;
        font-weight: 700;
        letter-spacing: 0.06em;
        text-transform: uppercase;
        white-space: nowrap;
        flex-shrink: 0;
    }
    .ws-pill.internet { background: #eeedf6; color: #4a4070; }
    .ws-pill.cf       { background: #fff0e6; color: #b85a0a; }
    .ws-pill.server   { background: #ede8ff; color: #5c3fb0; }
    .ws-pill.shield   { background: linear-gradient(135deg, #5600FF, #00DCFF); color: #fff; }
    .ws-pill.site     { background: #e6fff4; color: #0a7a4a; }
    .ws-connector {
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 3px;
        padding: 0 6px;
        flex-shrink: 0;
    }
    .ws-threat-tag {
        font-family: 'Dosis', sans-serif;
        font-size: 9px;
        font-weight: 700;
        letter-spacing: 0.05em;
        text-transform: uppercase;
        color: #cc0033;
        background: #fff0f3;
        border: 1px solid #ffc8d4;
        border-radius: 50px;
        padding: 2px 8px;
        white-space: nowrap;
    }
    .ws-pass-tag {
        font-family: 'Dosis', sans-serif;
        font-size: 9px;
        font-weight: 700;
        letter-spacing: 0.05em;
        text-transform: uppercase;
        color: #0a7a4a;
        background: #e6fff4;
        border: 1px solid #a3f0ce;
        border-radius: 50px;
        padding: 2px 8px;
        white-space: nowrap;
    }
    .ws-arrow-line {
        font-size: 13px;
        color: #c5bce8;
        line-height: 1;
    }
    .ws-arrow-line.pass { color: #00c87a; }
    /* BODY */
    .ws-body { padding: 24px 32px 32px; }
    .ws-notice {
        background: #f0fdf4;
        border: 1px solid #86efac;
        color: #166534;
        padding: 12px 18px;
        border-radius: 10px;
        margin-bottom: 24px;
        font-size: 14px;
    }
    /* STATS */
    .ws-stats {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
        gap: 14px;
        margin-bottom: 28px;
    }
    .ws-stat {
        background: #fff;
        border: 1px solid #e8e4ff;
        border-radius: 14px;
        padding: 18px 16px;
        position: relative;
        overflow: hidden;
    }
    .ws-stat::before {
        content: '';
        position: absolute;
        top: 0; left: 0; right: 0;
        height: 3px;
        background: linear-gradient(90deg, #5600FF, #00DCFF);
        border-radius: 14px 14px 0 0;
    }
    .ws-stat-value {
        font-size: 30px;
        font-weight: 800;
        color: #0f0230;
        line-height: 1;
        margin-bottom: 6px;
    }
    .ws-stat-value.danger { color: #dc2626; }
    .ws-stat-value.warning { color: #ea580c; }
    .ws-stat-value.teal { color: #0891b2; }
    .ws-stat-label {
        font-family: 'Dosis', sans-serif;
        font-size: 10px;
        letter-spacing: 0.1em;
        text-transform: uppercase;
        color: #8b7fb8;
    }
    /* PANELS */
    .ws-panel {
        background: #fff;
        border: 1px solid #e8e4ff;
        border-radius: 14px;
        margin-bottom: 22px;
        overflow: hidden;
    }
    .ws-panel-header {
        padding: 16px 22px;
        border-bottom: 1px solid #f0eeff;
        display: flex;
        align-items: center;
        justify-content: space-between;
    }
    .ws-panel-header h2 {
        font-family: 'Dosis', sans-serif;
        font-size: 11px;
        font-weight: 600;
        letter-spacing: 0.13em;
        text-transform: uppercase;
        color: #5600FF;
        margin: 0;
        padding: 0;
    }
    .ws-panel-header h2::before { content: none; }
    .ws-count-badge {
        background: #f0eeff;
        color: #5600FF;
        font-family: 'Dosis', sans-serif;
        font-size: 11px;
        font-weight: 600;
        padding: 3px 10px;
        border-radius: 20px;
        letter-spacing: 0.05em;
    }
    /* TABLES */
    .ws-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 13px;
    }
    .ws-table thead th {
        font-family: 'Dosis', sans-serif;
        font-size: 10px;
        letter-spacing: 0.12em;
        text-transform: uppercase;
        color: #8b7fb8;
        padding: 10px 20px;
        text-align: left;
        border-bottom: 1px solid #f0eeff;
        font-weight: 600;
        background: #faf9ff;
    }
    .ws-table tbody tr {
        border-bottom: 1px solid #f5f3ff;
        transition: background 0.1s;
    }
    .ws-table tbody tr:hover { background: #faf9ff; }
    .ws-table tbody tr:last-child { border-bottom: none; }
    .ws-table td {
        padding: 11px 20px;
        color: #0f0230;
        vertical-align: middle;
    }
    .ws-table code {
        background: #f0eeff;
        color: #5600FF;
        padding: 2px 7px;
        border-radius: 5px;
        font-size: 12px;
        font-family: 'Courier New', monospace;
    }
    .ws-badge {
        display: inline-block;
        padding: 3px 10px;
        border-radius: 20px;
        font-size: 11px;
        font-family: 'Dosis', sans-serif;
        font-weight: 600;
        letter-spacing: 0.05em;
    }
    .ws-unblock-btn {
        background: #fff0f0;
        color: #dc2626;
        border: 1px solid #fecaca;
        border-radius: 7px;
        padding: 5px 12px;
        font-size: 12px;
        font-family: 'Plus Jakarta Sans', sans-serif;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.15s;
    }
    .ws-unblock-btn:hover { background: #fee2e2; border-color: #f87171; }
    .ws-empty {
        padding: 32px 24px;
        text-align: center;
        color: #c4b8e8;
        font-size: 14px;
    }
    .ws-empty span { font-size: 24px; display: block; margin-bottom: 8px; }
    /* LOG */
    .ws-log-wrap { padding: 0; }
    #ws-log-table .ws-ip code { font-size: 11px; }
    #ws-log-table .ws-path code { font-size: 11px; max-width: 200px; display: inline-block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    #ws-log-table .ws-time { color: #a094c8; font-size: 12px; white-space: nowrap; }
    .ws-load-more {
        display: block;
        width: 100%;
        padding: 13px;
        background: #faf9ff;
        border: none;
        border-top: 1px solid #f0eeff;
        color: #8b7fb8;
        font-family: 'Dosis', sans-serif;
        font-size: 11px;
        letter-spacing: 0.1em;
        text-transform: uppercase;
        cursor: pointer;
        transition: all 0.15s;
    }
    .ws-load-more:hover { background: #f0eeff; color: #5600FF; }
    /* CONFIG GRID */
    .ws-config-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 14px;
        padding: 20px;
    }
    .ws-config-item {
        background: #faf9ff;
        border: 1px solid #ede9ff;
        border-radius: 10px;
        padding: 14px 16px;
    }
    .ws-config-label {
        font-family: 'Dosis', sans-serif;
        font-size: 10px;
        letter-spacing: 0.12em;
        text-transform: uppercase;
        color: #8b7fb8;
        margin-bottom: 5px;
    }
    .ws-config-value {
        font-size: 17px;
        font-weight: 700;
        color: #5600FF;
    }
    .ws-config-sub {
        font-size: 11px;
        color: #a094c8;
        margin-top: 2px;
    }
    .ws-footer {
        text-align: center;
        padding: 20px;
        font-family: 'Dosis', sans-serif;
        font-size: 11px;
        letter-spacing: 0.08em;
        color: #c4b8e8;
        text-transform: uppercase;
        border-top: 1px solid #ede9ff;
        margin-top: 8px;
    }
    .ws-footer a { color: #8b7fb8; text-decoration: none; }
    .ws-footer a:hover { color: #5600FF; }
    </style>
    <div id="wondershield-wrap">
        <!-- PURPLE HEADER -->
        <div class="ws-header">
            <div class="ws-logo">
                <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M12 2L4 6v6c0 5.25 3.5 10.15 8 11.35C16.5 22.15 20 17.25 20 12V6L12 2z" fill="white"/>
                    <path d="M9 12l2 2 4-4" stroke="#00DCFF" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </div>
            <div class="ws-header-text">
                <h1>WonderShield</h1>
                <p>Security Protection by Wonder Media</p>
            </div>
            <div class="ws-version">v<?php echo WS_VERSION; ?></div>
        </div>
        <!-- SECURITY LAYERS BANNER -->
        <div class="ws-layers">
            <div class="ws-layers-title">Your security layers — threats stopped before they reach your site</div>
            <div class="ws-layers-flow">
                <div class="ws-pill internet">🌐 Internet</div>
                <div class="ws-connector">
                    <div class="ws-threat-tag">Bots &amp; DDoS ✕</div>
                    <div class="ws-arrow-line">→</div>
                </div>
                <div class="ws-pill cf">☁️ Cloudflare</div>
                <div class="ws-connector">
                    <div class="ws-threat-tag">Attacks ✕</div>
                    <div class="ws-arrow-line">→</div>
                </div>
                <div class="ws-pill server">🖥️ Server</div>
                <div class="ws-connector">
                    <div class="ws-threat-tag">Brute Force ✕</div>
                    <div class="ws-arrow-line">→</div>
                </div>
                <div class="ws-pill shield">🛡️ WonderShield</div>
                <div class="ws-connector">
                    <div class="ws-pass-tag">Trusted Users ✓</div>
                    <div class="ws-arrow-line pass">→</div>
                </div>
                <div class="ws-pill site">✅ Your Site</div>
            </div>
        </div>
        <div class="ws-body">
            <?php if ($unblocked): ?>
            <div class="ws-notice">✓ IP address unblocked successfully.</div>
            <?php endif; ?>
            <!-- STATS -->
            <div class="ws-stats">
                <div class="ws-stat">
                    <div class="ws-stat-value danger"><?php echo $stats['blocked_24h']; ?></div>
                    <div class="ws-stat-label">Blocked 24h</div>
                </div>
                <div class="ws-stat">
                    <div class="ws-stat-value warning"><?php echo $stats['blocked_7d']; ?></div>
                    <div class="ws-stat-label">Blocked 7 Days</div>
                </div>
                <div class="ws-stat">
                    <div class="ws-stat-value"><?php echo $stats['blocked_30d']; ?></div>
                    <div class="ws-stat-label">Blocked 30 Days</div>
                </div>
                <div class="ws-stat">
                    <div class="ws-stat-value warning"><?php echo $stats['attempts_24h']; ?></div>
                    <div class="ws-stat-label">Attempts 24h</div>
                </div>
                <div class="ws-stat">
                    <div class="ws-stat-value teal"><?php echo $stats['active_blocks']; ?></div>
                    <div class="ws-stat-label">Active Blocks</div>
                </div>
                <div class="ws-stat">
                    <div class="ws-stat-value"><?php echo $stats['xmlrpc_blocked']; ?></div>
                    <div class="ws-stat-label">XML-RPC Blocked</div>
                </div>
                <div class="ws-stat">
                    <div class="ws-stat-value"><?php echo $stats['total_events']; ?></div>
                    <div class="ws-stat-label">Total Events</div>
                </div>
            </div>
            <!-- ACTIVE BLOCKS -->
            <div class="ws-panel">
                <div class="ws-panel-header">
                    <h2>🛡 Active Blocks</h2>
                    <span class="ws-count-badge"><?php echo count($active_blocks); ?> active</span>
                </div>
                <?php if (empty($active_blocks)): ?>
                    <div class="ws-empty"><span>✓</span> No active blocks. All clear.</div>
                <?php else: ?>
                <table class="ws-table">
                    <thead>
                        <tr>
                            <th>IP Address</th>
                            <th>Reason</th>
                            <th>Blocked At</th>
                            <th>Expires</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($active_blocks as $block): ?>
                        <tr>
                            <td><code><?php echo esc_html($block->ip); ?></code></td>
                            <td><span class="ws-badge" style="background:#fff0f0;color:#dc2626;"><?php echo esc_html($block->reason); ?></span></td>
                            <td style="color:#a094c8;font-size:12px;"><?php echo esc_html(human_time_diff(strtotime($block->blocked_at)) . ' ago'); ?></td>
                            <td style="color:#a094c8;font-size:12px;"><?php echo esc_html(human_time_diff(strtotime($block->expires_at))); ?></td>
                            <td>
                                <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="margin:0;">
                                    <?php wp_nonce_field('ws_unblock'); ?>
                                    <input type="hidden" name="action" value="ws_unblock">
                                    <input type="hidden" name="block_id" value="<?php echo (int)$block->id; ?>">
                                    <button type="submit" class="ws-unblock-btn">Unblock</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
            <!-- CONFIG INFO -->
            <div class="ws-panel">
                <div class="ws-panel-header">
                    <h2>⚙ Protection Rules</h2>
                </div>
                <div class="ws-config-grid">
                    <div class="ws-config-item">
                        <div class="ws-config-label">Login Attempts</div>
                        <div class="ws-config-value"><?php echo WS_MAX_ATTEMPTS; ?></div>
                        <div class="ws-config-sub">before lockout</div>
                    </div>
                    <div class="ws-config-item">
                        <div class="ws-config-label">Attempt Window</div>
                        <div class="ws-config-value"><?php echo WS_ATTEMPT_WINDOW / 60; ?>m</div>
                        <div class="ws-config-sub">rolling window</div>
                    </div>
                    <div class="ws-config-item">
                        <div class="ws-config-label">Lockout Duration</div>
                        <div class="ws-config-value"><?php echo WS_LOCKOUT_DURATION / 60; ?>m</div>
                        <div class="ws-config-sub">auto-expires</div>
                    </div>
                    <div class="ws-config-item">
                        <div class="ws-config-label">Admin Threshold</div>
                        <div class="ws-config-value"><?php echo WS_MAX_ATTEMPTS * 3; ?></div>
                        <div class="ws-config-sub">wp-admin requests</div>
                    </div>
                    <div class="ws-config-item">
                        <div class="ws-config-label">XML-RPC</div>
                        <div class="ws-config-value" style="color:#16a34a;">Blocked</div>
                        <div class="ws-config-sub">all requests</div>
                    </div>
                    <div class="ws-config-item">
                        <div class="ws-config-label">Log Retention</div>
                        <div class="ws-config-value"><?php echo WS_LOG_MAX_DAYS; ?>d</div>
                        <div class="ws-config-sub">max <?php echo number_format(WS_LOG_MAX_ROWS); ?> rows</div>
                    </div>
                    <div class="ws-config-item">
                        <div class="ws-config-label">User Enumeration</div>
                        <div class="ws-config-value" style="color:#16a34a;">Blocked</div>
                        <div class="ws-config-sub">?author= &amp; REST API</div>
                    </div>
                    <div class="ws-config-item">
                        <div class="ws-config-label">Notify Email</div>
                        <div class="ws-config-value" style="font-size:13px;color:#0891b2;"><?php echo esc_html(WS_NOTIFY_EMAIL); ?></div>
                        <div class="ws-config-sub">on block events</div>
                    </div>
                </div>
            </div>
            <!-- EVENT LOG -->
            <div class="ws-panel">
                <div class="ws-panel-header">
                    <h2>📋 Event Log</h2>
                    <span class="ws-count-badge"><?php echo number_format($stats['total_events']); ?> total events</span>
                </div>
                <div class="ws-log-wrap">
                    <table class="ws-table" id="ws-log-table">
                        <thead>
                            <tr>
                                <th>Event</th>
                                <th>IP</th>
                                <th>Path</th>
                                <th>When</th>
                            </tr>
                        </thead>
                        <tbody id="ws-log-tbody">
                            <?php foreach ($logs as $log) ws_render_log_row($log); ?>
                        </tbody>
                    </table>
                    <button class="ws-load-more" id="ws-load-more" data-offset="50">Load More Events ↓</button>
                </div>
            </div>
        </div>
        <div class="ws-footer">
            WonderShield <?php echo WS_VERSION; ?> &nbsp;·&nbsp; <a href="https://wondermedia.co.uk" target="_blank">Wonder Media Ltd</a> &nbsp;·&nbsp; Protecting <?php echo esc_html(get_bloginfo('name')); ?>
        </div>
    </div>
    <script>
    document.getElementById('ws-load-more').addEventListener('click', function() {
        var btn = this;
        var offset = parseInt(btn.dataset.offset);
        btn.textContent = 'Loading...';
        btn.disabled = true;
        var data = new FormData();
        data.append('action', 'ws_load_logs');
        data.append('offset', offset);
        data.append('_ajax_nonce', '<?php echo wp_create_nonce('ws_load_logs'); ?>');
        fetch(ajaxurl, { method: 'POST', body: data })
            .then(r => r.json())
            .then(function(res) {
                if (res.success) {
                    document.getElementById('ws-log-tbody').insertAdjacentHTML('beforeend', res.data.html);
                    btn.dataset.offset = offset + 50;
                    btn.textContent = 'Load More Events ↓';
                    btn.disabled = false;
                    if (res.data.count < 50) {
                        btn.textContent = 'No more events';
                        btn.disabled = true;
                    }
                }
            });
    });
    </script>
    <?php
}
