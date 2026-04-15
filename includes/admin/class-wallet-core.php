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
        add_action('woocommerce_refund_created', [$this, 'handle_refund'], 10, 2);
        add_shortcode('carno_wallet_balance', [$this, 'shortcode_wallet_balance']);
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
     * شورت‌کد نمایش موجودی به صورت متن خام
     * استفاده: [carno_wallet_balance]
     */
    public function shortcode_wallet_balance() {
        $user_id = Carno_Wallet_Helpers::get_current_user_id();
        if (!$user_id) {
            return 'برای دیدن موجودی به حساب کاربری خود <a href="/auth/">وارد شوید</a>';
        }

        $balance = Carno_Wallet_Helpers::get_user_balance($user_id);
        return 'موجودی کیف پول شما: ' . Carno_Wallet_Helpers::format_currency($balance);
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
