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
        // اعمال خصم به قیمت - خودکار بدون نیاز به انتخاب کاربر
        add_action('woocommerce_cart_calculate_fees', [$this, 'apply_wallet_discount']);
        
        // نمایش اطلاعات کیف پول در صفحه تسویه‌حساب
        add_action('woocommerce_before_checkout_form', [$this, 'display_wallet_info_checkout']);
        
        // ذخیره اطلاعات کیف پول در سفارش
        add_action('woocommerce_checkout_create_order', [$this, 'save_wallet_to_order']);
        
        // پردازش سفارش پس از ایجاد
        add_action('woocommerce_checkout_order_created', [$this, 'process_full_wallet_payment'], 10, 1);
        
        // کم‌کردن موجودی پس از پرداخت موفق (برای سفارش‌های جزئی)
        add_action('woocommerce_order_status_changed', [$this, 'handle_wallet_deduction_on_order_status_change'], 10, 3);
        
        // پاک‌کردن سشن پس از سفارش
        add_action('woocommerce_thankyou', [$this, 'clear_wallet_session']);
    }



    /**
     * اعمال خصم کیف پول خودکار به سبد خرید
     * بدون نیاز به انتخاب کاربر - کیف پول اتوماتیک اعمال می‌شود
     */
    public function apply_wallet_discount() {
        if (is_admin() && !defined('DOING_AJAX')) return;
        if (!is_user_logged_in()) return;

        $user_id = get_current_user_id();
        $balance = Carno_Wallet_Core::get_user_balance($user_id);

        if ($balance <= 0) return;

        // محاسبه مبلغی که باید از کیف پول کم شود
        $cart_total = floatval(WC()->cart->get_total('edit'));
        
        // اگر سبد خرید خالی است یا مبلغی ندارد، fee اضافه نکنید
        if ($cart_total <= 0) return;

        $deduct_amount = min($balance, $cart_total);

        // اگر مبلغی برای کم کردن وجود ندارد، fee اضافه نکنید
        if ($deduct_amount <= 0) return;

        // اعمال به عنوان اعتبار منفی (خصم) - نمایش به عنوان subtotal
        WC()->cart->add_fee(
            sprintf(__('اعتبار کیف پول: -%s تومان', 'carno-wallet'), number_format($deduct_amount)),
            -floatval($deduct_amount)
        );

        // ذخیره مبلغ در سشن برای استفاده در پرداخت
        WC()->session->set('carno_wallet_deduct_amount', $deduct_amount);
    }

    /**
     * نمایش اطلاعات کیف پول در صفحه تسویه‌حساب
     */
    public function display_wallet_info_checkout() {
        if (!is_user_logged_in()) return;

        $deduct_amount = WC()->session->get('carno_wallet_deduct_amount');
        if (!$deduct_amount || $deduct_amount <= 0) return;

        $cart_total = floatval(WC()->cart->get_total('edit'));
        $remaining = $cart_total - floatval($deduct_amount);

        echo '<div class="carno-checkout-wallet-info">';
        echo '<h3>📊 خلاصه استفاده از کیف پول</h3>';
        echo '<table>';
        
        echo '<tr>';
        echo '  <td><strong>مبلغ کل سفارش:</strong></td>';
        echo '  <td style="font-weight: 600; font-size: 16px;">' . number_format($cart_total) . ' تومان</td>';
        echo '</tr>';
        
        echo '<tr>';
        echo '  <td><strong>مبلغ کیف پول:</strong></td>';
        echo '  <td class="wallet-amount">- ' . number_format($deduct_amount) . ' تومان</td>';
        echo '</tr>';

        if ($remaining > 0) {
            echo '<tr>';
            echo '  <td><strong>مبلغ قابل پرداخت:</strong></td>';
            echo '  <td class="remaining-amount">' . number_format($remaining) . ' تومان</td>';
            echo '</tr>';
        } else {
            echo '<tr>';
            echo '  <td colspan="2" style="text-align: center; padding: 15px; background: #e8f8f5; border-radius: 5px;">';
            echo '  <span class="success-message">✓ سفارش شما کاملاً از طریق کیف پول پرداخت می‌شود!</span>';
            echo '  </td>';
            echo '</tr>';
        }
        
        echo '</table>';
        echo '</div>';
    }

    /**
     * ذخیره اطلاعات کیف پول در سفارش
     */
    public function save_wallet_to_order($order) {
        if (!is_user_logged_in()) return;

        $deduct_amount = WC()->session->get('carno_wallet_deduct_amount');
        
        if ($deduct_amount && $deduct_amount > 0) {
            $order->update_meta_data(CARNO_WALLET_ORDER_USED_KEY, true);
            $order->update_meta_data(CARNO_WALLET_ORDER_AMOUNT_KEY, floatval($deduct_amount));
        }
    }

    /**
     * پردازش سفارش اگر موجودی کیف پول برای پوشش کل مبلغ موثر باشد
     * در این صورت سفارش بدون نیاز به درگاه پرداخت ثبت می‌شود
     */
    public function process_full_wallet_payment($order) {
        if (!is_user_logged_in()) return;

        $user_id = $order->get_user_id();
        $deduct_amount = WC()->session->get('carno_wallet_deduct_amount');
        
        if (!$deduct_amount || $deduct_amount <= 0) return;

        $order_total = floatval($order->get_total());
        
        // اگر موجودی کیف پول برای پوشش کل سفارش کافی است
        if ($deduct_amount >= $order_total) {
            // کم‌کردن موجودی از کیف پول
            $current_balance = Carno_Wallet_Core::get_user_balance($user_id);
            
            if ($current_balance >= $deduct_amount) {
                Carno_Wallet_Core::deduct_balance($user_id, $deduct_amount);
                
                // ثبت سفارش به عنوان پرداخت‌شده کامل
                $order->set_payment_method_title('پرداخت از کیف پول');
                $order->set_status('processing');
                
                // یادداشت در سفارش
                $order->add_order_note(
                    sprintf('✓ پرداخت کامل از کیف پول: %s تومان | موجودی باقی‌مانده: %s تومان',
                        number_format($deduct_amount),
                        number_format(Carno_Wallet_Core::get_user_balance($user_id))
                    )
                );
                
                $order->update_meta_data(CARNO_WALLET_ORDER_FULL_PAYMENT_KEY, true);
                $order->update_meta_data(CARNO_WALLET_ORDER_DEDUCTED_KEY, true);
                $order->save();
                
                // پاک‌کردن سبد خرید
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
     * این برای مواردی است که کاربر کیف پول را استفاده کرد از طریق گیت‌وی های دیگر
     * (پرداخت جزئی)
     */
    public function handle_wallet_deduction_on_order_status_change($order_id, $old_status, $new_status) {
        // فقط وقتی سفارش به حالت "processing" یا "completed" برود
        if (!in_array($new_status, ['processing', 'completed'])) return;

        $order = wc_get_order($order_id);

        // بررسی اینکه آیا این سفارش قبلاً پردازش شده
        if ($order->get_meta(CARNO_WALLET_ORDER_DEDUCTED_KEY, true)) return;

        $wallet_amount = $order->get_meta(CARNO_WALLET_ORDER_AMOUNT_KEY, true);
        if (!$wallet_amount || $wallet_amount <= 0) return;

        $user_id = $order->get_user_id();
        if (!$user_id) return;

        $current_balance = Carno_Wallet_Core::get_user_balance($user_id);

        // اگر موجودی برای کم‌کردن کافی نیست، صرفاً یادداشت بگذارید
        if ($current_balance < $wallet_amount) {
            $order->add_order_note(
                sprintf('⚠️ تلاش برای کم‌کردن %s تومان از کیف پول، اما تنها %s تومان موجود است.',
                    number_format($wallet_amount),
                    number_format($current_balance)
                )
            );
            return;
        }

        // کم‌کردن موجودی
        Carno_Wallet_Core::deduct_balance($user_id, $wallet_amount);

        // ذخیره‌ی اطلاع‌رسانی اینکه کم‌کردن انجام شده
        $order->update_meta_data(CARNO_WALLET_ORDER_DEDUCTED_KEY, true);
        $order->save();

        $new_balance = Carno_Wallet_Core::get_user_balance($user_id);

        $order->add_order_note(
            sprintf('✓ موجودی کیف پول کاهش یافت: %s تومان | موجودی جدید: %s تومان',
                number_format($wallet_amount),
                number_format($new_balance)
            )
        );
    }

}
