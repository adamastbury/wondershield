<?php
if (!defined('ABSPATH')) exit;

define('WS_CENTRAL_URL',                 'https://shield.wondermedia.co.uk');
define('WS_CENTRAL_REGISTRATION_SECRET', '226e5d930720b2e01311ae8d18ecb572f2284a5d59f2ff43930cebcbd5f463df');

// ============================================================
// CRON INTERVAL
// ============================================================
add_filter('cron_schedules', function ($s) {
    if (!isset($s['ws_five_minutes'])) {
        $s['ws_five_minutes'] = ['interval' => 300, 'display' => 'Every 5 minutes'];
    }
    return $s;
});

// ============================================================
// REGISTRATION / BOOT
// ============================================================

/**
 * On first load (no site_id stored), generate a UUID, register with Central
 * using the REGISTRATION_SECRET, save the returned api_key, then schedule crons.
 * On subsequent loads, do nothing — crons handle the rest.
 */
add_action('plugins_loaded', 'ws_central_maybe_register', 20);
function ws_central_maybe_register() {
    if (get_option('ws_central_site_id')) {
        return; // Already registered
    }

    $site_id = wp_generate_uuid4();
    update_option('ws_central_site_id', $site_id, false);

    $response = ws_central_send_heartbeat(true); // blocking, uses REGISTRATION_SECRET
    if ($response && !empty($response['api_key'])) {
        update_option('ws_central_api_key', $response['api_key'], false);
    }

    // Schedule crons now (activation hook may have run before central.php existed)
    if (!wp_next_scheduled('ws_central_heartbeat')) {
        wp_schedule_event(time(), 'ws_five_minutes', 'ws_central_heartbeat');
    }
    if (!wp_next_scheduled('ws_central_check')) {
        wp_schedule_event(time(), 'ws_five_minutes', 'ws_central_check');
    }
}

// ============================================================
// HEARTBEAT CRON
// ============================================================
add_action('ws_central_heartbeat', 'ws_central_run_heartbeat');
function ws_central_run_heartbeat() {
    $response = ws_central_send_heartbeat(false);
    if ($response && !empty($response['api_key'])) {
        // Server may re-issue key; keep it fresh
        update_option('ws_central_api_key', $response['api_key'], false);
    }
    if ($response && !empty($response['force_update']) && $response['force_update'] === true) {
        ws_central_force_update(
            $response['command_id'] ?? null,
            $response['target_version'] ?? null
        );
    }
}

/**
 * Build and send the heartbeat payload.
 *
 * @param bool $blocking Whether to wait for the response.
 * @return array|null Decoded response body, or null on failure.
 */
function ws_central_send_heartbeat($blocking = false) {
    $site_id = get_option('ws_central_site_id');
    if (!$site_id) return null;

    $api_key  = get_option('ws_central_api_key');
    $is_first = empty($api_key);
    $token    = $is_first ? WS_CENTRAL_REGISTRATION_SECRET : $api_key;

    $stats = function_exists('ws_get_stats') ? ws_get_stats() : [];

    $body = [
        'site_id'        => $site_id,
        'domain'         => parse_url(home_url(), PHP_URL_HOST),
        'site_url'       => home_url(),
        'site_name'      => get_bloginfo('name'),
        'plugin_version' => WS_VERSION,
        'wp_version'     => get_bloginfo('version'),
        'php_version'    => PHP_VERSION,
        'stats'          => [
            'blocked_24h'    => $stats['blocked_24h']    ?? 0,
            'blocked_7d'     => $stats['blocked_7d']     ?? 0,
            'blocked_30d'    => $stats['blocked_30d']    ?? 0,
            'attempts_24h'   => $stats['attempts_24h']   ?? 0,
            'xmlrpc_blocked' => $stats['xmlrpc_blocked'] ?? 0,
            'probes_blocked' => $stats['probes_blocked'] ?? 0,
            'total_events'   => $stats['total_events']   ?? 0,
            'active_blocks'  => $stats['active_blocks']  ?? 0,
        ],
    ];

    // Attach any pending update report
    $pending = get_transient('ws_central_pending_update_report');
    if ($pending) {
        $body['update_report'] = $pending;
    }

    try {
        $result = wp_remote_post(WS_CENTRAL_URL . '/api/heartbeat', [
            'timeout'  => 10,
            'blocking' => $blocking,
            'headers'  => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $token,
            ],
            'body' => wp_json_encode($body),
        ]);

        if (is_wp_error($result)) {
            error_log('[WonderShield Central] Heartbeat error: ' . $result->get_error_message());
            return null;
        }

        if (!$blocking) {
            return null;
        }

        $code = wp_remote_retrieve_response_code($result);
        if ($code < 200 || $code >= 300) {
            error_log('[WonderShield Central] Heartbeat HTTP ' . $code);
            return null;
        }

        if ($pending) {
            delete_transient('ws_central_pending_update_report');
        }

        $decoded = json_decode(wp_remote_retrieve_body($result), true);
        return is_array($decoded) ? $decoded : null;

    } catch (\Throwable $e) {
        error_log('[WonderShield Central] Heartbeat exception: ' . $e->getMessage());
        return null;
    }
}

// ============================================================
// CHECK (poll for commands) — called by cron or REST trigger
// ============================================================
add_action('ws_central_check', 'ws_central_check_for_update');
function ws_central_check_for_update() {
    $site_id = get_option('ws_central_site_id');
    $api_key = get_option('ws_central_api_key');
    if (!$site_id || !$api_key) return;

    try {
        $result = wp_remote_get(WS_CENTRAL_URL . '/api/sites/' . rawurlencode($site_id) . '/check', [
            'timeout'  => 10,
            'blocking' => true,
            'headers'  => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
            ],
        ]);

        if (is_wp_error($result)) {
            error_log('[WonderShield Central] Check error: ' . $result->get_error_message());
            return;
        }

        $code = wp_remote_retrieve_response_code($result);
        if ($code < 200 || $code >= 300) {
            error_log('[WonderShield Central] Check HTTP ' . $code);
            return;
        }

        $data = json_decode(wp_remote_retrieve_body($result), true);
        if (is_array($data) && !empty($data['force_update']) && $data['force_update'] === true) {
            ws_central_force_update(
                $data['command_id'] ?? null,
                $data['target_version'] ?? null
            );
        }

    } catch (\Throwable $e) {
        error_log('[WonderShield Central] Check exception: ' . $e->getMessage());
    }
}

// ============================================================
// EVENT QUEUE
// ============================================================

/**
 * Called via do_action('ws_event', $type, $ip, $path, $user_agent) from ws_log().
 */
add_action('ws_event', 'ws_central_queue_event', 10, 4);
function ws_central_queue_event($event_type, $ip, $path, $user_agent) {
    $allowed = ['blocked', 'login_failed', 'probe_blocked', 'bad_agent_blocked', 'xmlrpc_blocked', 'enum_blocked'];
    if (!in_array($event_type, $allowed, true)) return;

    $queue = get_option('ws_central_event_queue', []);
    if (!is_array($queue)) $queue = [];

    $queue[] = [
        'event_type' => $event_type,
        'ip'         => $ip,
        'path'       => $path,
        'user_agent' => $user_agent,
        'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
    ];

    // Cap at 50
    if (count($queue) > 50) {
        $queue = array_slice($queue, -50);
    }

    update_option('ws_central_event_queue', $queue, false);
}

/**
 * Flush event queue on shutdown, at most once per 60 seconds.
 */
add_action('shutdown', 'ws_central_flush_events');
function ws_central_flush_events() {
    $queue = get_option('ws_central_event_queue', []);
    if (empty($queue)) return;

    $last_push = (int) get_option('ws_central_last_event_push', 0);
    if ((time() - $last_push) < 60) return;

    $site_id = get_option('ws_central_site_id');
    $api_key = get_option('ws_central_api_key');
    if (!$site_id || !$api_key) return;

    update_option('ws_central_last_event_push', time(), false);
    update_option('ws_central_event_queue', [], false);

    try {
        $result = wp_remote_post(WS_CENTRAL_URL . '/api/event', [
            'timeout'  => 10,
            'blocking' => false,
            'headers'  => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
            ],
            'body' => wp_json_encode([
                'site_id' => $site_id,
                'events'  => $queue,
            ]),
        ]);

        if (is_wp_error($result)) {
            error_log('[WonderShield Central] Event push error: ' . $result->get_error_message());
        }

    } catch (\Throwable $e) {
        error_log('[WonderShield Central] Event push exception: ' . $e->getMessage());
    }
}

// ============================================================
// FORCE UPDATE
// ============================================================
function ws_central_force_update($command_id, $target_version) {
    $site_id     = get_option('ws_central_site_id');
    $api_key     = get_option('ws_central_api_key');
    $old_version = WS_VERSION;

    ws_central_send_update_report($site_id, $api_key, $command_id, 'updating', $old_version, null, null);

    try {
        if (!class_exists('WP_Upgrader')) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        }
        if (!class_exists('WP_Upgrader_Skin')) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        }

        $upgrader = new Plugin_Upgrader(new WP_Upgrader_Skin());
        $result   = $upgrader->upgrade('wondershield/wondershield.php');

        if ($result === true) {
            // Reload to pick up new version constant
            $new_version = null;
            $plugin_data_file = WP_PLUGIN_DIR . '/wondershield/wondershield.php';
            if (file_exists($plugin_data_file)) {
                $plugin_data = get_plugin_data($plugin_data_file, false, false);
                $new_version = $plugin_data['Version'] ?? null;
            }
            ws_central_send_update_report($site_id, $api_key, $command_id, 'success', $old_version, $new_version, null);
        } else {
            $error_msg = is_wp_error($result) ? $result->get_error_message() : 'Unknown upgrade error';
            ws_central_send_update_report($site_id, $api_key, $command_id, 'failed', $old_version, null, $error_msg);
        }

    } catch (\Throwable $e) {
        ws_central_send_update_report($site_id, $api_key, $command_id, 'failed', $old_version, null, $e->getMessage());
    }
}

function ws_central_send_update_report($site_id, $api_key, $command_id, $status, $old_version, $new_version, $error_message) {
    $body = [
        'site_id'       => $site_id,
        'command_id'    => $command_id,
        'status'        => $status,
        'old_version'   => $old_version,
        'new_version'   => $new_version,
        'error_message' => $error_message,
    ];

    try {
        $result = wp_remote_post(WS_CENTRAL_URL . '/api/update/report', [
            'timeout'  => 10,
            'blocking' => true,
            'headers'  => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
            ],
            'body' => wp_json_encode($body),
        ]);

        if (is_wp_error($result)) {
            error_log('[WonderShield Central] Update report error: ' . $result->get_error_message());
            set_transient('ws_central_pending_update_report', $body, DAY_IN_SECONDS);
            return;
        }

        $code = wp_remote_retrieve_response_code($result);
        if ($code < 200 || $code >= 300) {
            error_log('[WonderShield Central] Update report HTTP ' . $code);
            set_transient('ws_central_pending_update_report', $body, DAY_IN_SECONDS);
        }

    } catch (\Throwable $e) {
        error_log('[WonderShield Central] Update report exception: ' . $e->getMessage());
        set_transient('ws_central_pending_update_report', $body, DAY_IN_SECONDS);
    }
}
