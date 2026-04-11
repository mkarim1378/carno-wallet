<?php
/**
 * Plugin Name: کارنو ولت - کیف پول کاربران کارنو مهارت
 * Description: مدیریت کیف پول کاربران آکادمی کارنو مهارت با قابلیت آپلود اکسل
 * Version: 1.3.0
 * Author: Carno Maharat
 * Text Domain: carno-wallet
 */

if (!defined('ABSPATH')) exit;

define('CARNO_WALLET_VERSION',  '1.3.0');
define('CARNO_WALLET_PATH',     plugin_dir_path(__FILE__));
define('CARNO_WALLET_URL',      plugin_dir_url(__FILE__));
define('CARNO_WALLET_META_KEY', 'user_wallet_balance');

// ─── Constants برای Order Meta ───────────────────────────────
define('CARNO_WALLET_ORDER_AMOUNT_KEY',      '_carno_wallet_amount');
define('CARNO_WALLET_ORDER_USED_KEY',        '_carno_wallet_used');
define('CARNO_WALLET_ORDER_DEDUCTED_KEY',    '_carno_wallet_deducted');
define('CARNO_WALLET_ORDER_FULL_PAYMENT_KEY', '_carno_wallet_full_payment');
define('CARNO_WALLET_ORDER_REFUNDED_KEY',    '_carno_wallet_refunded');

// ─── بارگذاری Helpers ───────────────────────────────────────
require_once CARNO_WALLET_PATH . 'includes/helpers/class-helpers.php';

// ─── بارگذاری Admin Components ──────────────────────────────
require_once CARNO_WALLET_PATH . 'includes/admin/class-wallet-core.php';
require_once CARNO_WALLET_PATH . 'includes/admin/class-wallet-cart.php';
require_once CARNO_WALLET_PATH . 'includes/admin/class-wallet-xlsx-reader.php';
require_once CARNO_WALLET_PATH . 'includes/admin/class-wallet-admin.php';
require_once CARNO_WALLET_PATH . 'includes/admin/class-wallet-order.php';

// ─── بارگذاری API ───────────────────────────────────────────
require_once CARNO_WALLET_PATH . 'includes/api/class-wallet-api.php';

/**
 * راه‌اندازی افزونه
 * 
 * باگ‌فیکس: راه‌اندازی بعد از plugins_loaded تا WooCommerce حتماً لود شده باشد.
 */
add_action('plugins_loaded', function () {
    // بارگذاری Gateway (در اینجا WooCommerce حتماً لود شده است)
    require_once CARNO_WALLET_PATH . 'includes/gateway/class-wallet-gateway.php';
    
    // Helpers از ابتدا موجود هستند
    
    // Core باید اول بارگذاری شود
    Carno_Wallet_Core::get_instance();
    
    // Cart management
    Carno_Wallet_Cart::get_instance();

    // Admin interface
    Carno_Wallet_Admin::get_instance();
    
    // Order management
    Carno_Wallet_Order::get_instance();
    
    // REST API
    Carno_Wallet_API::get_instance();
<<<<<<< HEAD
=======
    
    // ثبت‌نام درگاه پرداخت WooCommerce
    add_filter('woocommerce_payment_gateways', function ($gateways) {
        $gateways[] = 'Carno_Wallet_Gateway';
        return $gateways;
    });
>>>>>>> 8ba72b0621cc524bf46416b4c1f804316b1ee618
});
