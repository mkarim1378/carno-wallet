<?php
if (!defined('ABSPATH')) exit;

/**
 * مدیریت جدول اختصاصی تراکنش‌های کیف پول (wp_wallet_transactions)
 *
 * این کلاس مسئول ایجاد/به‌روزرسانی جدول، مهاجرت یک‌بارهٔ موجودی فعلی کاربران
 * به‌عنوان «مانده افتتاحیه»، و ثبت هر تراکنش بعدی است.
 */
class Carno_Wallet_Transactions {

    const DB_VERSION        = '1.0';
    const DB_VERSION_OPTION = 'carno_wallet_db_version';
    const MIGRATION_OPTION  = 'carno_wallet_balance_migrated';

    // انواع معتبر تراکنش - هر مقدار دیگری به admin_adjustment تبدیل می‌شود
    const TYPES = [
        'migration_opening_balance',
        'admin_adjustment',
        'excel_import',
        'cashback',
        'refund',
        'purchase',
    ];

    /**
     * نام کامل جدول با پیشوند دیتابیس
     */
    public static function table_name() {
        global $wpdb;
        return $wpdb->prefix . 'wallet_transactions';
    }

    /**
     * در صورت نیاز، جدول را ایجاد/به‌روزرسانی کرده و مهاجرت موجودی‌های فعلی را اجرا می‌کند
     * idempotent است و می‌تواند در هر درخواست صدا زده شود (هزینهٔ آن فقط دو get_option کش‌شده است)
     */
    public static function maybe_install() {
        if (get_option(self::DB_VERSION_OPTION) !== self::DB_VERSION) {
            self::create_table();
            update_option(self::DB_VERSION_OPTION, self::DB_VERSION);
        }

        if (get_option(self::MIGRATION_OPTION) !== 'yes') {
            self::migrate_existing_balances();
            update_option(self::MIGRATION_OPTION, 'yes');
        }
    }

    /**
     * ایجاد جدول تراکنش‌ها با dbDelta (idempotent)
     */
    private static function create_table() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table           = self::table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            type VARCHAR(32) NOT NULL,
            amount DECIMAL(15,2) NOT NULL,
            balance_after DECIMAL(15,2) NOT NULL,
            order_id BIGINT UNSIGNED NULL,
            description TEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY user_id (user_id),
            KEY type (type),
            KEY order_id (order_id)
        ) {$charset_collate};";

        dbDelta($sql);
    }

    /**
     * انتقال موجودی فعلی کاربران (از wp_usermeta) به جدول تراکنش‌ها به‌عنوان یک رکورد «مانده افتتاحیه»
     * فقط یک‌بار اجرا می‌شود (با MIGRATION_OPTION کنترل می‌شود)
     */
    private static function migrate_existing_balances() {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s",
            CARNO_WALLET_META_KEY
        ));

        if (!$rows) return;

        foreach ($rows as $row) {
            $balance = floatval($row->meta_value);
            if ($balance <= 0) continue;

            self::log(
                (int) $row->user_id,
                'migration_opening_balance',
                $balance,
                $balance,
                null,
                'موجودی منتقل‌شده از نسخه قبل از فعال‌سازی تاریخچه تراکنش‌ها'
            );
        }
    }

    /**
     * ثبت یک تراکنش جدید
     *
     * @param int $user_id شناسه کاربر
     * @param string $type نوع تراکنش (یکی از مقادیر TYPES)
     * @param float $amount مقدار تغییر (مثبت = افزایش موجودی، منفی = کاهش)
     * @param float $balance_after موجودی کاربر پس از این تراکنش
     * @param int|null $order_id شناسه سفارش مرتبط (اختیاری)
     * @param string $description توضیح کوتاه
     * @return bool نتیجه درج
     */
    public static function log($user_id, $type, $amount, $balance_after, $order_id = null, $description = '') {
        global $wpdb;

        if (!in_array($type, self::TYPES, true)) {
            $type = 'admin_adjustment';
        }

        return false !== $wpdb->insert(
            self::table_name(),
            [
                'user_id'       => (int) $user_id,
                'type'          => $type,
                'amount'        => floatval($amount),
                'balance_after' => floatval($balance_after),
                'order_id'      => $order_id !== null ? (int) $order_id : null,
                'description'   => sanitize_text_field($description),
                'created_at'    => current_time('mysql'),
            ],
            ['%d', '%s', '%f', '%f', '%d', '%s', '%s']
        );
    }

    /**
     * دریافت تراکنش‌های یک کاربر (جدیدترین اول)
     *
     * @param int $user_id شناسه کاربر
     * @param int $limit حداکثر تعداد ردیف
     * @return array لیست تراکنش‌ها
     */
    public static function get_user_transactions($user_id, $limit = 10) {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM " . self::table_name() . " WHERE user_id = %d ORDER BY id DESC LIMIT %d",
            (int) $user_id, (int) $limit
        ));
    }
}
