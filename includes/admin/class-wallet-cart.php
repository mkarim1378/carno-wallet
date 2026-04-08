<?php
if (!defined('ABSPATH')) exit;

/**
 * کلاس مدیریت کیف پول در سبد خرید
 * 
 * این کلاس مسئول اعمال اعتبار کیف پول به سبد خرید کاربر است:
 * - اگر موجودی >= قیمت سبد: خصم کامل
 * - اگر موجودی < قیمت سبد: خصم جزئی + درگاه پرداخت برای باقی
 */
class Carno_Wallet_Cart {

    private static $instance = null;
    const WALLET_SESSION_KEY = 'carno_wallet_credit_used';
    const WALLET_CREATE_PARTIAL_GATEWAY = true;

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('woocommerce_cart_calculate_fees', [$this, 'apply_wallet_discount']);
        add_action('woocommerce_checkout_create_order', [$this, 'save_wallet_to_order']);
        add_action('woocommerce_checkout_order_created', [$this, 'recalculate_order_totals'], 9, 1);
        add_action('woocommerce_checkout_process', [$this, 'ensure_wallet_fee_in_order']);
        add_action('woocommerce_checkout_order_created', [$this, 'process_full_wallet_payment'], 10, 1);
        add_action('woocommerce_order_status_changed', [$this, 'handle_wallet_deduction_on_order_status_change'], 10, 3);
        add_action('woocommerce_thankyou', [$this, 'clear_wallet_session']);
    }

    // ─── اعمال خصم به سبد خرید ──────────────────────────────────

    /**
     * اعمال خصم کیف پول خودکار به سبد خرید
     * بدون نیاز به انتخاب کاربر - کیف پول اتوماتیک اعمال می‌شود
     */
    public function apply_wallet_discount() {
        if (is_admin() && !defined('DOING_AJAX')) return;
        if (!Carno_Wallet_Helpers::is_user_logged_in()) return;

        $user_id = Carno_Wallet_Helpers::get_current_user_id();
        $balance = Carno_Wallet_Helpers::get_user_balance($user_id);

        if ($balance <= 0) return;

        $cart_subtotal = WC()->cart->get_subtotal();
        if ($cart_subtotal <= 0) return;

        $deduct_amount = min($balance, $cart_subtotal);
        if ($deduct_amount <= 0) return;

        // بررسی اینکه fee قبلاً اضافه شده‌است
        $cart_fees = WC()->cart->get_fees();
        foreach ($cart_fees as $fee) {
            if (strpos($fee->name, 'اعتبار کیف پول') !== false) {
                return;
            }
        }

        WC()->cart->add_fee(
            sprintf(__('اعتبار کیف پول: -%s تومان', 'carno-wallet'), number_format($deduct_amount)),
            -floatval($deduct_amount)
        );

        WC()->session->set('carno_wallet_deduct_amount', $deduct_amount);
    }

    /**
     * اطمینان از محاسبه صحیح fees قبل از checkout
     */
    public function ensure_wallet_fee_in_order() {
        if (!Carno_Wallet_Helpers::is_user_logged_in()) return;

        $deduct_amount = WC()->session->get('carno_wallet_deduct_amount');
        if (!$deduct_amount || $deduct_amount <= 0) return;

        WC()->cart->calculate_totals();
    }

    /**
     * بروزرسانی Order totals پس از ایجاد order
     */
    public function recalculate_order_totals($order) {
        if (!Carno_Wallet_Helpers::is_user_logged_in()) return;
        $order->calculate_totals(false);
    }

    /**
     * ذخیره اطلاعات کیف پول در سفارش
     */
    public function save_wallet_to_order($order) {
        if (!Carno_Wallet_Helpers::is_user_logged_in()) return;

        WC()->cart->calculate_totals();

        $deduct_amount = WC()->session->get('carno_wallet_deduct_amount');
        
        if (!$deduct_amount || $deduct_amount <= 0) {
            $user_id = $order->get_user_id();
            $balance = Carno_Wallet_Helpers::get_user_balance($user_id);
            $cart_subtotal = WC()->cart->get_subtotal();
            
            if ($balance > 0 && $cart_subtotal > 0) {
                $deduct_amount = min($balance, $cart_subtotal);
                WC()->session->set('carno_wallet_deduct_amount', $deduct_amount);
            }
        }
        
        if ($deduct_amount && $deduct_amount > 0) {
            $order->update_meta_data(CARNO_WALLET_ORDER_USED_KEY, true);
            $order->update_meta_data(CARNO_WALLET_ORDER_AMOUNT_KEY, floatval($deduct_amount));
        }
    }

    /**
     * پردازش سفارش اگر موجودی کیف پول برای پوشش کل مبلغ موثر باشد
     */
    public function process_full_wallet_payment($order) {
        if (!Carno_Wallet_Helpers::is_user_logged_in()) return;

        $user_id = $order->get_user_id();
        $deduct_amount = WC()->session->get('carno_wallet_deduct_amount');
        
        if (!$deduct_amount || $deduct_amount <= 0) return;

        $order->calculate_totals();
        $order_total = floatval($order->get_total());
        
        if ($deduct_amount >= $order_total) {
            $current_balance = Carno_Wallet_Helpers::get_user_balance($user_id);
            
            if ($current_balance >= $deduct_amount) {
                Carno_Wallet_Helpers::deduct_balance($user_id, $deduct_amount);
                
                $order->set_payment_method_title('پرداخت از کیف پول');
                $order->set_status('processing');
                
                $order->add_order_note(
                    sprintf('✅ پرداخت کامل از کیف پول: %s | موجودی باقی: %s',
                        Carno_Wallet_Helpers::format_currency($deduct_amount),
                        Carno_Wallet_Helpers::format_currency(Carno_Wallet_Helpers::get_user_balance($user_id))
                    )
                );
                
                $order->update_meta_data(CARNO_WALLET_ORDER_FULL_PAYMENT_KEY, true);
                $order->update_meta_data(CARNO_WALLET_ORDER_DEDUCTED_KEY, true);
                $order->save();
                
                WC()->cart->empty_cart();
            }
        }
    }

    /**
     * پاک‌کردن سشن پس از تکمیل سفارش
     */
    public function clear_wallet_session() {
        WC()->session->set('carno_wallet_deduct_amount', null);
    }

    // ─── توابع کمکی ────────────────────────────────────────────

    /**
     * دریافت مبلغ کم‌شده از کیف پول
     */
    public static function get_deducted_amount($order_id = null) {
        if ($order_id) {
            $order = wc_get_order($order_id);
            return floatval($order->get_meta(CARNO_WALLET_ORDER_AMOUNT_KEY, true) ?? 0);
        }
        return floatval(WC()->session->get('carno_wallet_deduct_amount') ?? 0);
    }

    /**
     * بررسی اینکه آیا سفارش از کیف پول استفاده کرده
     */
    public static function order_used_wallet($order_id) {
        $order = wc_get_order($order_id);
        return $order->get_meta(CARNO_WALLET_ORDER_USED_KEY, true) === true;
    }

    /**
     * هندلینگ کم‌کردن کیف پول وقتی سفارش به‌صورت پرداخت‌شده علامت‌گذاری شود
     */
    public function handle_wallet_deduction_on_order_status_change($order_id, $old_status, $new_status) {
        if (!in_array($new_status, ['processing', 'completed'])) return;

        $order = wc_get_order($order_id);

        if ($order->get_meta(CARNO_WALLET_ORDER_DEDUCTED_KEY, true)) return;

        $wallet_amount = $order->get_meta(CARNO_WALLET_ORDER_AMOUNT_KEY, true);
        if (!$wallet_amount || $wallet_amount <= 0) return;

        $user_id = $order->get_user_id();
        if (!$user_id) return;

        $current_balance = Carno_Wallet_Helpers::get_user_balance($user_id);

        if ($current_balance < $wallet_amount) {
            $order->add_order_note(
                sprintf('⚠️ تلاش برای کم‌کردن %s، اما تنها %s موجود است.',
                    Carno_Wallet_Helpers::format_currency($wallet_amount),
                    Carno_Wallet_Helpers::format_currency($current_balance)
                )
            );
            return;
        }

        Carno_Wallet_Helpers::deduct_balance($user_id, $wallet_amount);
        $order->update_meta_data(CARNO_WALLET_ORDER_DEDUCTED_KEY, true);
        $order->save();

        $new_balance = Carno_Wallet_Helpers::get_user_balance($user_id);

        $order->add_order_note(
            sprintf('✅ موجودی کیف پول کاهش یافت: %s | موجودی جدید: %s',
                Carno_Wallet_Helpers::format_currency($wallet_amount),
                Carno_Wallet_Helpers::format_currency($new_balance)
            )
        );
    }
}
