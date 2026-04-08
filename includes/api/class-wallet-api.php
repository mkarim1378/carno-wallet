<?php
if (!defined('ABSPATH')) exit;

/**
 * REST API Endpoints برای کیف پول
 * 
 * منظور این کلاس مدیریت تمام REST API endpoints است:
 * - دریافت موجودی
 * - شارژ کیف پول
 * - برداشت
 * - تاریخچه تراکنش‌ها
 */
class Carno_Wallet_API {

    private static $instance = null;
    const API_NAMESPACE = 'carno-wallet/v1';

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    // ─── ثبت‌نام endpoint‌های API ──────────────────────────────

    /**
     * ثبت‌نام تمام routes API
     */
    public function register_routes() {
        // TODO: API endpoints را در آینده پیاده‌سازی کنید
        
        // مثال:
        // register_rest_route(self::API_NAMESPACE, '/balance', [
        //     'methods' => 'GET',
        //     'callback' => [$this, 'get_user_balance'],
        //     'permission_callback' => [$this, 'check_permission'],
        // ]);
    }

    // ─── API Endpoints ──────────────────────────────────────────

    /**
     * دریافت موجودی کاربر فعلی
     * GET /wp-json/carno-wallet/v1/balance
     */
    public function get_user_balance($request) {
        $user_id = Carno_Wallet_Helpers::get_current_user_id();
        if (!$user_id) {
            return new WP_Error('not_logged_in', 'کاربر لاگین نکرده است', ['status' => 401]);
        }

        $balance = Carno_Wallet_Helpers::get_user_balance($user_id);

        return rest_ensure_response([
            'user_id' => $user_id,
            'balance' => $balance,
            'formatted' => Carno_Wallet_Helpers::format_currency($balance),
        ]);
    }

    // ─── بررسی دسترسی ──────────────────────────────────────────

    /**
     * بررسی دسترسی کاربر
     */
    public function check_permission($request) {
        return Carno_Wallet_Helpers::is_user_logged_in();
    }

    /**
     * بررسی دسترسی مدیریتی
     */
    public function check_admin_permission($request) {
        return Carno_Wallet_Helpers::current_user_can_manage_wallet();
    }
}
