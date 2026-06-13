<?php
if (!defined('ABSPATH')) exit;

/**
 * پنل تنظیمات افزونه کیف پول
 *
 * تمام مقادیر قابل تنظیم افزونه از طریق این کلاس مدیریت می‌شوند.
 * سایر کلاس‌ها باید از getterهای static این کلاس استفاده کنند.
 */
class Carno_Wallet_Settings {

    const OPTION_KEY = 'carno_wallet_settings';

    private static $instance = null;
    private static $cache    = null;

    private static $defaults = [
        'wallet_max_ratio'    => 80,
        'cashback_enabled'    => '1',
        'cashback_ratio'      => 10,
        'deduction_statuses'  => ['processing', 'completed'],
        'gateway_title'       => 'کیف پول',
        'gateway_description' => 'پرداخت کامل از موجودی کیف پول شما',
        'refund_to_wallet'    => '1',
        'max_balance'         => 0,
    ];

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu',  [$this, 'add_settings_page'], 20);
        add_action('admin_init',  [$this, 'register_settings']);
    }

    // ─── منوی ادمین ────────────────────────────────────────────

    public function add_settings_page() {
        add_submenu_page(
            'user-wallet',
            'تنظیمات کیف پول',
            'تنظیمات',
            'manage_options',
            'user-wallet-settings',
            [$this, 'render_page']
        );
    }

    // ─── ثبت تنظیمات ───────────────────────────────────────────

    public function register_settings() {
        register_setting(
            'carno_wallet_settings_group',
            self::OPTION_KEY,
            ['sanitize_callback' => [$this, 'sanitize']]
        );

        // ── بخش ۱: منطق مالی ──────────────────────────────────
        add_settings_section('carno_section_financial', '💰 منطق مالی', '__return_false', 'user-wallet-settings');

        add_settings_field('wallet_max_ratio',   'سقف استفاده از کیف پول',  [$this, 'field_max_ratio'],          'user-wallet-settings', 'carno_section_financial');
        add_settings_field('cashback_enabled',   'فعال بودن کش‌بک',          [$this, 'field_cashback_enabled'],   'user-wallet-settings', 'carno_section_financial');
        add_settings_field('cashback_ratio',     'درصد کش‌بک',               [$this, 'field_cashback_ratio'],     'user-wallet-settings', 'carno_section_financial');
        add_settings_field('max_balance',        'سقف موجودی کیف پول',      [$this, 'field_max_balance'],        'user-wallet-settings', 'carno_section_financial');
        add_settings_field('deduction_statuses', 'وضعیت‌های کسر موجودی',    [$this, 'field_deduction_statuses'], 'user-wallet-settings', 'carno_section_financial');

        // ── بخش ۲: درگاه پرداخت ───────────────────────────────
        add_settings_section('carno_section_gateway', '🏦 درگاه پرداخت', '__return_false', 'user-wallet-settings');

        add_settings_field('gateway_title',       'عنوان درگاه',    [$this, 'field_gateway_title'],       'user-wallet-settings', 'carno_section_gateway');
        add_settings_field('gateway_description', 'توضیح درگاه',   [$this, 'field_gateway_description'], 'user-wallet-settings', 'carno_section_gateway');

        // ── بخش ۳: بازپرداخت ──────────────────────────────────
        add_settings_section('carno_section_refund', '↩️ بازپرداخت', '__return_false', 'user-wallet-settings');

        add_settings_field('refund_to_wallet', 'بازگشت به کیف پول', [$this, 'field_refund_to_wallet'], 'user-wallet-settings', 'carno_section_refund');
    }

    // ─── رندر فیلدها ───────────────────────────────────────────

    public function field_max_ratio() {
        $v = intval(self::fetch('wallet_max_ratio'));
        printf(
            '<input type="number" name="%s[wallet_max_ratio]" value="%d" min="1" max="100" step="1" style="width:70px"> %%'
            . '<p class="description">حداکثر درصد از مبلغ سبد که می‌توان از کیف پول پرداخت کرد. (پیش‌فرض: ۸۰٪)</p>',
            self::OPTION_KEY, $v
        );
    }

    public function field_cashback_enabled() {
        $v = self::fetch('cashback_enabled');
        printf(
            '<label><input type="checkbox" name="%s[cashback_enabled]" value="1" %s> فعال بودن کش‌بک</label>'
            . '<p class="description">پس از تأیید پرداخت، درصدی از مبلغ سفارش به کیف پول کاربر برمی‌گردد.</p>',
            self::OPTION_KEY, checked('1', $v, false)
        );
    }

    public function field_cashback_ratio() {
        $v = intval(self::fetch('cashback_ratio'));
        printf(
            '<input type="number" name="%s[cashback_ratio]" value="%d" min="1" max="100" step="1" style="width:70px"> %%'
            . '<p class="description">درصد کش‌بک از مبلغ اصلی سبد خرید. (پیش‌فرض: ۱۰٪)</p>',
            self::OPTION_KEY, $v
        );
    }

    public function field_max_balance() {
        $v = intval(self::fetch('max_balance'));
        printf(
            '<input type="number" name="%s[max_balance]" value="%d" min="0" step="1000" style="width:140px"> تومان'
            . '<p class="description">حداکثر موجودی مجاز برای هر کاربر. در صورت رسیدن موجودی به این سقف (مثلاً با کش‌بک یا شارژ)، مقدار اضافه نادیده گرفته می‌شود. مقدار <strong>۰</strong> یعنی بدون محدودیت.</p>',
            self::OPTION_KEY, $v
        );
    }

    public function field_deduction_statuses() {
        $saved    = (array) self::fetch('deduction_statuses');
        $statuses = [
            'processing' => 'در حال پردازش (Processing)',
            'completed'  => 'تکمیل‌شده (Completed)',
            'on-hold'    => 'در انتظار (On Hold)',
        ];
        foreach ($statuses as $key => $label) {
            printf(
                '<label style="display:block;margin-bottom:5px;"><input type="checkbox" name="%s[deduction_statuses][]" value="%s" %s> %s</label>',
                self::OPTION_KEY, esc_attr($key), checked(in_array($key, $saved, true), true, false), esc_html($label)
            );
        }
        echo '<p class="description">موجودی کیف پول زمانی کسر می‌شود که سفارش به این وضعیت‌ها برسد.</p>';
    }

    public function field_gateway_title() {
        $v = self::fetch('gateway_title');
        printf(
            '<input type="text" name="%s[gateway_title]" value="%s" class="regular-text">'
            . '<p class="description">نامی که کاربر در صفحه پرداخت می‌بیند.</p>',
            self::OPTION_KEY, esc_attr($v)
        );
    }

    public function field_gateway_description() {
        $v = self::fetch('gateway_description');
        printf(
            '<input type="text" name="%s[gateway_description]" value="%s" class="regular-text">'
            . '<p class="description">توضیح کوتاه زیر نام درگاه در صفحه پرداخت.</p>',
            self::OPTION_KEY, esc_attr($v)
        );
    }

    public function field_refund_to_wallet() {
        $v = self::fetch('refund_to_wallet');
        printf(
            '<label><input type="checkbox" name="%s[refund_to_wallet]" value="1" %s> برگشت مبلغ کیف پول هنگام ریفاند</label>'
            . '<p class="description">هنگام ثبت بازپرداخت، مبلغ کیف پول مصرف‌شده به موجودی کاربر برمی‌گردد.</p>',
            self::OPTION_KEY, checked('1', $v, false)
        );
    }

    // ─── رندر صفحه ─────────────────────────────────────────────

    public function render_page() {
        ?>
        <div class="wrap">
            <h1>⚙️ تنظیمات کیف پول</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('carno_wallet_settings_group');
                do_settings_sections('user-wallet-settings');
                submit_button('ذخیره تنظیمات');
                ?>
            </form>
        </div>
        <?php
    }

    // ─── سانیتایز ──────────────────────────────────────────────

    public function sanitize($input) {
        $clean = [];

        $clean['wallet_max_ratio']    = min(100, max(1, intval($input['wallet_max_ratio']   ?? self::$defaults['wallet_max_ratio'])));
        $clean['cashback_ratio']      = min(100, max(1, intval($input['cashback_ratio']      ?? self::$defaults['cashback_ratio'])));
        $clean['max_balance']         = max(0, intval($input['max_balance'] ?? self::$defaults['max_balance']));
        $clean['cashback_enabled']    = !empty($input['cashback_enabled'])  ? '1' : '0';
        $clean['refund_to_wallet']    = !empty($input['refund_to_wallet'])  ? '1' : '0';
        $clean['gateway_title']       = sanitize_text_field($input['gateway_title']       ?? self::$defaults['gateway_title']);
        $clean['gateway_description'] = sanitize_text_field($input['gateway_description'] ?? self::$defaults['gateway_description']);

        $valid_statuses               = ['processing', 'completed', 'on-hold'];
        $submitted                    = array_intersect((array) ($input['deduction_statuses'] ?? []), $valid_statuses);
        $clean['deduction_statuses']  = !empty($submitted) ? array_values($submitted) : self::$defaults['deduction_statuses'];

        self::$cache = null;
        return $clean;
    }

    // ─── Getterهای Static (برای استفاده در سایر کلاس‌ها) ──────

    private static function fetch($key) {
        if (self::$cache === null) {
            self::$cache = wp_parse_args(
                (array) get_option(self::OPTION_KEY, []),
                self::$defaults
            );
        }
        return self::$cache[$key] ?? self::$defaults[$key] ?? null;
    }

    public static function get_max_ratio()           { return floatval(self::fetch('wallet_max_ratio'))  / 100; }
    public static function get_cashback_ratio()      { return floatval(self::fetch('cashback_ratio'))     / 100; }
    public static function is_cashback_enabled()     { return self::fetch('cashback_enabled')  === '1'; }
    public static function get_deduction_statuses()  { return (array)  self::fetch('deduction_statuses'); }
    public static function get_gateway_title()       { return (string) self::fetch('gateway_title'); }
    public static function get_gateway_description() { return (string) self::fetch('gateway_description'); }
    public static function is_refund_to_wallet()     { return self::fetch('refund_to_wallet')   === '1'; }
    public static function get_max_balance()         { return floatval(self::fetch('max_balance')); }
}
