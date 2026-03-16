<?php
if (!defined('ABSPATH')) exit;

// ============================================================
// ACTIVATION / DEACTIVATION
// ============================================================
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
        KEY idx_created (created_at)
    ) $charset;";
    $sql2 = "CREATE TABLE IF NOT EXISTS " . WS_TABLE_BLOCKS . " (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        ip VARCHAR(45) NOT NULL,
        reason VARCHAR(100) NOT NULL,
        blocked_at DATETIME NOT NULL,
        expires_at DATETIME NOT NULL,
        `manual` TINYINT(1) NOT NULL DEFAULT 0,
        PRIMARY KEY (id),
        UNIQUE KEY idx_ip (ip),
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
    $wpdb->query("DELETE FROM " . WS_TABLE_BLOCKS . " WHERE expires_at < NOW() AND `manual` = 0");
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
    // Compare against PHP-calculated UTC time to avoid MySQL timezone mismatches
    $now = gmdate('Y-m-d H:i:s');
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM " . WS_TABLE_BLOCKS . " WHERE ip = %s AND expires_at > %s",
        $ip, $now
    ));
    return $row ? $row : false;
}
function ws_block_ip($ip, $reason = 'brute_force', $manual = 0) {
    global $wpdb;
    // Don't overwrite an existing unexpired block — preserves the original expires_at
    // so the countdown timer reflects actual remaining time rather than resetting to 30 min.
    if (!$manual && ws_is_blocked($ip)) return;
    // Store all times as UTC so ws_is_blocked comparisons are always consistent
    $expires = gmdate('Y-m-d H:i:s', time() + WS_LOCKOUT_DURATION);
    $wpdb->replace(WS_TABLE_BLOCKS, [
        'ip'         => $ip,
        'reason'     => $reason,
        'blocked_at' => gmdate('Y-m-d H:i:s'),
        'expires_at' => $expires,
        'manual'     => $manual,
    ], ['%s','%s','%s','%s','%d']);
    ws_log($ip, 'blocked', $_SERVER['REQUEST_URI'] ?? '/', $_SERVER['HTTP_USER_AGENT'] ?? '');
    // ws_send_notification($ip, $reason);
}
function ws_log($ip, $event_type, $path, $user_agent = '') {
    global $wpdb;
    $wpdb->insert(WS_TABLE_LOG, [
        'event_type' => $event_type,
        'ip'         => $ip,
        'path'       => substr($path, 0, 255),
        'user_agent' => substr($user_agent, 0, 500),
        'created_at' => current_time('mysql'),
    ], ['%s','%s','%s','%s','%s']);
}
function ws_send_notification($ip, $reason) {
    $subject = '[WonderShield] IP Blocked: ' . $ip;
    $body  = "WonderShield has blocked an IP address on " . get_bloginfo('name') . ".\n\n";
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
function ws_count_recent_events($ip, $event_type, $window_seconds) {
    global $wpdb;
    $since = date('Y-m-d H:i:s', time() - $window_seconds);
    return (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM " . WS_TABLE_LOG . "
         WHERE ip = %s AND event_type = %s AND created_at > %s",
        $ip, $event_type, $since
    ));
}

// ============================================================
// BLOCK RESPONSE PAGE
// ============================================================
function ws_block_response($message, $ip = null, $secs = null) {
    global $wpdb;
    $ip = $ip ?: ws_get_ip();
    $expires_ts = 0;

    // Primary: check active (non-expired) block
    $block = ws_is_blocked($ip);

    // Fallback: query without the expires filter in case of timezone mismatch
    if ( ! $block ) {
        $block = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM " . WS_TABLE_BLOCKS . " WHERE ip = %s ORDER BY expires_at DESC LIMIT 1",
            $ip
        ) );
    }

    // Debug data for HTML comment
    $debug_table_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . WS_TABLE_BLOCKS );
    $debug_last_error  = $wpdb->last_error;

    if ( $block ) {
        $expires_ts = strtotime( $block->expires_at . ' UTC' );
        $secs       = max( 0, $expires_ts - time() );
    } else {
        $secs = max( 0, (int)( $secs ?: 0 ) );
        if ( $secs > 0 ) {
            $expires_ts = time() + $secs;
        }
    }

    include WS_PLUGIN_DIR . 'templates/block-page.php';
    exit;
}
