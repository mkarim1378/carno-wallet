<?php
/**
 * Plugin Name: کارنو ولت - کیف پول کاربران کارنو مهارت
 * Description: مدیریت کیف پول کاربران آکادمی کارنو مهارت با قابلیت آپلود اکسل
 * Version: 1.7.0
 * Author: Carno Maharat
 * Text Domain: carno-wallet
 */

if (!defined('ABSPATH')) exit;

define('CARNO_WALLET_VERSION',  '1.7.0');
define('CARNO_WALLET_PATH',     plugin_dir_path(__FILE__));
define('CARNO_WALLET_URL',      plugin_dir_url(__FILE__));
define('CARNO_WALLET_META_KEY', 'user_wallet_balance');

// ─── Constants برای Order Meta ───────────────────────────────
define('CARNO_WALLET_ORDER_AMOUNT_KEY',      '_carno_wallet_amount');
define('CARNO_WALLET_ORDER_USED_KEY',        '_carno_wallet_used');
define('CARNO_WALLET_ORDER_DEDUCTED_KEY',    '_carno_wallet_deducted');
define('CARNO_WALLET_ORDER_FULL_PAYMENT_KEY', '_carno_wallet_full_payment');
define('CARNO_WALLET_ORDER_REFUNDED_KEY',    '_carno_wallet_refunded');
define('CARNO_WALLET_ORDER_CASHBACK_KEY',    '_carno_wallet_cashback_applied');

// ─── بارگذاری Helpers ───────────────────────────────────────
require_once CARNO_WALLET_PATH . 'includes/helpers/class-wallet-transactions.php';
require_once CARNO_WALLET_PATH . 'includes/helpers/class-helpers.php';

// ─── بارگذاری Settings (باید قبل از سایر کلاس‌ها بارگذاری شود) ──
require_once CARNO_WALLET_PATH . 'includes/admin/class-wallet-settings.php';

// ─── بارگذاری Admin Components ──────────────────────────────
require_once CARNO_WALLET_PATH . 'includes/admin/class-wallet-core.php';
require_once CARNO_WALLET_PATH . 'includes/admin/class-wallet-cart.php';
require_once CARNO_WALLET_PATH . 'includes/admin/class-wallet-xlsx-reader.php';
require_once CARNO_WALLET_PATH . 'includes/admin/class-wallet-admin.php';
require_once CARNO_WALLET_PATH . 'includes/admin/class-wallet-order.php';

// ─── بارگذاری API ───────────────────────────────────────────
require_once CARNO_WALLET_PATH . 'includes/api/class-wallet-api.php';

// ─── ایجاد/به‌روزرسانی جدول تراکنش‌ها هنگام فعال‌سازی افزونه ──
register_activation_hook(__FILE__, ['Carno_Wallet_Transactions', 'maybe_install']);

/**
 * راه‌اندازی افزونه
 *
 * باگ‌فیکس: راه‌اندازی بعد از plugins_loaded تا WooCommerce حتماً لود شده باشد.
 */
add_action('plugins_loaded', function () {
    // اطمینان از وجود جدول تراکنش‌ها برای نصب‌های قبلی (بدون نیاز به فعال‌سازی مجدد)
    Carno_Wallet_Transactions::maybe_install();

    // بارگذاری Gateway (در اینجا WooCommerce حتماً لود شده است)
    require_once CARNO_WALLET_PATH . 'includes/gateway/class-wallet-gateway.php';

    // Helpers از ابتدا موجود هستند
    
    // Settings باید اول بارگذاری شود
    Carno_Wallet_Settings::get_instance();

    // Core باید دوم بارگذاری شود
    Carno_Wallet_Core::get_instance();
    
    // Cart management
    Carno_Wallet_Cart::get_instance();

    // Admin interface
    Carno_Wallet_Admin::get_instance();
    
    // Order management
    Carno_Wallet_Order::get_instance();
    
    // REST API
    Carno_Wallet_API::get_instance();
});
