<?php
if (!defined('ABSPATH')) exit;

/**
 * هسته اصلی کیف پول: عملیات موجودی و نمایش فرانت‌اند
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
        add_filter('woocommerce_payment_gateways', [$this, 'register_gateway']);
        add_action('woocommerce_account_dashboard', [$this, 'display_wallet_balance']);
        add_action('woocommerce_refund_created', [$this, 'handle_refund'], 10, 2);
    }

    public function register_gateway($gateways) {
        $gateways[] = 'Carno_Wallet_Gateway';
        return $gateways;
    }

    public function display_wallet_balance() {
        $user_id = get_current_user_id();
        if (!$user_id) return;

        $balance = self::get_user_balance($user_id);
        echo '<div class="woocommerce-Message woocommerce-Message--info woocommerce-info" style="margin-bottom: 20px;">';
        echo '<strong>موجودی کیف پول شما:</strong> ' . number_format($balance) . ' تومان';
        echo '</div>';
    }

    // ─── توابع استاتیک موجودی ──────────────────────────────────

    public static function get_user_balance($user_id) {
        $balance = get_user_meta((int) $user_id, CARNO_WALLET_META_KEY, true);
        return $balance !== '' ? floatval($balance) : 0.0;
    }

    public static function set_user_balance($user_id, $amount) {
        return update_user_meta((int) $user_id, CARNO_WALLET_META_KEY, max(0, floatval($amount)));
    }

    public static function deduct_balance($user_id, $amount) {
        $current = self::get_user_balance($user_id);
        $new_balance = max(0, $current - floatval($amount));
        return self::set_user_balance($user_id, $new_balance);
    }

    /**
     * هندلینگ بازپرداخت: اگر سفارش از کیف پول پرداخت شده، موجودی را برگردانید
     */
    public static function handle_refund($refund_id, $args) {
        $refund = wc_get_refund($refund_id);
        $order = $refund->get_parent();

        if (!$order) return;

        $user_id = $order->get_user_id();
        if (!$user_id) return;

        // بررسی اینکه آیا کیف پول برای این سفارش استفاده شده
        $wallet_amount = $order->get_meta('_carno_wallet_amount', true);
        if (!$wallet_amount || $wallet_amount <= 0) return;

        // اگر قبلاً برگردانده شده، دوباره نه
        if ($order->get_meta('_carno_wallet_refunded', true)) return;

        // محاسبه مبلغ بازپرداخت برای کیف پول
        $refund_amount = floatval($args['amount'] ?? 0);
        if ($refund_amount <= 0) return;

        // اگر بازپرداخت بیشتر از یا برابر با کیف پول استفاده‌شده باشد
        if ($refund_amount >= $wallet_amount) {
            // تمام مبلغ کیف پول را برگردانید
            self::add_to_balance($user_id, $wallet_amount);
            
            $order->update_meta_data('_carno_wallet_refunded', true);
            $order->add_order_note(
                sprintf('💳 بازپرداخت به کیف پول: %s تومان',
                    number_format($wallet_amount)
                )
            );
            $order->save();
        }
    }

    /**
     * اضافه‌کردن موجودی به کیف پول کاربر (برای بازپرداخت ها)
     */
    public static function add_to_balance($user_id, $amount) {
        $current = self::get_user_balance($user_id);
        $new_balance = $current + floatval($amount);
        return self::set_user_balance($user_id, $new_balance);
    }
}