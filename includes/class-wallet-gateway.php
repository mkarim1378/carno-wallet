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
     * 
     * حالا درگاه برای پرداخت‌های جزئی هم موجود است
     */
    public function is_available() {
        if (!parent::is_available()) return false;
        if (!is_user_logged_in()) return false;

        $user_id = get_current_user_id();
        $balance = Carno_Wallet_Core::get_user_balance($user_id);

        // موجودی باید بیش از صفر باشد
        return $balance > 0;
    }

    public function process_payment($order_id) {
        $order       = wc_get_order($order_id);
        $user_id     = $order->get_user_id();
        $order_total = floatval($order->get_total());
        $balance     = Carno_Wallet_Core::get_user_balance($user_id);

        // بررسی اینکه آیا کاربر کیف پول را در سبد خرید انتخاب کرده
        $wallet_deducted = Carno_Wallet_Cart::get_deducted_amount($order_id);

        // اگر هیچ مبلغی از کیف پول کم نشده، خطا
        if ($wallet_deducted <= 0) {
            wc_add_notice('خطا: کیف پول درست فعال نشد. لطفاً دوباره سعی کنید.', 'error');
            return ['result' => 'failure'];
        }

        // بررسی اینکه موجودی برای کم‌کردن کافی باشد
        if ($balance < $wallet_deducted) {
            wc_add_notice('موجودی کیف پول کافی نیست. لطفاً صفحه را رفرش کنید و دوباره سعی کنید.', 'error');
            return ['result' => 'failure'];
        }

        // کم‌کردن موجودی
        Carno_Wallet_Core::deduct_balance($user_id, $wallet_deducted);

        // اگر کل مبلغ از کیف پول پرداخت شده، سفارش کامل است
        if ($wallet_deducted >= $order_total) {
            $order->set_payment_method_title('پرداخت از کیف پول');
            $order->payment_complete();
            $order->add_order_note(
                sprintf('پرداخت کامل از کیف پول: %s تومان — موجودی باقیمانده: %s تومان',
                    number_format($wallet_deducted),
                    number_format(Carno_Wallet_Core::get_user_balance($user_id))
                )
            );
            WC()->cart->empty_cart();

            return [
                'result'   => 'success',
                'redirect' => $this->get_return_url($order),
            ];
        }

        // اگر پرداخت جزئی است، سفارش را نیمه‌تکمیل علامت‌گذاری کنید
        $remaining = $order_total - $wallet_deducted;
        $order->set_payment_method_title(sprintf('کیف پول + درگاه پرداخت'));
        $order->update_meta_data('_carno_wallet_partial_payment', true);
        $order->update_meta_data('_carno_wallet_amount_paid', $wallet_deducted);
        $order->update_meta_data('_carno_wallet_amount_remaining', $remaining);
        
        $order->add_order_note(
            sprintf('پرداخت جزئی از کیف پول: %s تومان | مبلغ باقی‌مانده: %s تومان',
                number_format($wallet_deducted),
                number_format($remaining)
            )
        );

        $order->save();

        // نوت برای ادمین که سفارش در انتظار پرداخت باقی‌مانده است
        wc_add_notice(
            sprintf('مبلغ %s تومان از کیف پول کم شد. لطفاً مبلغ %s تومان باقی‌مانده را پرداخت کنید.',
                number_format($wallet_deducted),
                number_format($remaining)
            ),
            'notice'
        );

        WC()->cart->empty_cart();

        // هدایت به صفحه تشکر (ولی نه به عنوان سفارش کامل)
        return [
            'result'   => 'success',
            'redirect' => $order->get_checkout_payment_url(),
        ];
    }

    // ─── راه‌اندازی کلاس (فقط اگر WooCommerce لود شده باشد) ──

    public static function init() {
        if (!class_exists('WC_Payment_Gateway')) return;
        // کلاس در این مرحله تعریف شده است؛ register از طریق Carno_Wallet_Core انجام می‌شود
    }
}
