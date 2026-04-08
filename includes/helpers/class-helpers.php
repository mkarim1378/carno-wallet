<?php
if (!defined('ABSPATH')) exit;

/**
 * کلاس کمکی: تابع‌های مشترک برای کیف پول
 * 
 * این کلاس شامل عملیات مشترک مانند:
 * - مدیریت موجودی کاربر
 * - فرمت‌کردن مبالغ
 * - تایید اعتبارات
 */
class Carno_Wallet_Helpers {

    /**
     * دریافت موجودی کاربر
     * 
     * @param int $user_id شناسه کاربر
     * @return float موجودی به‌روز
     */
    public static function get_user_balance($user_id) {
        $balance = get_user_meta((int) $user_id, CARNO_WALLET_META_KEY, true);
        return $balance !== '' ? floatval($balance) : 0.0;
    }

    /**
     * تنظیم موجودی کاربر
     * 
     * @param int $user_id شناسه کاربر
     * @param float $amount مقدار جدید
     * @return bool نتیجه عملیات
     */
    public static function set_user_balance($user_id, $amount) {
        return update_user_meta((int) $user_id, CARNO_WALLET_META_KEY, max(0, floatval($amount)));
    }

    /**
     * کسر موجودی از کیف پول کاربر
     * 
     * @param int $user_id شناسه کاربر
     * @param float $amount مقدار برای کسر
     * @return float موجودی کسر‌شده‌ی جدید
     */
    public static function deduct_balance($user_id, $amount) {
        $current = self::get_user_balance($user_id);
        $new_balance = max(0, $current - floatval($amount));
        return self::set_user_balance($user_id, $new_balance) ? $new_balance : $current;
    }

    /**
     * اضافه‌کردن موجودی به کیف پول کاربر
     * 
     * @param int $user_id شناسه کاربر
     * @param float $amount مقدار برای اضافه‌کردن
     * @return float موجودی جدید
     */
    public static function add_to_balance($user_id, $amount) {
        $current = self::get_user_balance($user_id);
        $new_balance = $current + floatval($amount);
        return self::set_user_balance($user_id, $new_balance) ? $new_balance : $current;
    }

    /**
     * بررسی کافی‌بودن موجودی
     * 
     * @param int $user_id شناسه کاربر
     * @param float $required_amount مقدار مورد نیاز
     * @return bool آیا موجودی کافی است
     */
    public static function has_sufficient_balance($user_id, $required_amount) {
        $balance = self::get_user_balance($user_id);
        return $balance >= floatval($required_amount);
    }

    /**
     * فرمت‌کردن عدد به صورت پول (جداکننده هزار)
     * 
     * @param float $amount مقدار
     * @param bool $with_currency اضافه‌کردن نام ارز
     * @return string مقدار فرمت‌شده
     */
    public static function format_currency($amount, $with_currency = true) {
        $formatted = number_format($amount) . ' تومان';
        return $formatted;
    }

    /**
     * تبدیل مقدار متنی به عدد صحیح
     * 
     * @param string $value مقدار متنی
     * @return float عدد
     */
    public static function sanitize_amount($value) {
        // حذف علائم پول و کاما
        $value = str_replace(['تومان', ',', ' '], '', (string) $value);
        return floatval($value);
    }

    /**
     * بررسی معتبربودن مقدار
     * 
     * @param float $amount مقدار
     * @return bool آیا معتبر است
     */
    public static function is_valid_amount($amount) {
        $amount = floatval($amount);
        return $amount > 0 && is_finite($amount);
    }

    /**
     * دریافت نام کاربر یا ایمیل
     * 
     * @param int $user_id شناسه کاربر
     * @return string نام نمایشی
     */
    public static function get_user_display_name($user_id) {
        $user = get_userdata($user_id);
        return $user ? $user->display_name : '';
    }

    /**
     * بررسی اینکه کاربر لاگین کرده است
     * 
     * @return bool آیا کاربر لاگین شده است
     */
    public static function is_user_logged_in() {
        return is_user_logged_in();
    }

    /**
     * دریافت شناسه کاربر فعلی
     * 
     * @return int شناسه کاربر
     */
    public static function get_current_user_id() {
        return get_current_user_id();
    }

    /**
     * بررسی دسترسی مدیریتی
     * 
     * @return bool آیا کاربر مدیر است
     */
    public static function current_user_can_manage_wallet() {
        return current_user_can('manage_options');
    }
}
