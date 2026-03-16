<?php
/**
 * Plugin Name: WonderShield
 * Plugin URI: https://wondermedia.co.uk
 * Description: Security hardening and brute force protection by Wonder Media
 * Version: 1.1.0
 * Author: Wonder Media Ltd
 * Author URI: https://wondermedia.co.uk
 * License: Proprietary
 */
if (!defined('ABSPATH')) exit;

define('WS_VERSION',         '1.1.0');
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
