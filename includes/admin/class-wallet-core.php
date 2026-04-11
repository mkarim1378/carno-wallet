<?php
if (!defined('ABSPATH')) exit;

/**
 * هسته اصلی کیف پول: عملیات موجودی و نمایش فرانت‌اند
 * 
 * این کلاس مسئول است:
 * - نمایش موجودی در داشبورد کاربر
 * - مدیریت ریفند‌های سفارش
 */
class Carno_Wallet_Core {

    private static $instance = null;

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('woocommerce_account_dashboard', [$this, 'display_wallet_balance']);
        add_action('wp_footer', [$this, 'inject_wallet_in_woodmart_dropdown']);
        add_action('wp_head', [$this, 'header_dropdown_wallet_styles']);
        add_action('woocommerce_refund_created', [$this, 'handle_refund'], 10, 2);
    }

    // ─── نمایش موجودی ──────────────────────────────────────────

    /**
     * نمایش موجودی کیف پول در داشبورد کاربر
     */
    public function display_wallet_balance() {
        $user_id = Carno_Wallet_Helpers::get_current_user_id();
        if (!$user_id) return;

        $balance = Carno_Wallet_Helpers::get_user_balance($user_id);
        ?>
        <div class="woocommerce-Message woocommerce-Message--info woocommerce-info" style="margin-bottom: 20px;">
            <strong>💰 موجودی کیف پول شما:</strong> <?php echo Carno_Wallet_Helpers::format_currency($balance); ?>
        </div>
        <?php
    }

    /**
     * تزریق موجودی کیف پول به دراپ‌داون هدر وودمارت از طریق JavaScript
     *
     * وودمارت در dropdown هدر، قالب navigation.php ووکامرس را فراخوانی نمی‌کند
     * و لینک‌ها را مستقیم رندر می‌کند؛ به همین دلیل تزریق JS تنها راه مطمئن است.
     */
    public function inject_wallet_in_woodmart_dropdown() {
        if (!is_user_logged_in()) return;

        $user_id = Carno_Wallet_Helpers::get_current_user_id();
        $balance  = Carno_Wallet_Helpers::get_user_balance($user_id);
        $formatted = esc_js(Carno_Wallet_Helpers::format_currency($balance));
        ?>
        <script>
        (function () {
            var html = '<div class="carno-wallet-header-balance">'
                     + '<span class="carno-wallet-header-balance__label">اعتبار کیف پول<\/span>'
                     + '<span class="carno-wallet-header-balance__amount"><?php echo $formatted; ?><\/span>'
                     + '<\/div>';

            /* سلکتورهای رایج دراپ‌داون اکانت در وودمارت */
            var selectors = [
                '.wd-dropdown-account .woocommerce-MyAccount-navigation',
                '.wd-dropdown-account nav',
                '.wd-dropdown-account ul',
                '.woodmart-account-dropdown nav',
                '.woodmart-account-dropdown ul',
                '.wd-header-account .woocommerce-MyAccount-navigation',
                '.wd-header-account ul',
            ];

            function inject() {
                /* جلوگیری از تزریق مکرر */
                if (document.querySelector('.wd-dropdown-account .carno-wallet-header-balance')) return;

                for (var i = 0; i < selectors.length; i++) {
                    var el = document.querySelector(selectors[i]);
                    if (el) {
                        el.insertAdjacentHTML('beforebegin', html);
                        return;
                    }
                }
            }

            /* اجرا بعد از لود کامل DOM */
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', inject);
            } else {
                inject();
            }

            /* پشتیبانی از بارگذاری AJAX در وودمارت */
            var observer = new MutationObserver(inject);
            observer.observe(document.body, { childList: true, subtree: true });
        })();
        </script>
        <?php
    }

    /**
     * استایل‌های نمایش موجودی در دراپ‌داون هدر
     */
    public function header_dropdown_wallet_styles() {
        ?>
        <style>
        .carno-wallet-header-balance {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: linear-gradient(135deg, #e8f5e9 0%, #f1f8e9 100%);
            border: 1px solid #c8e6c9;
            border-radius: 8px;
            padding: 10px 14px;
            margin: 8px 12px 4px;
            direction: rtl;
            gap: 8px;
            box-sizing: border-box;
            width: calc(100% - 24px);
        }
        .carno-wallet-header-balance__label {
            font-size: 12px;
            color: #555;
            font-weight: 500;
            line-height: 1.4;
        }
        .carno-wallet-header-balance__amount {
            font-size: 13px;
            color: #2e7d32;
            font-weight: 700;
            white-space: nowrap;
            line-height: 1.4;
        }
        </style>
        <?php
    }

    // ─── مدیریت ریفند ──────────────────────────────────────────

    /**
     * مدیریت بازپرداخت: اگر سفارش از کیف پول پرداخت شده باشد، موجودی برگرداند
     * 
     * @param int $refund_id شناسه بازپرداخت
     * @param array $args اطلاعات بازپرداخت
     */
    public function handle_refund($refund_id, $args) {
        $refund = wc_get_refund($refund_id);
        $order = $refund ? $refund->get_parent() : null;

        if (!$order) return;

        $user_id = $order->get_user_id();
        if (!$user_id) return;

        // بررسی اینکه آیا کیف پول برای این سفارش استفاده شده
        $wallet_amount = $order->get_meta(CARNO_WALLET_ORDER_AMOUNT_KEY, true);
        if (!$wallet_amount || $wallet_amount <= 0) return;

        // اگر قبلاً برگردانده شده، دوباره نه
        if ($order->get_meta(CARNO_WALLET_ORDER_REFUNDED_KEY, true)) return;

        $refund_amount = floatval($args['amount'] ?? 0);
        if ($refund_amount <= 0) return;

        // اگر بازپرداخت بیشتر یا برابر با مبلغ کیف پول باشد
        if ($refund_amount >= $wallet_amount) {
            Carno_Wallet_Helpers::add_to_balance($user_id, $wallet_amount);
            
            $order->update_meta_data(CARNO_WALLET_ORDER_REFUNDED_KEY, true);
            $order->add_order_note(
                sprintf('💳 بازپرداخت به کیف پول: %s',
                    Carno_Wallet_Helpers::format_currency($wallet_amount)
                )
            );
            $order->save();
        }
    }

    // ─── توابع کمکی (برای سازگاری عقب‌رفتگی) ────────────────────

    /**
     * دریافت موجودی کاربر (برای سازگاری عقب‌رفتگی)
     * 
     * @param int $user_id شناسه کاربر
     * @return float موجودی
     */
    public static function get_user_balance($user_id) {
        return Carno_Wallet_Helpers::get_user_balance($user_id);
    }

    /**
     * تنظیم موجودی کاربر (برای سازگاری عقب‌رفتگی)
     * 
     * @param int $user_id شناسه کاربر
     * @param float $amount مقدار
     * @return bool نتیجه
     */
    public static function set_user_balance($user_id, $amount) {
        return Carno_Wallet_Helpers::set_user_balance($user_id, $amount);
    }

    /**
     * کسر موجودی (برای سازگاری عقب‌رفتگی)
     * 
     * @param int $user_id شناسه کاربر
     * @param float $amount مقدار
     * @return float موجودی جدید
     */
    public static function deduct_balance($user_id, $amount) {
        return Carno_Wallet_Helpers::deduct_balance($user_id, $amount);
    }

    /**
     * اضافه‌کردن موجودی (برای سازگاری عقب‌رفتگی)
     * 
     * @param int $user_id شناسه کاربر
     * @param float $amount مقدار
     * @return float موجودی جدید
     */
    public static function add_to_balance($user_id, $amount) {
        return Carno_Wallet_Helpers::add_to_balance($user_id, $amount);
    }
}
