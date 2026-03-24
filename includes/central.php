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
function ws_central_maybe_register( $depth = 0 ) {
    if ( $depth > 1 ) return; // Recursion guard

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

    $site_id = get_option('ws_central_site_id');
    $api_key = get_option('ws_central_api_key');

    if ($site_id && $api_key) {
        // Credentials exist — but validate them if not confirmed recently.
        // This catches stale credentials after a DB clear without relying on WP cron,
        // since WP cron is disabled and only fires when Cloudflare pings wp-cron.php,
        // which only happens for sites already in the central DB.
        $validated_at = (int) get_option('ws_central_validated_at', 0);
        if ((time() - $validated_at) > 600) {
            $response = ws_central_send_heartbeat(true);
            if ($response === false) {
                // 401 — stale credentials, re-register immediately
                ws_central_reset();
                ws_central_maybe_register( $depth + 1 );
            } elseif (is_array($response)) {
                update_option('ws_central_validated_at', time(), false);
                if (!empty($response['api_key'])) {
                    update_option('ws_central_api_key', $response['api_key'], false);
                }
                // Act on force_update even when triggered from plugins_loaded,
                // since the cron heartbeat action may not be running (e.g. stuck event).
                if (!empty($response['force_update']) && $response['force_update'] === true) {
                    ws_central_force_update(
                        $response['command_id'] ?? null,
                        $response['target_version'] ?? null
                    );
                }
            }
        }
        return;
    }

    // No api_key — either fresh install or after a reset.
    // If site_id exists, reuse it so the central updates the existing record
    // rather than inserting a new one (which would conflict on the domain UNIQUE constraint).
    if (!$site_id) {
        $site_id = wp_generate_uuid4();
        update_option('ws_central_site_id', $site_id, false);
    }

    $response = ws_central_send_heartbeat(true); // blocking, uses REGISTRATION_SECRET
    if ($response && !empty($response['api_key'])) {
        update_option('ws_central_api_key', $response['api_key'], false);
        update_option('ws_central_validated_at', time(), false);
        // Central may return a corrected site_id if our UUID drifted (domain conflict resolved)
        if (!empty($response['site_id'])) {
            update_option('ws_central_site_id', $response['site_id'], false);
        }
    }
    // On failure, leave site_id in place so the next page load retries
    // with the same UUID rather than generating a new one each time.
}

/**
 * Clear all central registration state so the next page load re-registers.
 */
function ws_central_reset() {
    // Keep site_id so re-registration reuses the same record (avoids domain UNIQUE conflict).
    // Only clear the api_key so the central re-issues a fresh one.
    delete_option('ws_central_api_key');
    delete_option('ws_central_validated_at');
    delete_option('ws_central_event_queue');
    delete_option('ws_central_last_event_push');
    delete_transient('ws_central_pending_update_report');
}

// ============================================================
// HEARTBEAT CRON
// ============================================================
add_action('ws_central_heartbeat', 'ws_central_run_heartbeat');
function ws_central_run_heartbeat() {
    $response = ws_central_send_heartbeat(true); // blocking so we can detect auth failures
    if ($response === false) {
        // Central rejected our credentials — reset and re-register immediately
        ws_central_reset();
        ws_central_maybe_register();
        return;
    }
    update_option('ws_central_validated_at', time(), false);
    if (!empty($response['api_key'])) {
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
            $msg = 'HTTP 401 — credentials rejected (secret mismatch or invalid api_key)';
            error_log('[WonderShield Central] Heartbeat 401 — credentials rejected');
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
// REST TRIGGER ENDPOINT
// Called directly by WonderShield Central on update push for instant delivery.
// ============================================================
add_action('rest_api_init', function() {
    register_rest_route('wondershield/v1', '/trigger', [
        'methods'             => 'POST',
        'callback'            => 'ws_central_handle_trigger',
        'permission_callback' => '__return_true',
    ]);
});

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
