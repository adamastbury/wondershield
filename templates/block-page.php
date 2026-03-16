<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Access Restricted</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&family=Dosis:wght@600;700&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

body {
    font-family: 'Plus Jakarta Sans', sans-serif;
    background: #0f0230;
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
    position: relative;
}

/* Animated mist layers */
.fog {
    position: fixed;
    border-radius: 50%;
    mix-blend-mode: screen;
    pointer-events: none;
    will-change: transform;
}
.fog-a {
    width: 700px; height: 700px;
    background: radial-gradient(ellipse, rgba(86,0,255,0.42) 0%, rgba(60,0,200,0.12) 40%, transparent 70%);
    filter: blur(72px);
    top: -200px; right: -100px;
    animation: fa 7s ease-in-out infinite alternate;
}
.fog-b {
    width: 600px; height: 600px;
    background: radial-gradient(ellipse, rgba(0,80,200,0.35) 0%, rgba(0,40,150,0.10) 45%, transparent 70%);
    filter: blur(80px);
    top: 100px; left: -100px;
    animation: fb 9s ease-in-out infinite alternate;
}
.fog-c {
    width: 500px; height: 500px;
    background: radial-gradient(ellipse, rgba(0,180,255,0.25) 0%, rgba(0,120,200,0.08) 45%, transparent 70%);
    filter: blur(76px);
    bottom: -100px; left: 30%;
    animation: fc 11s ease-in-out infinite alternate;
}
@keyframes fa {
    0%   { transform: translate(0,0) scale(1); }
    50%  { transform: translate(-120px,100px) scale(1.12); }
    100% { transform: translate(60px,-80px) scale(0.92); }
}
@keyframes fb {
    0%   { transform: translate(0,0) scale(1); }
    50%  { transform: translate(140px,80px) scale(1.10); }
    100% { transform: translate(-80px,-100px) scale(0.90); }
}
@keyframes fc {
    0%   { transform: translate(0,0) scale(1.05); }
    50%  { transform: translate(-100px,-80px) scale(0.88); }
    100% { transform: translate(120px,80px) scale(1.10); }
}

/* Noise texture */
.noise {
    position: fixed;
    inset: 0;
    pointer-events: none;
    background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 256 256' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='0.04'/%3E%3C/svg%3E");
    background-size: 200px;
    opacity: 0.4;
    z-index: 1;
}

/* Glass card */
.card {
    position: relative;
    z-index: 2;
    background: rgba(255,255,255,0.04);
    border: 1px solid rgba(255,255,255,0.10);
    backdrop-filter: blur(24px);
    -webkit-backdrop-filter: blur(24px);
    border-radius: 24px;
    padding: 48px 44px 40px;
    max-width: 480px;
    width: 90%;
    text-align: center;
    box-shadow: 0 32px 80px rgba(0,0,0,0.5), 0 0 0 1px rgba(255,255,255,0.04);
    animation: card-in 0.5s cubic-bezier(0.4,0,0.2,1) both;
}
@keyframes card-in {
    from { opacity: 0; transform: translateY(20px); }
    to   { opacity: 1; transform: translateY(0); }
}

/* Brand header */
.brand {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 8px;
    margin-bottom: 24px;
}
.brand-logo {
    width: 68px;
    height: 68px;
    filter: drop-shadow(0 0 16px rgba(86,0,255,0.5)) drop-shadow(0 0 32px rgba(0,220,255,0.2));
}
.brand-name {
    font-weight: 800;
    font-size: 19px;
    background: linear-gradient(90deg, #fff 20%, #00dcff);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
    letter-spacing: -0.01em;
    line-height: 1;
}
.brand-sub {
    font-family: 'Dosis', sans-serif;
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 0.18em;
    text-transform: uppercase;
    color: rgba(0,220,255,0.45);
}

/* Divider */
.divider {
    height: 1px;
    background: linear-gradient(90deg, transparent, rgba(0,220,255,0.25), rgba(86,0,255,0.25), transparent);
    margin: 0 0 26px;
}

/* Status badge */
.status-badge {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    background: rgba(239,68,68,0.10);
    border: 1px solid rgba(239,68,68,0.22);
    border-radius: 50px;
    padding: 5px 14px 5px 10px;
    font-family: 'Dosis', sans-serif;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.12em;
    text-transform: uppercase;
    color: #f87171;
    margin-bottom: 18px;
}
.status-dot {
    width: 6px; height: 6px;
    border-radius: 50%;
    background: #ef4444;
    animation: pulse-dot 2s ease-in-out infinite;
}
@keyframes pulse-dot {
    0%, 100% { opacity: 1; transform: scale(1); }
    50%       { opacity: 0.5; transform: scale(0.75); }
}

h1 {
    font-size: 22px;
    font-weight: 800;
    color: #fff;
    line-height: 1.2;
    letter-spacing: -0.02em;
    margin-bottom: 10px;
}
.message {
    font-size: 14px;
    color: rgba(255,255,255,0.50);
    line-height: 1.7;
    margin-bottom: 24px;
}

/* Info pills */
.pills {
    display: flex;
    flex-direction: column;
    gap: 7px;
    margin-bottom: 22px;
    text-align: left;
}
.pill {
    display: flex;
    align-items: center;
    gap: 12px;
    background: rgba(255,255,255,0.04);
    border: 1px solid rgba(255,255,255,0.07);
    border-radius: 10px;
    padding: 9px 14px;
}
.pill-label {
    font-family: 'Dosis', sans-serif;
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 0.14em;
    text-transform: uppercase;
    color: rgba(0,220,255,0.5);
    min-width: 50px;
    flex-shrink: 0;
}
.pill-value {
    font-size: 13px;
    color: rgba(255,255,255,0.75);
    font-weight: 600;
}

/* Countdown */
.countdown-wrap {
    background: rgba(86,0,255,0.07);
    border: 1px solid rgba(86,0,255,0.20);
    border-radius: 14px;
    padding: 18px 20px 16px;
    margin-bottom: 24px;
}
.countdown-label {
    font-family: 'Dosis', sans-serif;
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 0.16em;
    text-transform: uppercase;
    color: rgba(0,220,255,0.55);
    margin-bottom: 8px;
}
.countdown-timer {
    font-size: 42px;
    font-weight: 800;
    background: linear-gradient(90deg, #a855f7, #00dcff);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
    letter-spacing: -0.03em;
    line-height: 1;
    font-variant-numeric: tabular-nums;
}
.countdown-sub {
    font-size: 12px;
    color: rgba(255,255,255,0.28);
    margin-top: 8px;
    line-height: 1.5;
}

/* Footer */
.card-footer {
    font-family: 'Dosis', sans-serif;
    font-size: 10px;
    letter-spacing: 0.12em;
    text-transform: uppercase;
    color: rgba(255,255,255,0.16);
}
.card-footer a {
    color: rgba(0,220,255,0.35);
    text-decoration: none;
    transition: color 0.2s;
}
.card-footer a:hover { color: rgba(0,220,255,0.65); }
</style>
</head>
<body>

<div class="fog fog-a"></div>
<div class="fog fog-b"></div>
<div class="fog fog-c"></div>
<div class="noise"></div>

<div class="card">

    <div class="brand">
        <!-- WonderMedia logo mark -->
        <svg class="brand-logo" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">
            <defs>
                <linearGradient id="wm-g" x1="0%" y1="0%" x2="100%" y2="100%">
                    <stop offset="0%"   stop-color="#5600ff"/>
                    <stop offset="60%"  stop-color="#247fff"/>
                    <stop offset="100%" stop-color="#00dcff"/>
                </linearGradient>
            </defs>
            <polygon points="43.7,74 20.8,28 65.6,28" fill="none" stroke="url(#wm-g)" stroke-width="5.5" stroke-linejoin="round" stroke-linecap="round"/>
            <polygon points="57.3,74 34.4,28 79.2,28" fill="none" stroke="url(#wm-g)" stroke-width="5.5" stroke-linejoin="round" stroke-linecap="round"/>
        </svg>
        <div class="brand-name">WonderShield</div>
        <div class="brand-sub">by Wonder Media</div>
    </div>

    <div class="divider"></div>

    <div class="status-badge">
        <div class="status-dot"></div>
        Access Blocked
    </div>

    <h1>Your access has been<br>temporarily restricted</h1>
    <p class="message"><?php echo esc_html($message); ?></p>

    <div class="pills">
        <?php if ($ip): ?>
        <div class="pill">
            <span class="pill-label">IP</span>
            <span class="pill-value"><?php echo esc_html($ip); ?></span>
        </div>
        <?php endif; ?>
        <div class="pill">
            <span class="pill-label">Time</span>
            <span class="pill-value"><?php echo gmdate('d M Y, H:i') . ' UTC'; ?></span>
        </div>
    </div>

    <?php if ($secs > 0): ?>
    <div class="countdown-wrap">
        <div class="countdown-label">Access restores in</div>
        <div class="countdown-timer" id="ws-countdown">--:--</div>
        <div class="countdown-sub" id="ws-countdown-sub">If you believe this is an error, please contact the site administrator.</div>
    </div>
    <?php endif; ?>

    <div class="card-footer">
        Protected by <a href="https://wondermedia.co.uk" target="_blank">Wonder Media</a>
    </div>

</div>

<?php if ($secs > 0): ?>
<script>
(function() {
    var secs = <?php echo $secs; ?>;
    var el  = document.getElementById('ws-countdown');
    var sub = document.getElementById('ws-countdown-sub');
    function tick() {
        if (secs <= 0) {
            el.textContent  = '00:00';
            sub.textContent = 'You may now try again.';
            return;
        }
        var m = Math.floor(secs / 60);
        var s = secs % 60;
        el.textContent = (m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s;
        secs--;
        setTimeout(tick, 1000);
    }
    tick();
})();
</script>
<?php endif; ?>

</body>
</html>
