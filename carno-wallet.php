<?php
/**
 * Plugin Name: کارنو ولت - کیف پول کاربران کارنو مهارت
 * Description: مدیریت کیف پول کاربران آکادمی کارنو مهارت با قابلیت آپلود اکسل
 * Version: 1.2.0
 * Author: Carno Maharat
 * Text Domain: carno-wallet
 */

if (!defined('ABSPATH')) exit;

define('CARNO_WALLET_VERSION',  '1.2.0');
define('CARNO_WALLET_PATH',     plugin_dir_path(__FILE__));
define('CARNO_WALLET_URL',      plugin_dir_url(__FILE__));
define('CARNO_WALLET_META_KEY', 'user_wallet_balance');

require_once CARNO_WALLET_PATH . 'includes/class-wallet-core.php';
require_once CARNO_WALLET_PATH . 'includes/class-wallet-cart.php';
require_once CARNO_WALLET_PATH . 'includes/class-wallet-xlsx-reader.php';
require_once CARNO_WALLET_PATH . 'includes/class-wallet-admin.php';

/**
 * باگ‌فیکس: راه‌اندازی بعد از plugins_loaded تا WooCommerce حتماً لود شده باشد.
 * قبلاً plugin قبل از plugins_loaded راه‌اندازی می‌شد.
 */
add_action('plugins_loaded', function () {
    // Gateway فقط اگر WooCommerce فعال باشد لود می‌شود
    if (class_exists('WC_Payment_Gateway')) {
        require_once CARNO_WALLET_PATH . 'includes/class-wallet-gateway.php';
    }

    Carno_Wallet_Core::get_instance();
    Carno_Wallet_Cart::get_instance();
    Carno_Wallet_Admin::get_instance();
});
