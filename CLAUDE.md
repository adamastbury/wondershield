# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

WonderShield is a WordPress security plugin (v1.0.5) by Wonder Media Ltd. It provides brute force protection, IP blocking, bad bot detection, XML-RPC disabling, user enumeration prevention, and an admin dashboard — all in a single PHP file with zero external dependencies.

## Deployment

There is no build step. Releases are triggered by pushing a git tag:

```bash
git tag v1.0.x
git push origin v1.0.x
```

GitHub Actions (`.github/workflows/release.yml`) automatically builds `wondershield.zip` and creates a GitHub release. The plugin's auto-updater then serves this to WordPress sites.

**Version must be updated in two places before tagging:**
- `WS_VERSION` constant in `wondershield.php` (line ~12)
- The `Version:` plugin header in `wondershield.php` (line ~7)

## Architecture

```
wondershield/
├── wondershield.php          ← Entry point: constants, require chain, activation hooks
├── includes/
│   ├── class-updater.php     ← GitHub auto-updater
│   ├── helpers.php           ← Core functions + activation/cron/block page
│   ├── protections.php       ← All security add_action hooks (the growing part)
│   └── admin.php             ← Admin menu, AJAX, stats, log row renderer
└── templates/
    ├── block-page.php        ← Branded block page (vars: $message, $ip, $mins)
    └── admin-page.php        ← Admin dashboard HTML (vars: $stats, $active_blocks, $logs, $unblocked)
```

### Database Tables (created on activation)
- `{prefix}wondershield_log` — event log (IP, event type, path, user agent, timestamp)
- `{prefix}wondershield_blocks` — IP blocks (IP, reason, expiry, manual flag)

### Core Constants (`wondershield.php`)
| Constant | Value | Purpose |
|---|---|---|
| `WS_MAX_ATTEMPTS` | 5 | Failed logins before lockout |
| `WS_ATTEMPT_WINDOW` | 300s | Window for counting attempts |
| `WS_LOCKOUT_DURATION` | 1800s | How long an IP stays blocked |
| `WS_PROBE_THRESHOLD` | 3 | Probe hits before IP block |
| `WS_LOG_MAX_ROWS` | 1000 | Max log entries |
| `WS_LOG_MAX_DAYS` | 30 | Log retention period |
| `WS_NOTIFY_EMAIL` | hello@wondermedia.co.uk | Alert recipient |

### Key functions (`includes/helpers.php`)
- **`ws_get_ip()`** — IP detection respecting Cloudflare headers
- **`ws_is_blocked()` / `ws_block_ip()`** — block check and creation
- **`ws_log()`** — event logging
- **`ws_count_recent_events()`** — counts any event type for threshold checks
- **`ws_block_response()`** — includes `templates/block-page.php` and exits
- **`ws_daily_cleanup`** cron — log pruning and expired block removal

### Security rules (`includes/protections.php`)
All `add_action` hooks live here. To add a new protection, add it to this file and push an update. Current rules:
- XML-RPC block, user enumeration prevention, REST user endpoint removal
- Bad user agent detection (scanner tool names)
- Bad path probe detection (`/.env`, `/.git/`, etc.) — `$probe_contains` and `$probe_prefixes` arrays
- WP-login and wp-admin rate limiting
- Failed login hook, new admin user email alert

### Auto-Updater (`includes/class-updater.php`)
Polls GitHub releases API every 5 minutes, hooks into WordPress transient filters (`pre_set_site_transient_update_plugins`, `plugins_api`) to inject update info. Downloads zip directly from GitHub release assets.

---

## WonderShield Central Integration

### What to build

A new file: `includes/central.php`

This file adds silent background reporting to WonderShield Central — a Cloudflare Pages dashboard at `https://shield.wondermedia.co.uk`. It does four things:

1. **Registers** the site with Central on first run, stores the returned API key
2. **Sends a heartbeat** every 5 minutes with current stats via WP-Cron
3. **Pushes security events** to Central in real time (batched, flushed on shutdown, at most once per 60 seconds)
4. **Polls for and executes force-update commands** issued from the Central dashboard

It must **not** break or change any existing plugin behaviour. Purely additive.

---

### Configuration constants (top of central.php)

```php
define('WS_CENTRAL_URL',                'https://shield.wondermedia.co.uk');
define('WS_CENTRAL_REGISTRATION_SECRET', ''); // Set to REGISTRATION_SECRET from Cloudflare
```

`WS_CENTRAL_REGISTRATION_SECRET` is used only for first-time registration. After that, the per-site API key stored in `ws_central_api_key` option is used.

---

### WordPress options (new — don't conflict with existing)

| Option | Description |
|--------|-------------|
| `ws_central_site_id` | UUID v4, generated on first registration |
| `ws_central_api_key` | Per-site key returned by Central on registration |
| `ws_central_event_queue` | JSON array of queued events (max 50) |
| `ws_central_last_event_push` | Unix timestamp of last event flush |

---

### API endpoints

All requests:
- `Content-Type: application/json`
- `Authorization: Bearer <token>`
- Use `wp_remote_post()` / `wp_remote_get()` with `timeout => 10`
- Use `blocking => false` for fire-and-forget calls (events, update reports)
- On WP_Error or non-2xx: `error_log('[WonderShield Central] ...')` and silently continue

#### POST /api/heartbeat

Called every 5 minutes via `ws_central_heartbeat` WP-Cron hook.

Auth: `REGISTRATION_SECRET` on first call; per-site API key thereafter.

**Request body:**
```json
{
  "site_id": "<ws_central_site_id>",
  "domain": "<parse_url(home_url(), PHP_URL_HOST)>",
  "site_url": "<home_url()>",
  "site_name": "<get_bloginfo('name')>",
  "plugin_version": "<WS_VERSION>",
  "wp_version": "<get_bloginfo('version')>",
  "php_version": "<PHP_VERSION>",
  "stats": {
    "blocked_24h": 0,
    "blocked_7d": 0,
    "blocked_30d": 0,
    "attempts_24h": 0,
    "xmlrpc_blocked": 0,
    "probes_blocked": 0,
    "total_events": 0,
    "active_blocks": 0
  }
}
```

Stats come from calling `ws_get_stats()` (defined in `includes/admin.php`).

**Response:**
```json
{
  "ok": true,
  "api_key": "ws_sk_...",    // only on first registration — save to ws_central_api_key
  "force_update": false,
  "target_version": null,
  "command_id": null
}
```

If `force_update: true` → trigger the update flow (see below).

**Registration flow:**
1. On first load, if `ws_central_site_id` is empty: generate UUID with `wp_generate_uuid4()`, save it
2. Call heartbeat immediately (blocking) using `REGISTRATION_SECRET`
3. Save returned `api_key` to `ws_central_api_key`
4. Schedule cron jobs

---

#### POST /api/event

Flush queued events on the `shutdown` hook, at most once per 60 seconds.

Auth: Per-site API key.

**Request body:**
```json
{
  "site_id": "<ws_central_site_id>",
  "events": [
    {
      "event_type": "blocked",
      "ip": "1.2.3.4",
      "path": "/wp-login.php",
      "user_agent": "...",
      "created_at": "2026-03-23T12:00:00Z"
    }
  ]
}
```

Allowed event types: `blocked`, `login_failed`, `probe_blocked`, `bad_agent_blocked`, `xmlrpc_blocked`, `enum_blocked`

`created_at` = UTC ISO 8601 from `gmdate('Y-m-d\TH:i:s\Z')`.

**How to collect events:**
- Add `do_action('ws_event', $type, $ip, $path, $user_agent)` at the end of `ws_log()` in `helpers.php`
- In `central.php`, hook `add_action('ws_event', 'ws_central_queue_event', 10, 4)`
- Append to `ws_central_event_queue` option, cap at 50 items
- Use `blocking => false` on the flush request

---

#### GET /api/sites/{site_id}/check

Called every 5 minutes via `ws_central_check` WP-Cron hook.

Auth: Per-site API key.

**Response:**
```json
{
  "force_update": true,
  "target_version": "1.5.0",
  "command_id": 42
}
```

If `force_update: true` → trigger the update flow.

---

#### POST /api/update/report

Called after a force-update attempt.

Auth: Per-site API key. Use `blocking => true` so the result is confirmed.

**Request body:**
```json
{
  "site_id": "<ws_central_site_id>",
  "command_id": 42,
  "status": "updating",
  "old_version": "1.4.2",
  "new_version": null,
  "error_message": null
}
```

Status values: `updating`, `success`, `failed`

**Fallback:** If the report POST fails, store it as a transient and include it in the next heartbeat body as `update_report` (the server handles this field).

---

### Force-update flow

```
1. Save old_version = WS_VERSION
2. POST /api/update/report  status=updating
3. require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php'
4. $upgrader = new Plugin_Upgrader(new WP_Upgrader_Skin())
5. $result = $upgrader->upgrade('wondershield/wondershield.php')
6. If $result === true → POST /api/update/report  status=success, new_version=WS_VERSION (reloaded)
7. If error         → POST /api/update/report  status=failed, error_message=wp_error message
```

---

### WP-Cron setup

Register a custom interval:
```php
add_filter('cron_schedules', function($s) {
    $s['ws_five_minutes'] = ['interval' => 300, 'display' => 'Every 5 minutes'];
    return $s;
});
```

Schedule on activation (add to `ws_activate()` in `helpers.php`):
```php
if (!wp_next_scheduled('ws_central_heartbeat'))
    wp_schedule_event(time(), 'ws_five_minutes', 'ws_central_heartbeat');
if (!wp_next_scheduled('ws_central_check'))
    wp_schedule_event(time(), 'ws_five_minutes', 'ws_central_check');
```

Unschedule on deactivation (add to `ws_deactivate()` in `helpers.php`):
```php
wp_clear_scheduled_hook('ws_central_heartbeat');
wp_clear_scheduled_hook('ws_central_check');
```

---

### Files to create/modify

| File | Change |
|------|--------|
| `includes/central.php` | **Create** — all Central integration code |
| `wondershield.php` | **Add** `require_once` for `central.php` near bottom, wrapped in `if (defined('WS_CENTRAL_URL') && WS_CENTRAL_URL)` |
| `includes/helpers.php` | **Add** `do_action('ws_event', ...)` at the end of `ws_log()` |
| `includes/helpers.php` | **Add** cron schedule/unschedule calls in `ws_activate()` and `ws_deactivate()` |

No other files should be modified.

---

### Note on DISABLE_WP_CRON

All sites run `DISABLE_WP_CRON = true` in `wp-config.php`. This **only** disables the automatic cron spawn that happens on WordPress page loads — it does **not** prevent wp-cron from running when `wp-cron.php` is called directly via HTTP. WonderShield Central calls `wp-cron.php` directly every 5 minutes, which will correctly trigger `ws_central_heartbeat` and `ws_central_check` alongside all other scheduled WordPress jobs. No special workaround is needed in `central.php`.

---

### Version bump after this work

After `central.php` is complete and tested, bump `WS_VERSION` and the `Version:` header in `wondershield.php` to `1.5.2`, then tag and push:
```bash
git tag v1.5.2
git push origin v1.5.2
```

---

### Error handling rules

- Never let Central comms crash or slow the site
- All outbound calls wrapped in try/catch and `is_wp_error()` checks
- Blocking calls only in cron context (never on page loads)
- Silent failure with `error_log()` only

---

### Testing the integration locally

To test against the live Central instance:
1. Set `WS_CENTRAL_REGISTRATION_SECRET` in `central.php` to the value from Cloudflare secrets
2. Install the plugin on a test WordPress site
3. Trigger cron manually: `wp cron event run ws_central_heartbeat`
4. Check the WonderShield Central dashboard at `https://shield.wondermedia.co.uk` — the site should appear

---

### Version bump after this work

After `central.php` is complete and tested, bump `WS_VERSION` and the `Version:` header in `wondershield.php` to `1.5.0`, then tag and push:
```bash
git tag v1.5.0
git push origin v1.5.0
```
