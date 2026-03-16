<?php
/**
 * Plugin Name: WonderShield
 * Plugin URI: https://wondermedia.co.uk
 * Description: Security hardening and brute force protection by Wonder Media
 * Version: 1.2.0
 * Author: Wonder Media Ltd
 * Author URI: https://wondermedia.co.uk
 * License: Proprietary
 */
if (!defined('ABSPATH')) exit;

define('WS_VERSION',         '1.2.0');
define('WS_PLUGIN_DIR',      plugin_dir_path(__FILE__));
define('WS_TABLE_LOG',       $GLOBALS['wpdb']->prefix . 'wondershield_log');
define('WS_TABLE_BLOCKS',    $GLOBALS['wpdb']->prefix . 'wondershield_blocks');
define('WS_MAX_ATTEMPTS',    5);
define('WS_ATTEMPT_WINDOW',  300);
define('WS_LOCKOUT_DURATION',1800);
define('WS_PROBE_THRESHOLD', 3);
define('WS_LOG_MAX_ROWS',    1000);
define('WS_LOG_MAX_DAYS',    30);
define('WS_NOTIFY_EMAIL',    'hello@wondermedia.co.uk');

require_once WS_PLUGIN_DIR . 'includes/class-updater.php';
require_once WS_PLUGIN_DIR . 'includes/helpers.php';
require_once WS_PLUGIN_DIR . 'includes/protections.php';
require_once WS_PLUGIN_DIR . 'includes/admin.php';

$ws_updater = new WonderShield_Updater(__FILE__);
$ws_updater->init();

register_activation_hook(__FILE__,   'ws_activate');
register_deactivation_hook(__FILE__, 'ws_deactivate');

// Auto-create tables if missing — runs inline on every load, no hooks or dbDelta needed.
// Checks an option flag so it only executes the CREATE once, not on every request.
if ( get_option( 'ws_blocks_table_ver' ) !== '1' ) {
    global $wpdb;
    $wpdb->query(
        "CREATE TABLE IF NOT EXISTS " . WS_TABLE_BLOCKS . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            ip VARCHAR(45) NOT NULL,
            reason VARCHAR(100) NOT NULL,
            blocked_at DATETIME NOT NULL,
            expires_at DATETIME NOT NULL,
            manual TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY idx_ip (ip),
            KEY idx_expires (expires_at)
        ) " . $GLOBALS['wpdb']->get_charset_collate()
    );
    update_option( 'ws_blocks_table_ver', '1' );
}
