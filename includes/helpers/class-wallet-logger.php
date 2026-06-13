<?php
if (!defined('ABSPATH')) exit;

/**
 * لاگ متمرکز افزونه (wp_carno_wallet_logs)
 *
 * مسئول ایجاد/به‌روزرسانی جدول لاگ، ثبت رویدادها (مثلاً نتیجه ارسال پیامک کش‌بک)
 * و پاک‌سازی خودکار رکوردهای قدیمی‌تر از ۳۰ روز.
 */
class Carno_Wallet_Logger {

    const DB_VERSION        = '1.0';
    const DB_VERSION_OPTION = 'carno_wallet_logs_db_version';
    const RETENTION_DAYS    = 30;

    public static function table_name() {
        global $wpdb;
        return $wpdb->prefix . 'carno_wallet_logs';
    }

    /**
     * در صورت نیاز، جدول لاگ را ایجاد/به‌روزرسانی می‌کند (idempotent)
     */
    public static function maybe_install() {
        if (get_option(self::DB_VERSION_OPTION) !== self::DB_VERSION) {
            self::create_table();
            update_option(self::DB_VERSION_OPTION, self::DB_VERSION);
        }
    }

    private static function create_table() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table           = self::table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            level VARCHAR(20) NOT NULL,
            channel VARCHAR(40) NOT NULL,
            message TEXT NOT NULL,
            context LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY level (level),
            KEY channel (channel),
            KEY created_at (created_at)
        ) {$charset_collate};";

        dbDelta($sql);
    }

    /**
     * ثبت یک رکورد لاگ
     *
     * @param string $level   info|error
     * @param string $channel کانال رویداد (مثلاً sms)
     * @param string $message پیام انسانی
     * @param array  $context داده‌های کمکی (به‌صورت JSON ذخیره می‌شود)
     */
    public static function log($level, $channel, $message, $context = []) {
        global $wpdb;

        return false !== $wpdb->insert(
            self::table_name(),
            [
                'level'      => sanitize_key($level),
                'channel'    => sanitize_key($channel),
                'message'    => sanitize_text_field($message),
                'context'    => !empty($context) ? wp_json_encode($context) : null,
                'created_at' => current_time('mysql'),
            ],
            ['%s', '%s', '%s', '%s', '%s']
        );
    }

    /**
     * دریافت لاگ‌ها به‌صورت صفحه‌بندی‌شده (جدیدترین اول)
     *
     * @param array $args page, per_page, level, channel
     * @return array{rows: array, total: int}
     */
    public static function get_logs($args = []) {
        global $wpdb;

        $page     = max(1, (int) ($args['page'] ?? 1));
        $per_page = max(1, (int) ($args['per_page'] ?? 20));
        $offset   = ($page - 1) * $per_page;

        $where  = [];
        $params = [];

        if (!empty($args['level'])) {
            $where[]  = 'level = %s';
            $params[] = sanitize_key($args['level']);
        }
        if (!empty($args['channel'])) {
            $where[]  = 'channel = %s';
            $params[] = sanitize_key($args['channel']);
        }

        $where_sql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
        $table     = self::table_name();

        $total_sql = "SELECT COUNT(*) FROM {$table} {$where_sql}";
        $total     = (int) (empty($params) ? $wpdb->get_var($total_sql) : $wpdb->get_var($wpdb->prepare($total_sql, $params)));

        $rows_sql = "SELECT * FROM {$table} {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d";
        $rows     = $wpdb->get_results($wpdb->prepare($rows_sql, array_merge($params, [$per_page, $offset])));

        return ['rows' => $rows ?: [], 'total' => $total];
    }

    /**
     * حذف رکوردهای قدیمی‌تر از RETENTION_DAYS روز
     */
    public static function cleanup() {
        global $wpdb;
        $table = self::table_name();

        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE created_at < %s",
            gmdate('Y-m-d H:i:s', time() - self::RETENTION_DAYS * DAY_IN_SECONDS)
        ));
    }
}
