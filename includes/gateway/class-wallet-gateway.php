<?php
if (!defined('ABSPATH')) exit;

/**
 * درگاه پرداخت WooCommerce — کیف پول
 * 
 * مسئول پرداخت‌های کامل و جزئی از موجودی کیف پول
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

    // ─── تنظیمات درگاه ───────────────────────────────────────

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
     * بررسی در‌دسترس‌بودن درگاه
     * درگاه فقط برای کاربران لاگین‌شده با موجودی دسترسی دارند
     */
    public function is_available() {
        if (!parent::is_available()) return false;
        if (!Carno_Wallet_Helpers::is_user_logged_in()) return false;

        $user_id = Carno_Wallet_Helpers::get_current_user_id();
        $balance = Carno_Wallet_Helpers::get_user_balance($user_id);

        return $balance > 0;
    }

    /**
     * پردازش پرداخت
     * 
     * @param int $order_id شناسه سفارش
     * @return array نتیجه پرداخت
     */
    public function process_payment($order_id) {
        $order       = wc_get_order($order_id);
        $user_id     = $order->get_user_id();
        $order_total = floatval($order->get_total());
        $balance     = Carno_Wallet_Helpers::get_user_balance($user_id);

        // دریافت مبلغ کسر‌شده از کیف پول
        $wallet_deducted = floatval(WC()->session->get('carno_wallet_deduct_amount') ?? 0);

        if ($wallet_deducted <= 0) {
            wc_add_notice('خطا: کیف پول درست فعال نشد. لطفاً دوباره سعی کنید.', 'error');
            return ['result' => 'failure'];
        }

        if ($balance < $wallet_deducted) {
            wc_add_notice('موجودی کیف پول کافی نیست. لطفاً صفحه را رفرش کنید و دوباره سعی کنید.', 'error');
            return ['result' => 'failure'];
        }

        // کسر کردن موجودی
        Carno_Wallet_Helpers::deduct_balance($user_id, $wallet_deducted);

        // پرداخت کامل
        if ($wallet_deducted >= $order_total) {
            return $this->process_full_payment($order, $wallet_deducted);
        }

        // پرداخت جزئی
        return $this->process_partial_payment($order, $wallet_deducted, $order_total);
    }

    /**
     * پردازش پرداخت کامل
     * 
     * @param WC_Order $order سفارش
     * @param float $wallet_deducted مبلغ کسر‌شده
     * @return array نتیجه
     */
    private function process_full_payment($order, $wallet_deducted) {
        $user_id = $order->get_user_id();
        $remaining_balance = Carno_Wallet_Helpers::get_user_balance($user_id);

        $order->set_payment_method_title('پرداخت از کیف پول');
        $order->payment_complete();
        $order->add_order_note(
            sprintf('✅ پرداخت کامل از کیف پول: %s | موجودی باقی: %s',
                Carno_Wallet_Helpers::format_currency($wallet_deducted),
                Carno_Wallet_Helpers::format_currency($remaining_balance)
            )
        );
        $order->save();

        WC()->cart->empty_cart();

        return [
            'result'   => 'success',
            'redirect' => $this->get_return_url($order),
        ];
    }

    /**
     * پردازش پرداخت جزئی
     * 
     * @param WC_Order $order سفارش
     * @param float $wallet_deducted مبلغ کسر‌شده
     * @param float $order_total کل سفارش
     * @return array نتیجه
     */
    private function process_partial_payment($order, $wallet_deducted, $order_total) {
        $remaining = $order_total - $wallet_deducted;

        $order->set_payment_method_title('کیف پول + درگاه پرداخت');
        $order->update_meta_data('_carno_wallet_partial_payment', true);
        $order->update_meta_data(CARNO_WALLET_ORDER_AMOUNT_KEY, $wallet_deducted);
        $order->update_meta_data('_carno_wallet_amount_remaining', $remaining);
        
        $order->add_order_note(
            sprintf('⚠️ پرداخت جزئی: %s از کیف پول | مبلغ باقی‌مانده: %s',
                Carno_Wallet_Helpers::format_currency($wallet_deducted),
                Carno_Wallet_Helpers::format_currency($remaining)
            )
        );
        $order->save();

        wc_add_notice(
            sprintf('مبلغ %s کم شد. لطفاً %s را پرداخت کنید.',
                Carno_Wallet_Helpers::format_currency($wallet_deducted),
                Carno_Wallet_Helpers::format_currency($remaining)
            ),
            'notice'
        );

        WC()->cart->empty_cart();

        return [
            'result'   => 'success',
            'redirect' => $order->get_checkout_payment_url(),
        ];
    }
}
