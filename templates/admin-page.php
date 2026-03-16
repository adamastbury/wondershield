<?php if (!defined('ABSPATH')) exit; ?>
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
    width: 48px; height: 48px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    filter: drop-shadow(0 0 12px rgba(86,0,255,0.5)) drop-shadow(0 0 24px rgba(0,220,255,0.15));
}
.ws-logo svg { width: 40px; height: 40px; }
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
/* SECURITY PIPELINE */
.ws-layers {
    background: linear-gradient(135deg, #0c0228 0%, #1a0448 60%, #0c0228 100%);
    border-radius: 16px;
    padding: 22px 24px 24px;
    margin: 24px 32px 0;
    overflow: hidden;
    position: relative;
}
.ws-layers::before {
    content: '';
    position: absolute;
    inset: 0;
    background-image: radial-gradient(circle, rgba(255,255,255,0.022) 1px, transparent 1px);
    background-size: 20px 20px;
    pointer-events: none;
}
.ws-layers-eyebrow {
    font-family: 'Dosis', sans-serif;
    font-size: 9px;
    font-weight: 600;
    letter-spacing: 0.22em;
    text-transform: uppercase;
    color: rgba(0,220,255,0.55);
    text-align: center;
    margin-bottom: 4px;
    position: relative;
}
.ws-layers-headline {
    font-family: 'Plus Jakarta Sans', sans-serif;
    font-size: 12px;
    font-weight: 600;
    color: rgba(255,255,255,0.55);
    text-align: center;
    margin-bottom: 20px;
    letter-spacing: -0.01em;
    position: relative;
}
.ws-pipeline {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0;
    flex-wrap: nowrap;
    position: relative;
}
.ws-pnode {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 3px;
    padding: 11px 13px;
    border-radius: 12px;
    background: rgba(255,255,255,0.06);
    border: 1px solid rgba(255,255,255,0.09);
    flex-shrink: 0;
    min-width: 76px;
    text-align: center;
    position: relative;
}
.ws-pnode-icon { font-size: 18px; line-height: 1; margin-bottom: 1px; }
.ws-pnode-name {
    font-family: 'Dosis', sans-serif;
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 0.07em;
    text-transform: uppercase;
    color: rgba(255,255,255,0.65);
    white-space: nowrap;
}
.ws-pnode-sub {
    font-family: 'Plus Jakarta Sans', sans-serif;
    font-size: 9px;
    color: rgba(255,255,255,0.28);
    white-space: nowrap;
}
.ws-pnode-shield {
    background: linear-gradient(135deg, #5600FF 0%, #3a00cc 100%);
    border: 1px solid rgba(0,220,255,0.35);
    box-shadow: 0 0 22px rgba(86,0,255,0.5), 0 0 44px rgba(0,220,255,0.13), inset 0 1px 0 rgba(255,255,255,0.12);
    padding: 13px 16px;
    min-width: 98px;
    transform: scale(1.06);
    animation: ws-shield-pulse 3s ease-in-out infinite;
}
.ws-pnode-shield .ws-pnode-name { color: #fff; font-size: 11px; }
.ws-pnode-shield .ws-pnode-sub  { color: rgba(255,255,255,0.55); }
.ws-pnode-badge {
    font-family: 'Dosis', sans-serif;
    font-size: 8px;
    font-weight: 700;
    letter-spacing: 0.1em;
    text-transform: uppercase;
    color: #00DCFF;
    background: rgba(0,220,255,0.13);
    border: 1px solid rgba(0,220,255,0.28);
    border-radius: 20px;
    padding: 2px 7px;
    margin-top: 4px;
    white-space: nowrap;
}
.ws-pnode-site {
    background: rgba(0,180,90,0.1);
    border-color: rgba(0,180,90,0.22);
}
.ws-pnode-site .ws-pnode-name { color: #00c87a; }
.ws-pconn {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 4px;
    padding: 0 5px;
    flex-shrink: 0;
}
.ws-pconn-tag {
    font-family: 'Dosis', sans-serif;
    font-size: 8px;
    font-weight: 700;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    color: #ff5577;
    background: rgba(255,85,119,0.1);
    border: 1px solid rgba(255,85,119,0.22);
    border-radius: 20px;
    padding: 2px 7px;
    white-space: nowrap;
}
.ws-pconn-tag.pass {
    color: #00c87a;
    background: rgba(0,200,100,0.1);
    border-color: rgba(0,200,100,0.22);
}
.ws-pconn-arrow { font-size: 13px; color: rgba(255,255,255,0.18); line-height: 1; }
.ws-pconn-arrow.pass { color: rgba(0,200,100,0.45); }
@keyframes ws-shield-pulse {
    0%, 100% { box-shadow: 0 0 22px rgba(86,0,255,0.5), 0 0 44px rgba(0,220,255,0.13), inset 0 1px 0 rgba(255,255,255,0.12); }
    50%       { box-shadow: 0 0 30px rgba(86,0,255,0.7), 0 0 60px rgba(0,220,255,0.22), inset 0 1px 0 rgba(255,255,255,0.12); }
}
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
.ws-stat-value { font-size: 30px; font-weight: 800; color: #0f0230; line-height: 1; margin-bottom: 6px; }
.ws-stat-value.danger  { color: #dc2626; }
.ws-stat-value.warning { color: #ea580c; }
.ws-stat-value.teal    { color: #0891b2; }
.ws-stat-label {
    font-family: 'Dosis', sans-serif;
    font-size: 10px;
    letter-spacing: 0.1em;
    text-transform: uppercase;
    color: #8b7fb8;
}
/* PANELS */
.ws-panel { background: #fff; border: 1px solid #e8e4ff; border-radius: 14px; margin-bottom: 22px; overflow: hidden; }
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
    margin: 0; padding: 0;
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
.ws-table { width: 100%; border-collapse: collapse; font-size: 13px; }
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
.ws-table tbody tr { border-bottom: 1px solid #f5f3ff; transition: background 0.1s; }
.ws-table tbody tr:hover { background: #faf9ff; }
.ws-table tbody tr:last-child { border-bottom: none; }
.ws-table td { padding: 11px 20px; color: #0f0230; vertical-align: middle; }
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
.ws-empty { padding: 32px 24px; text-align: center; color: #c4b8e8; font-size: 14px; }
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
.ws-config-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 14px; padding: 20px; }
.ws-config-item { background: #faf9ff; border: 1px solid #ede9ff; border-radius: 10px; padding: 14px 16px; }
.ws-config-label { font-family: 'Dosis', sans-serif; font-size: 10px; letter-spacing: 0.12em; text-transform: uppercase; color: #8b7fb8; margin-bottom: 5px; }
.ws-config-value { font-size: 17px; font-weight: 700; color: #5600FF; }
.ws-config-sub { font-size: 11px; color: #a094c8; margin-top: 2px; }
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
    <!-- HEADER -->
    <div class="ws-header">
        <div class="ws-logo">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">
                <defs>
                    <linearGradient id="wm-admin-g" x1="0%" y1="0%" x2="100%" y2="100%">
                        <stop offset="0%"   stop-color="#5600ff"/>
                        <stop offset="60%"  stop-color="#247fff"/>
                        <stop offset="100%" stop-color="#00dcff"/>
                    </linearGradient>
                </defs>
                <polygon points="43.7,74 20.8,28 65.6,28" fill="none" stroke="url(#wm-admin-g)" stroke-width="5.5" stroke-linejoin="round" stroke-linecap="round"/>
                <polygon points="57.3,74 34.4,28 79.2,28" fill="none" stroke="url(#wm-admin-g)" stroke-width="5.5" stroke-linejoin="round" stroke-linecap="round"/>
            </svg>
        </div>
        <div class="ws-header-text">
            <h1>WonderShield</h1>
            <p>Security Protection by Wonder Media</p>
        </div>
        <div class="ws-version">v<?php echo WS_VERSION; ?></div>
    </div>

    <!-- SECURITY PIPELINE -->
    <div class="ws-layers">
        <div class="ws-layers-eyebrow">Defence in Depth</div>
        <div class="ws-layers-headline">Your site is protected by 4 layers — WonderShield is the final line of defence</div>
        <div class="ws-pipeline">
            <div class="ws-pnode">
                <div class="ws-pnode-icon">🌐</div>
                <div class="ws-pnode-name">Internet</div>
                <div class="ws-pnode-sub">All Traffic</div>
            </div>
            <div class="ws-pconn">
                <div class="ws-pconn-tag">Bots &amp; DDoS ✕</div>
                <div class="ws-pconn-arrow">→</div>
            </div>
            <div class="ws-pnode">
                <div class="ws-pnode-icon">☁️</div>
                <div class="ws-pnode-name">Cloudflare</div>
                <div class="ws-pnode-sub">Edge Shield</div>
            </div>
            <div class="ws-pconn">
                <div class="ws-pconn-tag">Attacks ✕</div>
                <div class="ws-pconn-arrow">→</div>
            </div>
            <div class="ws-pnode">
                <div class="ws-pnode-icon">🖥️</div>
                <div class="ws-pnode-name">Web Server</div>
                <div class="ws-pnode-sub">Host Firewall</div>
            </div>
            <div class="ws-pconn">
                <div class="ws-pconn-tag">Brute Force ✕</div>
                <div class="ws-pconn-arrow">→</div>
            </div>
            <div class="ws-pnode ws-pnode-shield">
                <div class="ws-pnode-icon">🛡️</div>
                <div class="ws-pnode-name">WonderShield</div>
                <div class="ws-pnode-sub">Login Guard</div>
                <div class="ws-pnode-badge">Final Defence</div>
            </div>
            <div class="ws-pconn">
                <div class="ws-pconn-tag pass">Verified Only ✓</div>
                <div class="ws-pconn-arrow pass">→</div>
            </div>
            <div class="ws-pnode ws-pnode-site">
                <div class="ws-pnode-icon">✅</div>
                <div class="ws-pnode-name">Your Site</div>
                <div class="ws-pnode-sub">Protected</div>
            </div>
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
                <div class="ws-stat-value"><?php echo $stats['probes_blocked']; ?></div>
                <div class="ws-stat-label">Probes Blocked</div>
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

        <!-- PROTECTION RULES -->
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
                    <div class="ws-config-label">Probe Threshold</div>
                    <div class="ws-config-value"><?php echo WS_PROBE_THRESHOLD; ?></div>
                    <div class="ws-config-sub">hits before block</div>
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
