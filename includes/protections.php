<?php
if (!defined('ABSPATH')) exit;

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
// PROTECTION: BAD PATH PROBES
// ============================================================
// Catches scanners probing for sensitive files (.env, .git, etc.)
// Any matching request gets a 404 immediately. After WS_PROBE_THRESHOLD
// hits from the same IP within WS_ATTEMPT_WINDOW, the IP is blocked.
// Add new patterns here and push a plugin update to deploy across all sites.
add_action('init', function() {
    $path = strtolower(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/');

    // Matched anywhere in the path — these strings never appear in real content URLs
    $probe_contains = [
        '/.env',           // .env files in any directory
        '/.git/',          // git repository contents
        '/.aws/',          // AWS credential files
        '/.ssh/',          // SSH key files
        'wp-config.php.',  // wp-config backup files (.bak, .old, etc.)
        '/.htpasswd',      // Apache password files
        '/etc/passwd',     // Unix password file
    ];
    // Matched only at the start of the path — avoids false-positives on URL slugs
    // e.g. /server-status is the Apache page, but /blog/server-status-update/ is a real post
    $probe_prefixes = [
        '/server-status',  // Apache server-status page
    ];

    $matched = false;
    foreach ($probe_contains as $pattern) {
        if (strpos($path, $pattern) !== false) {
            $matched = true;
            break;
        }
    }
    if (!$matched) {
        foreach ($probe_prefixes as $prefix) {
            if (strpos($path, $prefix) === 0) {
                $matched = true;
                break;
            }
        }
    }
    if (!$matched) return;

    $ip = ws_get_ip();
    $block = ws_is_blocked($ip);
    if ($block) {
        ws_log($ip, 'blocked_hit', $_SERVER['REQUEST_URI'] ?? '/', $_SERVER['HTTP_USER_AGENT'] ?? '');
        status_header(403);
        $expires = strtotime($block->expires_at);
        $mins = max(1, round(($expires - time()) / 60));
        ws_block_response('Your IP has been temporarily blocked due to suspicious activity.', $ip, $mins);
    }
    ws_log($ip, 'probe_blocked', $_SERVER['REQUEST_URI'] ?? '/', $_SERVER['HTTP_USER_AGENT'] ?? '');
    $probes = ws_count_recent_events($ip, 'probe_blocked', WS_ATTEMPT_WINDOW);
    if ($probes >= WS_PROBE_THRESHOLD) {
        ws_block_ip($ip, 'path probe scanner');
        status_header(403);
        ws_block_response('Your IP has been temporarily blocked due to suspicious activity.', $ip, (int)(WS_LOCKOUT_DURATION / 60));
    }
    status_header(404);
    exit;
}, 1);

// ============================================================
// PROTECTION: WP-LOGIN + WP-ADMIN RATE LIMITING
// ============================================================
// wp-login.php: runs early at init priority 1
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
// wp-admin: skip logged-in users entirely
add_action('admin_init', function() {
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
        $body  = "A new administrator account has been created.\n\n";
        $body .= "Username: " . $user->user_login . "\n";
        $body .= "Email: " . $user->user_email . "\n";
        $body .= "Time: " . current_time('mysql') . "\n\n";
        $body .= "If you did not create this account, log in immediately and remove it.\n";
        wp_mail(WS_NOTIFY_EMAIL, $subject, $body);
    }
});
