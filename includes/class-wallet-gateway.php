<?php
if (!defined('ABSPATH')) exit;

/**
 * درگاه پرداخت WooCommerce — کیف پول
 */
class Carno_Wallet_Gateway extends WC_Payment_Gateway {

    public function __construct() {
        $this->id                 = 'carno_wallet';
        $this->method_title       = 'کیف پول';
        $this->method_description = 'پرداخت از طریق موجودی کیف پول';
        $this->has_fields         = false;

        $this->init_form_fields();
        $this->init_settings();

        $this->title       = $this->get_option('title', 'کیف پول');
        $this->description = $this->get_option('description', 'پرداخت کامل از موجودی کیف پول شما');

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
    }

    public function init_form_fields() {
        $this->form_fields = [
            'enabled' => [
                'title'   => 'فعال/غیرفعال',
                'type'    => 'checkbox',
                'label'   => 'فعال کردن پرداخت با کیف پول',
                'default' => 'yes',
            ],
            'title' => [
                'title'   => 'عنوان',
                'type'    => 'text',
                'default' => 'کیف پول',
            ],
            'description' => [
                'title'   => 'توضیحات',
                'type'    => 'text',
                'default' => 'پرداخت کامل از موجودی کیف پول شما',
            ],
        ];
    }

    /**
     * باگ‌فیکس: به جای add_filter داخل constructor، از is_available استفاده می‌شود.
     * قبلاً filter هر بار که WooCommerce یک instance می‌ساخت دوباره register می‌شد.
     */
    public function is_available() {
        if (!parent::is_available()) return false;
        if (!is_user_logged_in()) return false;

        $user_id = get_current_user_id();
        $balance = Carno_Wallet_Core::get_user_balance($user_id);

        // فقط اگر موجودی برای پوشش کل سبد خرید کافی باشد نمایش داده شود
        if (!WC()->cart) return false;
        $cart_total = floatval(WC()->cart->get_total('edit'));

        return $balance >= $cart_total && $balance > 0;
    }

    public function process_payment($order_id) {
        $order       = wc_get_order($order_id);
        $user_id     = $order->get_user_id();
        $order_total = floatval($order->get_total());
        $balance     = Carno_Wallet_Core::get_user_balance($user_id);

        // باگ‌فیکس: قبلاً در حالت ناکافی بودن موجودی، پول کم می‌شد ولی failure برمی‌گشت
        // اکنون فقط در صورت کافی بودن موجودی عملیات انجام می‌شود
        if ($balance < $order_total) {
            wc_add_notice('موجودی کیف پول کافی نیست. لطفاً روش پرداخت دیگری انتخاب کنید.', 'error');
            return ['result' => 'failure'];
        }

        Carno_Wallet_Core::deduct_balance($user_id, $order_total);

        $order->payment_complete();
        $order->add_order_note(
            sprintf('پرداخت از کیف پول: %s تومان — موجودی باقیمانده: %s تومان',
                number_format($order_total),
                number_format(Carno_Wallet_Core::get_user_balance($user_id))
            )
        );

        WC()->cart->empty_cart();

        return [
            'result'   => 'success',
            'redirect' => $this->get_return_url($order),
        ];
    }

    // ─── راه‌اندازی کلاس (فقط اگر WooCommerce لود شده باشد) ──

    public static function init() {
        if (!class_exists('WC_Payment_Gateway')) return;
        // کلاس در این مرحله تعریف شده است؛ register از طریق Carno_Wallet_Core انجام می‌شود
    }
}
