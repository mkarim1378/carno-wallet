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

// Constants برای order meta
define('CARNO_WALLET_ORDER_AMOUNT_KEY',      '_carno_wallet_amount');
define('CARNO_WALLET_ORDER_USED_KEY',        '_carno_wallet_used');
define('CARNO_WALLET_ORDER_DEDUCTED_KEY',    '_carno_wallet_deducted');
define('CARNO_WALLET_ORDER_FULL_PAYMENT_KEY', '_carno_wallet_full_payment');
define('CARNO_WALLET_ORDER_REFUNDED_KEY',    '_carno_wallet_refunded');

require_once CARNO_WALLET_PATH . 'includes/class-wallet-core.php';
require_once CARNO_WALLET_PATH . 'includes/class-wallet-cart.php';
require_once CARNO_WALLET_PATH . 'includes/class-wallet-xlsx-reader.php';
require_once CARNO_WALLET_PATH . 'includes/class-wallet-admin.php';

/**
 * باگ‌فیکس: راه‌اندازی بعد از plugins_loaded تا WooCommerce حتماً لود شده باشد.
 * قبلاً plugin قبل از plugins_loaded راه‌اندازی می‌شد.
 */
add_action('plugins_loaded', function () {
    // Core سال باید اول بارگذاری شود
    Carno_Wallet_Core::get_instance();
    Carno_Wallet_Cart::get_instance();

    Carno_Wallet_Admin::get_instance();
});
