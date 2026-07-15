<?php
if (!defined('ABSPATH')) exit;

define('WS_CENTRAL_URL', 'https://shield.wondermedia.co.uk');
// No shared registration secret. Each site is connected by pasting a per-site API
// key (issued from WonderShield Central) into Settings → API Key. The key is the
// sole credential; the plugin does nothing until one is entered.

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
 * Boot: keep the crons scheduled, and — if an API key has been entered — validate
 * it against Central and learn our canonical site_id from the response.
 *
 * There is NO self-registration. With no API key the plugin stays inert until an
 * admin pastes a key on Settings → API Key. A rejected key surfaces an error but is
 * never wiped automatically (it is the admin's credential, not a self-issued one).
 */
add_action('plugins_loaded', 'ws_central_maybe_register', 20);
function ws_central_maybe_register() {
    // Ensure crons are scheduled and not stuck overdue.
    // wp_next_scheduled() returns false if missing, or a timestamp if scheduled.
    // We reschedule if missing OR if the next run is more than 10 minutes overdue.
    $hb_next = wp_next_scheduled('ws_central_heartbeat');
    if (!$hb_next || (time() - $hb_next) > 600) {
        wp_clear_scheduled_hook('ws_central_heartbeat');
        wp_schedule_event(time(), 'ws_five_minutes', 'ws_central_heartbeat');
    }
    $chk_next = wp_next_scheduled('ws_central_check');
    if (!$chk_next || (time() - $chk_next) > 600) {
        wp_clear_scheduled_hook('ws_central_check');
        wp_schedule_event(time(), 'ws_five_minutes', 'ws_central_check');
    }

    $api_key = get_option('ws_central_api_key');
    if (empty($api_key)) {
        // Not configured — no key means no calls to Central.
        return;
    }

    // We have a key. Validate it (and learn our site_id) if we don't have a site_id
    // yet, or haven't confirmed the connection in the last 10 minutes.
    $site_id      = get_option('ws_central_site_id');
    $validated_at = (int) get_option('ws_central_validated_at', 0);
    if (empty($site_id) || (time() - $validated_at) > 600) {
        $response = ws_central_send_heartbeat(true);
        if (is_array($response)) {
            update_option('ws_central_validated_at', time(), false);
            // Central returns our canonical site_id; adopt it (drives the /check + /event calls).
            if (!empty($response['site_id'])) {
                update_option('ws_central_site_id', $response['site_id'], false);
            }
            ws_central_apply_block_ops($response);
            // Act on force_update here too, since the cron heartbeat may not be running.
            if (!empty($response['force_update']) && $response['force_update'] === true) {
                ws_central_force_update(
                    $response['command_id'] ?? null,
                    $response['target_version'] ?? null
                );
            }
        }
        // On false (401 — bad key) or null (network) the error is already recorded in
        // ws_central_last_error; we leave the key untouched so the admin can correct it.
    }
}

/**
 * Clear the connection's validation state so the stored key is re-checked on the
 * next load. Does NOT delete the api_key — that is entered/managed by the admin.
 */
function ws_central_reset() {
    delete_option('ws_central_validated_at');
    delete_transient('ws_central_pending_update_report');
}

/**
 * Apply cross-site block reconciliation ops returned by Central in a heartbeat
 * response. Central diffs its desired global block set against what we reported and
 * tells us exactly which IPs to add (manual, permanent) or remove. This is what
 * makes global blocks self-healing: if we were offline when Central pushed a block,
 * we converge here on our next check-in.
 */
function ws_central_apply_block_ops($response) {
    if (empty($response['block_ops']) || !is_array($response['block_ops'])) return;
    global $wpdb;
    $ops = $response['block_ops'];

    if (!empty($ops['add']) && is_array($ops['add'])) {
        $now = gmdate('Y-m-d H:i:s');
        foreach ($ops['add'] as $b) {
            $ip = isset($b['ip']) ? sanitize_text_field($b['ip']) : '';
            if ($ip === '' || !filter_var(explode('/', $ip)[0], FILTER_VALIDATE_IP)) continue;
            $reason = isset($b['reason']) ? sanitize_text_field($b['reason']) : 'Blocked via Central';
            $wpdb->replace(WS_TABLE_BLOCKS, [
                'ip'         => $ip,
                'reason'     => $reason,
                'blocked_at' => $now,
                'expires_at' => '9999-12-31 23:59:59',
                'manual'     => 1,
            ], ['%s', '%s', '%s', '%s', '%d']);
        }
    }

    if (!empty($ops['remove']) && is_array($ops['remove'])) {
        foreach ($ops['remove'] as $ip) {
            $ip = sanitize_text_field($ip);
            if ($ip === '') continue;
            $wpdb->delete(WS_TABLE_BLOCKS, ['ip' => $ip], ['%s']);
        }
    }
}

// ============================================================
// HEARTBEAT CRON
// ============================================================
add_action('ws_central_heartbeat', 'ws_central_run_heartbeat');
function ws_central_run_heartbeat() {
    $response = ws_central_send_heartbeat(true); // blocking so we can detect auth failures
    if (!is_array($response)) {
        // false (401 — invalid key) or null (network/error). The error is recorded in
        // ws_central_last_error; never wipe the admin-entered key automatically.
        return;
    }
    update_option('ws_central_validated_at', time(), false);
    // Learn/confirm our canonical site_id from Central.
    if (!empty($response['site_id'])) {
        update_option('ws_central_site_id', $response['site_id'], false);
    }
    ws_central_apply_block_ops($response);
    if (!empty($response['force_update']) && $response['force_update'] === true) {
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
    global $wpdb;

    $api_key = get_option('ws_central_api_key');
    if (empty($api_key)) return null; // no key, no heartbeat
    $token = $api_key;

    // site_id may be empty on the very first heartbeat after a key is pasted;
    // Central identifies us by the key and returns our canonical site_id.
    $site_id = get_option('ws_central_site_id');

    $stats = function_exists('ws_get_stats') ? ws_get_stats() : [];

    $body = [
        'site_id'        => $site_id ?: '',
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

    // Attach active blocks (for Central blocks management page)
    // Use gmdate() for UTC comparison — expires_at is stored in UTC (via gmdate in ws_block_ip),
    // so NOW() would be wrong on servers in non-UTC timezones (e.g. BST).
    $now_utc = gmdate('Y-m-d H:i:s');
    $active_blocks_rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT ip, reason, blocked_at, expires_at, manual FROM " . WS_TABLE_BLOCKS . " WHERE expires_at > %s OR manual = 1",
            $now_utc
        ),
        ARRAY_A
    );
    if (!empty($active_blocks_rows)) {
        $body['active_blocks_detail'] = array_map(function($row) {
            return [
                'ip'         => $row['ip'],
                'reason'     => $row['reason'],
                'blocked_at' => $row['blocked_at'],
                'expires_at' => $row['expires_at'],
                'manual'     => (int) $row['manual'],
            ];
        }, $active_blocks_rows);
    } else {
        $body['active_blocks_detail'] = [];
    }

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
            $msg = 'WP error: ' . $result->get_error_message();
            error_log('[WonderShield Central] Heartbeat error: ' . $msg);
            update_option('ws_central_last_error', $msg, false);
            return null;
        }

        if (!$blocking) {
            return null;
        }

        $code = wp_remote_retrieve_response_code($result);
        if ($code === 401) {
            $msg = 'HTTP 401 — API key rejected. Check the key on Settings → API Key.';
            error_log('[WonderShield Central] Heartbeat 401 — API key rejected');
            update_option('ws_central_last_error', $msg, false);
            return false;
        }
        if ($code < 200 || $code >= 300) {
            $body_preview = substr(wp_remote_retrieve_body($result), 0, 200);
            $msg = 'HTTP ' . $code . ' — ' . $body_preview;
            error_log('[WonderShield Central] Heartbeat HTTP ' . $code);
            update_option('ws_central_last_error', $msg, false);
            return null;
        }

        if ($pending) {
            delete_transient('ws_central_pending_update_report');
        }

        $decoded = json_decode(wp_remote_retrieve_body($result), true);
        if (!is_array($decoded)) {
            $msg = 'Invalid JSON response from central';
            update_option('ws_central_last_error', $msg, false);
            return null;
        }

        // Clear any previous error on success
        delete_option('ws_central_last_error');
        return $decoded;

    } catch (\Throwable $e) {
        $msg = 'Exception: ' . $e->getMessage();
        error_log('[WonderShield Central] Heartbeat exception: ' . $msg);
        update_option('ws_central_last_error', $msg, false);
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
add_action('ws_event', 'ws_central_queue_event', 10, 5);
function ws_central_queue_event($event_type, $ip, $path, $user_agent, $country = '') {
    $allowed = ['attempt', 'blocked', 'login_failed', 'probe_blocked', 'bad_agent_blocked', 'xmlrpc_blocked', 'enum_blocked'];
    if (!in_array($event_type, $allowed, true)) return;

    $queue = get_option('ws_central_event_queue', []);
    if (!is_array($queue)) $queue = [];

    $queue[] = [
        'event_type' => $event_type,
        'ip'         => $ip,
        'path'       => $path,
        'user_agent' => $user_agent,
        'country'    => $country,
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
    global $wpdb;

    $last_push = (int) get_option('ws_central_last_event_push', 0);
    if ((time() - $last_push) < 60) return;

    $queue = get_option('ws_central_event_queue', []);

    $site_id = get_option('ws_central_site_id');
    $api_key = get_option('ws_central_api_key');
    if (!$site_id || !$api_key) return;

    // Advance rate-limit timer now so concurrent requests don't double-send.
    update_option('ws_central_last_event_push', time(), false);
    // Clear queue optimistically; restored below if the push fails.
    update_option('ws_central_event_queue', [], false);

    // Include active blocks so Central stays in sync every ~60 seconds
    $now_utc = gmdate('Y-m-d H:i:s');
    $active_blocks_rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT ip, reason, blocked_at, expires_at, manual FROM " . WS_TABLE_BLOCKS . " WHERE expires_at > %s OR manual = 1",
            $now_utc
        ),
        ARRAY_A
    );
    $active_blocks_detail = array_map(function($row) {
        return [
            'ip'         => $row['ip'],
            'reason'     => $row['reason'],
            'blocked_at' => $row['blocked_at'],
            'expires_at' => $row['expires_at'],
            'manual'     => (int) $row['manual'],
        ];
    }, $active_blocks_rows ?: []);

    try {
        // Use blocking:true so we can detect failures and restore the queue.
        // The shutdown hook fires after the browser response is already sent,
        // so the 5-second timeout does not affect the user-visible page load.
        $result = wp_remote_post(WS_CENTRAL_URL . '/api/event', [
            'timeout'  => 5,
            'blocking' => true,
            'headers'  => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
            ],
            'body' => wp_json_encode([
                'site_id'              => $site_id,
                'events'               => $queue,
                'active_blocks_detail' => $active_blocks_detail,
            ]),
        ]);

        $failed = is_wp_error($result);
        $http_code = $failed ? 0 : (int) wp_remote_retrieve_response_code($result);

        if ($failed || $http_code >= 400) {
            $err_msg = $failed ? $result->get_error_message() : "HTTP $http_code";
            error_log('[WonderShield Central] Event push failed (' . $err_msg . ') — restoring queue for retry');

            // Restore the queue so events are not permanently lost.
            // Merge with anything queued since we cleared it above.
            $current = get_option('ws_central_event_queue', []);
            if (!is_array($current)) $current = [];
            $restored = array_merge($queue, $current);
            if (count($restored) > 50) $restored = array_slice($restored, -50);
            update_option('ws_central_event_queue', $restored, false);
            // Reset timer so the retry can fire on the next request
            update_option('ws_central_last_event_push', 0, false);
        }

    } catch (\Throwable $e) {
        error_log('[WonderShield Central] Event push exception: ' . $e->getMessage());
        // The queue was cleared optimistically before the POST — restore it so a
        // transient exception doesn't silently drop up to 50 events (incl. 'blocked').
        $current = get_option('ws_central_event_queue', []);
        if (!is_array($current)) $current = [];
        $restored = array_merge($queue, $current);
        if (count($restored) > 50) $restored = array_slice($restored, -50);
        update_option('ws_central_event_queue', $restored, false);
        update_option('ws_central_last_event_push', 0, false);
    }
}

// ============================================================
// REST ENDPOINTS (trigger + unblock)
// Called directly by WonderShield Central.
// ============================================================
add_action('rest_api_init', function() {
    register_rest_route('wondershield/v1', '/health', [
        'methods'             => 'GET',
        'callback'            => 'ws_central_health_check',
        'permission_callback' => '__return_true',
    ]);
    register_rest_route('wondershield/v1', '/trigger', [
        'methods'             => 'POST',
        'callback'            => 'ws_central_handle_trigger',
        'permission_callback' => '__return_true',
    ]);
    register_rest_route('wondershield/v1', '/unblock', [
        'methods'             => 'POST',
        'callback'            => 'ws_central_handle_unblock',
        'permission_callback' => '__return_true',
    ]);
    register_rest_route('wondershield/v1', '/block', [
        'methods'             => 'POST',
        'callback'            => 'ws_central_handle_block',
        'permission_callback' => '__return_true',
    ]);
});

function ws_central_health_check(WP_REST_Request $request) {
    // Require the per-site API key, same as /trigger, /block, /unblock — no longer
    // an unauthenticated liveness/DB-status leak.
    $auth    = $request->get_header('Authorization');
    $token   = preg_replace('/^Bearer\s+/i', '', $auth ?? '');
    $api_key = get_option('ws_central_api_key');
    if (empty($api_key) || !hash_equals($api_key, $token)) {
        return new WP_REST_Response(['error' => 'Unauthorized'], 401);
    }

    global $wpdb;
    $ok = $wpdb->get_var("SELECT 1") === '1';
    return new WP_REST_Response(['ok' => $ok], $ok ? 200 : 503);
}

function ws_central_handle_trigger(WP_REST_Request $request) {
    $auth    = $request->get_header('Authorization');
    $token   = preg_replace('/^Bearer\s+/i', '', $auth ?? '');
    $api_key = get_option('ws_central_api_key');

    if (empty($api_key) || !hash_equals($api_key, $token)) {
        return new WP_REST_Response(['error' => 'Unauthorized'], 401);
    }

    ws_central_check_for_update();
    return new WP_REST_Response(['ok' => true], 200);
}

function ws_central_handle_block(WP_REST_Request $request) {
    $auth    = $request->get_header('Authorization');
    $token   = preg_replace('/^Bearer\s+/i', '', $auth ?? '');
    $api_key = get_option('ws_central_api_key');

    if (empty($api_key) || !hash_equals($api_key, $token)) {
        return new WP_REST_Response(['error' => 'Unauthorized'], 401);
    }

    $ip     = sanitize_text_field($request->get_param('ip') ?? '');
    $reason = sanitize_text_field($request->get_param('reason') ?? 'Manually blocked via Central');

    if (empty($ip)) {
        return new WP_REST_Response(['error' => 'Missing ip'], 400);
    }

    global $wpdb;
    $wpdb->replace(
        WS_TABLE_BLOCKS,
        [
            'ip'         => $ip,
            'reason'     => $reason,
            'blocked_at' => gmdate('Y-m-d H:i:s'), // UTC — consistent with ws_block_ip and the read queries
            'expires_at' => '9999-12-31 23:59:59',
            'manual'     => 1,
        ],
        ['%s', '%s', '%s', '%s', '%d']
    );

    return new WP_REST_Response(['ok' => true, 'ip' => $ip], 200);
}

function ws_central_handle_unblock(WP_REST_Request $request) {
    $auth    = $request->get_header('Authorization');
    $token   = preg_replace('/^Bearer\s+/i', '', $auth ?? '');
    $api_key = get_option('ws_central_api_key');

    if (empty($api_key) || !hash_equals($api_key, $token)) {
        return new WP_REST_Response(['error' => 'Unauthorized'], 401);
    }

    $ip = sanitize_text_field($request->get_param('ip') ?? '');
    if (empty($ip)) {
        return new WP_REST_Response(['error' => 'Missing ip'], 400);
    }

    global $wpdb;
    $wpdb->delete(WS_TABLE_BLOCKS, ['ip' => $ip], ['%s']);

    return new WP_REST_Response(['ok' => true, 'ip' => $ip], 200);
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
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/misc.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

        // Plugin_Upgrader::upgrade() checks the update_plugins transient and
        // silently returns false if our plugin isn't in response[]. This happens
        // when the GitHub release cache still shows the current version as latest.
        //
        // Fix: clear the GitHub cache so our updater hook fetches fresh, then
        // ensure the plugin is in checked[] so the hook actually evaluates it
        // (the hook bails early when checked is empty).
        delete_site_transient('wondershield_github_release');
        $update_transient = get_site_transient('update_plugins');
        if ( ! is_object( $update_transient ) ) $update_transient = new stdClass();
        if ( ! isset( $update_transient->checked ) )  $update_transient->checked  = [];
        if ( ! isset( $update_transient->response ) )  $update_transient->response  = [];
        if ( ! isset( $update_transient->no_update ) ) $update_transient->no_update = [];
        $update_transient->checked['wondershield/wondershield.php'] = WS_VERSION;
        unset( $update_transient->no_update['wondershield/wondershield.php'] );
        set_site_transient( 'update_plugins', $update_transient );

        $upgrader = new Plugin_Upgrader(new Automatic_Upgrader_Skin());
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
