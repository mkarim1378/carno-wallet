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
        // هوک‌های سبد خرید
        add_action('woocommerce_cart_contents', [$this, 'display_wallet_option']);
        add_action('woocommerce_after_cart', [$this, 'display_wallet_notice']);
        
        // درخواست‌های AJAX
        add_action('wp_ajax_carno_apply_wallet_credit', [$this, 'ajax_apply_wallet_credit']);
        add_action('wp_ajax_carno_remove_wallet_credit', [$this, 'ajax_remove_wallet_credit']);
        
        // اعمال خصم به قیمت
        add_action('woocommerce_cart_calculate_fees', [$this, 'apply_wallet_discount']);
        
        // هوک‌های پرداخت
        add_action('woocommerce_checkout_init', [$this, 'checkout_init']);
        add_action('woocommerce_before_checkout_form', [$this, 'display_wallet_info_checkout']);
        
        // ذخیره اطلاعات کیف پول در سفارش
        add_action('woocommerce_checkout_create_order', [$this, 'save_wallet_to_order']);
        add_action('woocommerce_payment_complete', [$this, 'process_wallet_payment']);
        
        // کم‌کردن موجودی پس از پرداخت موفق (برای گیت‌وی های غیر-کیف‌پول)
        add_action('woocommerce_order_status_changed', [$this, 'handle_wallet_deduction_on_order_status_change'], 10, 3);
        
        // اسکریپت‌های جاوا اسکریپت
        add_action('wp_enqueue_scripts', [$this, 'enqueue_wallet_scripts']);

        // پاک‌کردن سشن پس از سفارش
        add_action('woocommerce_thankyou', [$this, 'clear_wallet_session']);
        
        // هندلر انتخاب روش پرداخت
        add_action('wp_footer', [$this, 'add_payment_method_script']);
    }

    /**
     * صفحه سبد خرید: نمایش گزینه استفاده از کیف پول
     */
    public function display_wallet_option() {
        if (!is_user_logged_in()) return;

        $user_id = get_current_user_id();
        $balance = Carno_Wallet_Core::get_user_balance($user_id);

        if ($balance <= 0) return;

        $cart_total = floatval(WC()->cart->get_total('edit'));
        $is_using_wallet = $this->is_wallet_credit_applied();
        
        ?>
        <tr>
            <td colspan="6" style="text-align: center; padding: 20px; background: #f0f7ff; border-top: 2px solid #0073aa;">
                <div id="carno-wallet-section" style="max-width: 600px; margin: 0 auto;">
                    <h3 style="margin: 0 0 15px 0; color: #0073aa;">💳 استفاده از کیف پول</h3>
                    
                    <div style="background: white; padding: 15px; border-radius: 5px; text-align: right;">
                        <p style="margin: 0 0 10px 0;">
                            <strong>موجودی کیف پول شما:</strong> 
                            <span style="color: #27ae60; font-size: 18px;">
                                <?php echo number_format($balance); ?>
                            </span> تومان
                        </p>

                        <?php if ($balance >= $cart_total): ?>
                            <p style="margin: 10px 0; color: #27ae60;">
                                ✓ موجودی شما برای پوشش کل سفارش کافی است!
                            </p>
                        <?php else: ?>
                            <p style="margin: 10px 0; color: #e74c3c;">
                                حداقل <?php echo number_format($cart_total - $balance); ?> تومان دیگر نیاز دارید.
                            </p>
                        <?php endif; ?>

                        <button 
                            type="button" 
                            class="button button-primary" 
                            id="carno-wallet-toggle-btn"
                            data-balance="<?php echo esc_attr($balance); ?>"
                            data-cart-total="<?php echo esc_attr($cart_total); ?>"
                            style="margin-top: 10px; cursor: pointer; width: 100%; padding: 10px;">
                            <?php echo $is_using_wallet ? 'لغو استفاده از کیف پول' : 'استفاده از کیف پول'; ?>
                        </button>

                        <?php if ($is_using_wallet && $balance < $cart_total): ?>
                            <p style="margin: 15px 0 0 0; padding: 10px; background: #fff3cd; border-radius: 3px; color: #856404;">
                                ℹ️ مبلغ کیف پول شما (<?php echo number_format($balance); ?> تومان) کم می‌شود و مابقی از طریق درگاه پرداخت دریافت خواهد شد.
                            </p>
                        <?php endif; ?>
                    </div>
                </div>
            </td>
        </tr>
        <?php
    }

    /**
     * نمایش پیام در سبد خرید
     */
    public function display_wallet_notice() {
        if (!is_user_logged_in()) return;

        $is_using_wallet = $this->is_wallet_credit_applied();
        if (!$is_using_wallet) return;

        $user_id = get_current_user_id();
        $balance = Carno_Wallet_Core::get_user_balance($user_id);
        $cart_total = floatval(WC()->cart->get_total('edit'));

        $to_deduct = min($balance, $cart_total);

        wc_print_notice(
            sprintf(
                '<strong>✓ کیف پول فعال است</strong><br>مبلغ %s تومان از کیف پول کم خواهد شد.',
                number_format($to_deduct)
            ),
            'notice'
        );
    }

    /**
     * AJAX: اعمال اعتبار کیف پول
     */
    public function ajax_apply_wallet_credit() {
        check_ajax_referer('carno_wallet_nonce', 'nonce');

        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => 'لطفاً وارد شوید.']);
        }

        WC()->session->set(self::WALLET_SESSION_KEY, true);

        wp_send_json_success([
            'message' => 'کیف پول فعال شد.',
            'wallet_applied' => true,
        ]);
    }

    /**
     * AJAX: حذف اعتبار کیف پول
     */
    public function ajax_remove_wallet_credit() {
        check_ajax_referer('carno_wallet_nonce', 'nonce');

        WC()->session->set(self::WALLET_SESSION_KEY, false);

        wp_send_json_success([
            'message' => 'استفاده از کیف پول لغو شد.',
            'wallet_applied' => false,
        ]);
    }

    /**
     * اعمال خصم/جایزه به سبد خرید
     */
    public function apply_wallet_discount() {
        if (is_admin() && !defined('DOING_AJAX')) return;
        if (!is_user_logged_in()) return;

        $is_using_wallet = $this->is_wallet_credit_applied();
        if (!$is_using_wallet) return;

        $user_id = get_current_user_id();
        $balance = Carno_Wallet_Core::get_user_balance($user_id);

        if ($balance <= 0) return;

        // محاسبه مبلغی که باید از کیف پول کم شود
        $cart_total = floatval(WC()->cart->get_total('edit'));
        $deduct_amount = min($balance, $cart_total);

        // اعمال به عنوان جایزه منفی (خصم)
        WC()->cart->add_fee(
            __('تخفیف کیف پول', 'carno-wallet') . ': -' . number_format($deduct_amount) . ' تومان',
            -floatval($deduct_amount)
        );

        // ذخیره مبلغ در سشن برای استفاده در پرداخت
        WC()->session->set('carno_wallet_deduct_amount', $deduct_amount);
    }

    /**
     * شروع صفحه تسویه‌حساب
     */
    public function checkout_init() {
        if (!is_user_logged_in()) return;

        $is_using_wallet = $this->is_wallet_credit_applied();
        if (!$is_using_wallet) {
            WC()->session->set('carno_wallet_deduct_amount', null);
        }
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
            $order->update_meta_data('_carno_wallet_used', true);
            $order->update_meta_data('_carno_wallet_amount', floatval($deduct_amount));
        }
    }

    /**
     * لود اسکریپت جاوا اسکریپت
     */
    public function enqueue_wallet_scripts() {
        if (!is_user_logged_in()) return;

        wp_enqueue_script(
            'carno-wallet-cart',
            CARNO_WALLET_URL . 'assets/wallet-cart.js',
            ['jquery'],
            CARNO_WALLET_VERSION,
            true
        );

        wp_localize_script('carno-wallet-cart', 'carnoWallet', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('carno_wallet_nonce'),
        ]);
    }

    /**
     * بررسی اینکه آیا با کیف پول استفاده می‌شود
     */
    public function is_wallet_credit_applied() {
        return WC()->session->get(self::WALLET_SESSION_KEY) === true;
    }

    /**
     * پاک‌کردن سشن پس از تکمیل سفارش
     */
    public function clear_wallet_session() {
        WC()->session->set(self::WALLET_SESSION_KEY, false);
        WC()->session->set('carno_wallet_deduct_amount', null);
    }

    /**
     * دریافت مبلغ کم‌شده از کیف پول
     */
    public static function get_deducted_amount($order_id = null) {
        if ($order_id) {
            $order = wc_get_order($order_id);
            return floatval($order->get_meta('_carno_wallet_amount', true) ?? 0);
        }
        return floatval(WC()->session->get('carno_wallet_deduct_amount') ?? 0);
    }

    /**
     * بررسی اینکه آیا سفارش از کیف پول استفاده کرده
     */
    public static function order_used_wallet($order_id) {
        $order = wc_get_order($order_id);
        return $order->get_meta('_carno_wallet_used', true) === true;
    }

    /**
     * پردازش پرداخت کامل یا جزئی از کیف پول
     */
    public function process_wallet_payment($order_id) {
        $order = wc_get_order($order_id);

        $wallet_amount = $order->get_meta('_carno_wallet_amount', true);
        if (!$wallet_amount || $wallet_amount <= 0) return;

        $user_id = $order->get_user_id();
        $current_balance = Carno_Wallet_Core::get_user_balance($user_id);

        $order->add_order_note(
            sprintf('موجودی کیف پول فعلی پس از پرداخت: %s تومان',
                number_format($current_balance)
            )
        );
    }

    /**
     * هندلینگ کم‌کردن کیف پول وقتی سفارش به‌صورت پرداخت‌شده علامت‌گذاری شود
     * این برای مواردی است که کاربر کیف پول را استفاده کرد از طریق گیت‌وی های دیگر
     */
    public function handle_wallet_deduction_on_order_status_change($order_id, $old_status, $new_status) {
        // فقط وقتی سفارش به حالت "processing" یا "completed" برود
        if (!in_array($new_status, ['processing', 'completed'])) return;

        $order = wc_get_order($order_id);

        // بررسی اینکه آیا این سفارش قبلاً پردازش شده
        if ($order->get_meta('_carno_wallet_deducted', true)) return;

        $wallet_amount = $order->get_meta('_carno_wallet_amount', true);
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
        $order->update_meta_data('_carno_wallet_deducted', true);
        $order->save();

        $new_balance = Carno_Wallet_Core::get_user_balance($user_id);

        $order->add_order_note(
            sprintf('✓ موجودی کیف پول کاهش یافت: %s تومان | موجودی جدید: %s تومان',
                number_format($wallet_amount),
                number_format($new_balance)
            )
        );
    }

    /**
     * اسکریپت برای هندلینگ تغییر روش پرداخت
     */
    public function add_payment_method_script() {
        if (!is_checkout() || !is_user_logged_in()) return;

        ?>
        <script type="text/javascript">
        jQuery(function($) {
            // وقتی روش پرداخت تغییر کند، اگر کیف پول فعال است، آن را نگه دارید
            // ولی اگر روش پرداخت غیر کیف پولی است، این اختیار است
            
            $(document.body).on('updated_checkout', function() {
                // این می‌تواند برای به‌روز‌رسانی صفحه برای تغییرات روش پرداخت استفاده شود
            });
        });
        </script>
        <?php
    }
}
