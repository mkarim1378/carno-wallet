<?php
if (!defined('ABSPATH')) exit;

/**
 * کلاس مدیریت سفارش‌ها
 * 
 * نمایش اطلاعات کیف پول در پنل ادمین WooCommerce
 */
class Carno_Wallet_Order {

    private static $instance = null;

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // اضافه‌کردن در صفحه سفارش تفصیلی (جدول محصولات)
        add_action('woocommerce_admin_order_items_after_line_items', [$this, 'display_wallet_info']);
        
        // نمایش اطلاعات کیف پول در قسمت totals (نمایش شده در modal و صفحه تفصیلی)
        add_action('woocommerce_admin_order_totals_after_total', [$this, 'display_wallet_info_in_totals']);
        
        // نمایش اطلاعات کیف پول در preview سفارش (frontend)
        // اضافه‌کردن رو در پنل سفارش
        add_action('woocommerce_admin_order_items_after_line_items', [$this, 'display_wallet_info']);
        
        // نمایش اطلاعات کیف پول در preview سفارش
        add_action('woocommerce_order_details_after_order_table', [$this, 'display_wallet_info_frontend']);
    }

    // ─── نمایش در پنل ادمین ──────────────────────────────────

    /**
     * نمایش اطلاعات کیف پول در قسمت totals
     * این hook در modal و صفحه تفصیلی نمایش داده می‌شود
     */
    public function display_wallet_info_in_totals($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        $wallet_amount = $order->get_meta(CARNO_WALLET_ORDER_AMOUNT_KEY, true);

        // اگر کیف پول استفاده نشده، نمایش ندهید
        if (!$wallet_amount || $wallet_amount <= 0) {
            return;
        }

        $is_full_payment = $order->get_meta(CARNO_WALLET_ORDER_FULL_PAYMENT_KEY, true);
        $is_partial = $order->get_meta('_carno_wallet_partial_payment', true);
        $remaining = $order->get_meta('_carno_wallet_amount_remaining', true);
        
        ?>
        <tr>
            <td class="label">💳 کیف پول:</td>
            <td width="1%"></td>
            <td class="total">
                <strong><?php echo esc_html(Carno_Wallet_Helpers::format_currency($wallet_amount)); ?></strong>
                <?php if ($is_full_payment): ?>
                    <br><small style="color: #27ae60;">✅ پرداخت کامل</small>
                <?php elseif ($is_partial): ?>
                    <br><small style="color: #f39c12;">⚠️ پرداخت جزئی</small>
                    <?php if ($remaining): ?>
                        <br><small style="color: #f39c12;">باقی: <?php echo esc_html(Carno_Wallet_Helpers::format_currency($remaining)); ?></small>
                    <?php endif; ?>
                <?php endif; ?>
            </td>
        </tr>
        <?php
    }

    /**
     * نمایش اطلاعات کیف پول در پنل ادمین (بعد از محصولات)
     */
    public function display_wallet_info($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) return;

        $wallet_amount = $order->get_meta(CARNO_WALLET_ORDER_AMOUNT_KEY, true);
        $is_full_payment = $order->get_meta(CARNO_WALLET_ORDER_FULL_PAYMENT_KEY, true);
        $is_partial = $order->get_meta('_carno_wallet_partial_payment', true);

        // اگر کیف پول استفاده نشده، نمایش ندهید
        if (!$wallet_amount || $wallet_amount <= 0) {
            return;
        }

        ?>
        <tr>
            <td colspan="3" style="padding: 10px; background: #f0f0f1; border-top: 2px solid #ddd;">
                <strong style="display: block; margin-bottom: 8px;">💳 اطلاعات کیف پول</strong>
                <div style="margin-left: 20px;">
                    <?php
                    $formatted_amount = Carno_Wallet_Helpers::format_currency($wallet_amount);
                    echo '<p style="margin: 5px 0;"><strong>مبلغ استفاده‌شده:</strong> ' . esc_html($formatted_amount) . '</p>';
                    
                    if ($is_full_payment) {
                        echo '<p style="margin: 5px 0; color: #27ae60;"><strong>✅ نوع پرداخت:</strong> پرداخت کامل از کیف پول</p>';
                    } elseif ($is_partial) {
                        $remaining = $order->get_meta('_carno_wallet_amount_remaining', true);
                        if ($remaining) {
                            $formatted_remaining = Carno_Wallet_Helpers::format_currency($remaining);
                            echo '<p style="margin: 5px 0; color: #f39c12;"><strong>⚠️ نوع پرداخت:</strong> پرداخت جزئی</p>';
                            echo '<p style="margin: 5px 0; color: #f39c12;"><strong>مبلغ باقی‌مانده:</strong> ' . esc_html($formatted_remaining) . '</p>';
                        }
                    }
                    ?>
                </div>
            </td>
        </tr>
        <?php
    }

    /**
     * نمایش اطلاعات کیف پول در صفحه سفارش (frontend)
     */
    public function display_wallet_info_frontend($order) {
        if (!$order || !is_a($order, 'WC_Order')) {
            return;
        }

        $wallet_amount = $order->get_meta(CARNO_WALLET_ORDER_AMOUNT_KEY, true);
        if (!$wallet_amount || $wallet_amount <= 0) {
            return;
        }

        $is_full_payment = $order->get_meta(CARNO_WALLET_ORDER_FULL_PAYMENT_KEY, true);
        $is_partial = $order->get_meta('_carno_wallet_partial_payment', true);

        ?>
        <div style="margin-top: 20px; padding: 15px; background: #f9f9f9; border-left: 4px solid #3498db; border-radius: 4px;">
            <h3 style="margin-top: 0; color: #3498db;">💳 اطلاعات پرداخت از کیف پول</h3>
            <table style="width: 100%; border-collapse: collapse;">
                <tr>
                    <td style="padding: 8px; border-bottom: 1px solid #e0e0e0;"><strong>مبلغ کسر‌شده از کیف پول:</strong></td>
                    <td style="padding: 8px; border-bottom: 1px solid #e0e0e0; text-align: left;">
                        <?php echo esc_html(Carno_Wallet_Helpers::format_currency($wallet_amount)); ?>
                    </td>
                </tr>
                <tr>
                    <td style="padding: 8px; border-bottom: 1px solid #e0e0e0;"><strong>نوع پرداخت:</strong></td>
                    <td style="padding: 8px; border-bottom: 1px solid #e0e0e0; text-align: left;">
                        <?php
                        if ($is_full_payment) {
                            echo '<span style="color: #27ae60; font-weight: bold;">✅ پرداخت کامل</span>';
                        } elseif ($is_partial) {
                            echo '<span style="color: #f39c12; font-weight: bold;">⚠️ پرداخت جزئی</span>';
                            $remaining = $order->get_meta('_carno_wallet_amount_remaining', true);
                            if ($remaining) {
                                echo '<br><small>مبلغ باقی‌مانده: ' . esc_html(Carno_Wallet_Helpers::format_currency($remaining)) . '</small>';
                            }
                        } else {
                            echo 'نامشخص';
                        }
                        ?>
                    </td>
                </tr>
            </table>
        </div>
        <?php
    }
}
