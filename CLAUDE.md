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
