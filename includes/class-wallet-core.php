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
}
