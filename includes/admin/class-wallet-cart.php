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

    // ─── توابع کمکی محاسبه سقف کیف پول ────────────────────────────

    /**
     * آیا یک آیتم سبد از پرداخت کیف پول مستثنی است؟
     * (فقط در حالت سقف ثابت معنی دارد)
     *
     * @param WC_Order_Item|array $item آیتم سبد یا سفارش
     * @return bool
     */
    private static function is_item_excluded($item) {
        $product_id = 0;

        if (is_a($item, 'WC_Order_Item_Product')) {
            $product_id = $item->get_product_id();
        } elseif (is_array($item) && isset($item['product_id'])) {
            $product_id = (int) $item['product_id'];
        } elseif (is_a($item, 'WC_Product')) {
            $product_id = $item->get_id();
        } elseif (is_object($item) && method_exists($item, 'get_product_id')) {
            $product_id = $item->get_product_id();
        }

        if (!$product_id) return false;

        // بررسی لیست محصولات مستثنی (شامل والد واریشن)
        $parent_id = wp_get_post_parent_id($product_id) ?: $product_id;
        $check_ids = array_unique([$product_id, $parent_id]);

        $excluded_products = Carno_Wallet_Settings::get_max_excluded_products();
        if (!empty(array_intersect($check_ids, $excluded_products))) {
            return true;
        }

        // بررسی دسته‌بندی‌های مستثنی
        $excluded_categories = Carno_Wallet_Settings::get_max_excluded_categories();
        if (!empty($excluded_categories)) {
            $terms = wp_get_post_terms($product_id, 'product_cat', ['fields' => 'ids']);
            if (!is_wp_error($terms) && !empty(array_intersect($terms, $excluded_categories))) {
                return true;
            }
        }

        return false;
    }

    /**
     * محاسبه «مبلغ قابل‌پوشش از کیف پول» در سبد خرید
     * مجموع قیمت آیتم‌های غیرمستثنی (تضمین‌شده برای نمایش اعمال کیف پول روی محصولات عادی)
     *
     * @param WC_Cart $cart
     * @return float
     */
    private static function calculate_coverable_subtotal_cart($cart) {
        $coverable = 0.0;

        foreach ($cart->get_cart_contents() as $item) {
            if (self::is_item_excluded($item)) {
                continue;
            }
            $line_total = isset($item['line_total']) ? floatval($item['line_total']) : 0;
            if ($line_total > 0) {
                $coverable += $line_total;
            }
        }

        return $coverable;
    }

    /**
     * محاسبه مبلغ نهایی قابل کسر از کیف پول برای سبد خرید
     *
     * - حالت درصدی: min(موجودی, subtotal × درصد)  ← رفتار قدیمی، استثناها اعمال نمی‌شوند
     * - حالت ثابت: min(موجودی, سقف ثابت, مبلغ قابل‌پوشش)
     *
     * @param WC_Cart $cart
     * @param float   $balance موجودی کاربر
     * @return float مبلغ قابل کسر (>= 0)
     */
    public static function calculate_wallet_deduct_amount($cart, $balance) {
        if ($balance <= 0) return 0.0;

        $mode = Carno_Wallet_Settings::get_max_mode();

        if ($mode === 'fixed') {
            $fixed_cap = Carno_Wallet_Settings::get_max_fixed_amount();
            if ($fixed_cap <= 0) {
                // سقف ثابت صفر = بدون محدودیت خاص؛ کل قابل‌پوشش
                $max_from_wallet = self::calculate_coverable_subtotal_cart($cart);
            } else {
                $coverable       = self::calculate_coverable_subtotal_cart($cart);
                $max_from_wallet = min($fixed_cap, $coverable);
            }
        } else {
            // حالت درصدی (رفتار قدیمی)
            $cart_subtotal   = floatval($cart->get_subtotal());
            $max_from_wallet = floor($cart_subtotal * Carno_Wallet_Settings::get_max_ratio());
        }

        if ($max_from_wallet <= 0) return 0.0;

        return min(floatval($balance), $max_from_wallet);
    }

    // ─── اعمال خصم به سبد خرید ──────────────────────────────────

    /**
     * اعمال خصم کیف پول خودکار به سبد خرید
     * بدون نیاز به انتخاب کاربر - کیف پول اتوماتیک اعمال می‌شود
     */
    public function apply_wallet_discount() {
        if (is_admin() && !defined('DOING_AJAX')) return;
        if (!Carno_Wallet_Helpers::is_user_logged_in()) return;

        // بررسی اینکه fee قبلاً اضافه شده‌است
        foreach (WC()->cart->get_fees() as $fee) {
            if (strpos($fee->name, 'اعتبار کیف پول') !== false) {
                return;
            }
        }

        $user_id      = Carno_Wallet_Helpers::get_current_user_id();
        $balance      = Carno_Wallet_Helpers::get_user_balance($user_id);
        $deduct_amount = self::calculate_wallet_deduct_amount(WC()->cart, $balance);

        if ($deduct_amount <= 0) return;

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
            $deduct_amount = self::calculate_wallet_deduct_amount(WC()->cart, $balance);
            if ($deduct_amount > 0) {
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
        
        if ($order_total <= 0) {
            $current_balance = Carno_Wallet_Helpers::get_user_balance($user_id);
            
            if ($current_balance >= $deduct_amount) {
                Carno_Wallet_Helpers::deduct_balance($user_id, $deduct_amount, 'purchase', 'پرداخت کامل سفارش از کیف پول', $order->get_id());

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
        if (!in_array($new_status, Carno_Wallet_Settings::get_deduction_statuses(), true)) return;

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

        Carno_Wallet_Helpers::deduct_balance($user_id, $wallet_amount, 'purchase', 'کسر بهای سفارش از کیف پول', $order_id);
        $order->update_meta_data(CARNO_WALLET_ORDER_DEDUCTED_KEY, true);

        $new_balance = Carno_Wallet_Helpers::get_user_balance($user_id);
        $order->add_order_note(
            sprintf('✅ موجودی کیف پول کاهش یافت: %s | موجودی جدید: %s',
                Carno_Wallet_Helpers::format_currency($wallet_amount),
                Carno_Wallet_Helpers::format_currency($new_balance)
            )
        );

        // کش‌بک: درصدی از مبلغ سبد خرید به کیف پول اضافه می‌شود (یک‌بار)
        if (Carno_Wallet_Settings::is_cashback_enabled() && !$order->get_meta(CARNO_WALLET_ORDER_CASHBACK_KEY, true)) {
            $subtotal        = floatval($order->get_subtotal());
            $cashback_amount = floor($subtotal * Carno_Wallet_Settings::get_cashback_ratio());

            if ($cashback_amount > 0) {
                $balance_before = Carno_Wallet_Helpers::get_user_balance($user_id);
                $balance_after  = Carno_Wallet_Helpers::add_to_balance($user_id, $cashback_amount, 'cashback', 'کش‌بک خرید', $order_id);
                $actual_added   = $balance_after - $balance_before;

                $order->update_meta_data(CARNO_WALLET_ORDER_CASHBACK_KEY, true);
                $order->update_meta_data(CARNO_WALLET_ORDER_CASHBACK_AMOUNT_KEY, $actual_added);

                if ($actual_added < $cashback_amount) {
                    $order->add_order_note(
                        sprintf('🎁 کش‌بک %s%% سبد خرید: %s محاسبه شد، اما به‌دلیل سقف موجودی کیف پول فقط %s اضافه شد | موجودی جدید: %s',
                            intval(Carno_Wallet_Settings::get_cashback_ratio() * 100),
                            Carno_Wallet_Helpers::format_currency($cashback_amount),
                            Carno_Wallet_Helpers::format_currency($actual_added),
                            Carno_Wallet_Helpers::format_currency($balance_after)
                        )
                    );
                } else {
                    $order->add_order_note(
                        sprintf('🎁 کش‌بک %s%% سبد خرید: %s به کیف پول اضافه شد | موجودی جدید: %s',
                            intval(Carno_Wallet_Settings::get_cashback_ratio() * 100),
                            Carno_Wallet_Helpers::format_currency($cashback_amount),
                            Carno_Wallet_Helpers::format_currency($balance_after)
                        )
                    );
                }

                // ارسال آسینک پیامک اطلاع‌رسانی کش‌بک (در صورت فعال‌بودن در تنظیمات)
                if ($actual_added > 0 && Carno_Wallet_Settings::is_cashback_sms_enabled() && function_exists('as_schedule_single_action')) {
                    as_schedule_single_action(time(), 'carno_wallet_send_cashback_sms', [
                        'user_id'       => $user_id,
                        'order_id'      => $order_id,
                        'amount'        => $actual_added,
                        'balance_after' => $balance_after,
                    ], 'carno-wallet');
                }
            }
        }

        $order->save();
    }
}
