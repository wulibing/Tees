<?php
/**
 * Plugin Name: LMT Order System
 * Description: Secure quote/order/payment API layer for tees.wulibing.me.
 * Version: 0.1.0
 */

if (!defined('ABSPATH')) { exit; }

define('LMT_OS_VERSION', '0.1.0');
define('LMT_OS_PATH', plugin_dir_path(__FILE__));
define('LMT_OS_URL', plugin_dir_url(__FILE__));

require_once LMT_OS_PATH . 'includes/class-lmt-os-pricing.php';
require_once LMT_OS_PATH . 'includes/class-lmt-os-db.php';
require_once LMT_OS_PATH . 'includes/class-lmt-os-tax.php';
require_once LMT_OS_PATH . 'includes/class-lmt-os-api.php';

register_activation_hook(__FILE__, ['LMT_OS_DB', 'activate']);

add_action('plugins_loaded', function () {
    LMT_OS_API::init();
});