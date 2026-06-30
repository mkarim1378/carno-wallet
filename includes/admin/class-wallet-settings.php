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
        'wallet_max_ratio'              => 80,
        'wallet_max_mode'               => 'percent', // 'percent' | 'fixed'
        'wallet_max_fixed_amount'       => 0,         // مبلغ ثابت سقف (تومان)
        'wallet_max_excluded_products'  => [],        // آرایه‌ای از Product ID
        'wallet_max_excluded_categories'=> [],        // آرایه‌ای از Term ID دسته‌بندی محصول
        'cashback_enabled'    => '1',
        'cashback_ratio'      => 10,
        'deduction_statuses'  => ['processing', 'completed'],
        'gateway_title'       => 'کیف پول',
        'gateway_description' => 'پرداخت کامل از موجودی کیف پول شما',
        'refund_to_wallet'    => '1',
        'max_balance'         => 0,

        // پیامک کش‌بک
        'cashback_sms_enabled' => '0',
        'sms_username'         => '',
        'sms_password'         => '',
        'sms_from'             => '',
        'sms_from_support_one' => '',
        'sms_from_support_two' => '',
        'sms_template'         => "{name} عزیز، {amount} تومان کش‌بک خرید شما (سفارش #{order_id}) به کیف پولتان اضافه شد.\nموجودی فعلی: {balance} تومان\n{site_name}",
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
        add_action('admin_init',  [$this, 'handle_test_sms']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    /**
     * بارگذاری اسکریپت‌های Select2/SelectWoo ووکامرس در صفحه تنظیمات کیف پول
     */
    public function enqueue_assets($hook) {
        // صفحه تنظیمات از طریق admin.php?page=user-wallet-settings باز می‌شود
        if (empty($_GET['page']) || $_GET['page'] !== 'user-wallet-settings') {
            return;
        }

        if (function_exists('WC')) {
            wp_enqueue_script('wc-enhanced-select');
            wp_enqueue_style('woocommerce_admin_styles');
        }
    }

    /**
     * پردازش فرم «آزمایش ارسال پیامک» در تب پیامک کش‌بک
     */
    public function handle_test_sms() {
        if (!isset($_POST['carno_wallet_test_sms_nonce'])) return;
        if (!current_user_can('manage_options')) return;
        if (!wp_verify_nonce($_POST['carno_wallet_test_sms_nonce'], 'carno_wallet_test_sms')) return;

        $mobile   = sanitize_text_field($_POST['test_sms_mobile'] ?? '');
        $order_id = intval($_POST['test_sms_order_id'] ?? 0);

        $result = Carno_Wallet_SMS::send_test($mobile, $order_id);

        set_transient('carno_wallet_test_sms_result_' . get_current_user_id(), $result, 60);

        wp_safe_redirect(add_query_arg(['page' => 'user-wallet-settings', 'tab' => 'sms'], admin_url('admin.php')));
        exit;
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

        // ── تب پیامک کش‌بک ─────────────────────────────────────
        add_settings_section('carno_section_sms', '📱 پیامک کش‌بک', [$this, 'section_sms_intro'], 'user-wallet-settings-sms');

        add_settings_field('cashback_sms_enabled', 'فعال‌سازی پیامک کش‌بک', [$this, 'field_cashback_sms_enabled'], 'user-wallet-settings-sms', 'carno_section_sms');
        add_settings_field('sms_username',         'نام کاربری پیامیتو',    [$this, 'field_sms_username'],         'user-wallet-settings-sms', 'carno_section_sms');
        add_settings_field('sms_password',         'رمز عبور / ApiKey',     [$this, 'field_sms_password'],         'user-wallet-settings-sms', 'carno_section_sms');
        add_settings_field('sms_from',             'شماره فرستنده',         [$this, 'field_sms_from'],              'user-wallet-settings-sms', 'carno_section_sms');
        add_settings_field('sms_from_support_one', 'شماره فرستنده بکاپ ۱',  [$this, 'field_sms_from_support_one'],  'user-wallet-settings-sms', 'carno_section_sms');
        add_settings_field('sms_from_support_two', 'شماره فرستنده بکاپ ۲',  [$this, 'field_sms_from_support_two'],  'user-wallet-settings-sms', 'carno_section_sms');
        add_settings_field('sms_template',         'متن پیامک کش‌بک',       [$this, 'field_sms_template'],          'user-wallet-settings-sms', 'carno_section_sms');
    }

    public function section_sms_intro() {
        echo '<p>با فعال‌سازی این گزینه، به محض اعمال کش‌بک به کیف پول کاربر، یک پیامک اطلاع‌رسانی از طریق وب‌سرویس پیامیتو (Payamak-Panel) ارسال می‌شود.</p>';
    }

    // ─── رندر فیلدها ───────────────────────────────────────────

    public function field_max_ratio() {
        $mode          = self::get_max_mode();
        $ratio         = intval(self::fetch('wallet_max_ratio'));
        $fixed         = intval(self::fetch('wallet_max_fixed_amount'));
        $products      = self::get_max_excluded_products();
        $categories    = self::get_max_excluded_categories();
        $opt           = self::OPTION_KEY;

        // ── انتخاب حالت سقف ──
        printf(
            '<label style="display:block;margin-bottom:8px;"><input type="radio" name="%s[wallet_max_mode]" value="percent" %s> درصدی از مبلغ سبد</label>',
            $opt, checked('percent', $mode, false)
        );
        printf(
            '<label style="display:block;margin-bottom:4px;"><input type="radio" name="%s[wallet_max_mode]" value="fixed" %s> مبلغ ثابت (تومان)</label>',
            $opt, checked('fixed', $mode, false)
        );

        // ── بلوک حالت درصدی ──
        echo '<div id="carno-wallet-max-percent" class="carno-wallet-max-block" style="margin:8px 0 8px 24px;' . ($mode === 'percent' ? '' : 'display:none;') . '">';
        printf(
            '<input type="number" name="%s[wallet_max_ratio]" value="%d" min="1" max="100" step="1" style="width:70px"> %%'
            . '<p class="description">حداکثر درصد از مبلغ سبد که می‌توان از کیف پول پرداخت کرد. (پیش‌فرض: ۸۰٪)</p>',
            $opt, $ratio
        );
        echo '</div>';

        // ── بلوک حالت مبلغ ثابت ──
        echo '<div id="carno-wallet-max-fixed" class="carno-wallet-max-block" style="margin:8px 0 8px 24px;' . ($mode === 'fixed' ? '' : 'display:none;') . '">';

        // مبلغ ثابت
        printf(
            '<label style="display:block;margin-bottom:4px;">سقف مبلغ کیف پول:</label>'
            . '<input type="number" name="%s[wallet_max_fixed_amount]" value="%d" min="0" step="1000" style="width:140px"> تومان'
            . '<p class="description">حداکثر مبلغی که از کیف پول برای هر سفارش کسر می‌شود، فارغ از موجودی کاربر.</p>',
            $opt, $fixed
        );

        // انتخاب محصولات مستثنی
        echo '<label style="display:block;margin-top:12px;">محصولات مستثنی (کاملاً از کیف پول خارج می‌شوند):</label>';
        $selected_products = '';
        if (!empty($products)) {
            foreach ($products as $pid) {
                $p = wc_get_product($pid);
                if ($p) {
                    printf('<option value="%d" selected="selected">#%d — %s</option>', $pid, $pid, esc_html(wp_strip_all_tags($p->get_formatted_name())));
                }
            }
        }
        printf(
            '<select name="%s[wallet_max_excluded_products][]" multiple="multiple" class="wc-product-search" data-placeholder="جستجوی محصول…" data-action="woocommerce_json_search_products_and_variations" style="width:50%%;min-width:300px;">%s</select>'
            . '<p class="description">محصولاتی که نمی‌خواهید با کیف پول پرداخت شوند را اضافه کنید (مثلاً محصولات ارزان‌تر از سقف ثابت که کاربر می‌تواند رایگان دریافت کند).</p>',
            $opt, $selected_products
        );

        // انتخاب دسته‌های مستثنی
        echo '<label style="display:block;margin-top:12px;">دسته‌بندی‌های مستثنی:</label>';
        $selected_categories = '';
        if (!empty($categories)) {
            foreach ($categories as $cid) {
                $term = get_term($cid, 'product_cat');
                if ($term && !is_wp_error($term)) {
                    printf('<option value="%d" selected="selected">%s</option>', $cid, esc_html($term->name));
                }
            }
        }
        printf(
            '<select name="%s[wallet_max_excluded_categories][]" multiple="multiple" class="wc-enhanced-select" data-placeholder="انتخاب دسته…" style="width:50%%;min-width:300px;">%s</select>'
            . '<p class="description">تمام محصولات این دسته‌بندی‌ها از پرداخت کیف پول مستثنی می‌شوند.</p>',
            $opt, $selected_categories
        );

        echo '</div>';

        // ── اسکریپت نمایش/مخفی بلوک‌ها ──
        ?>
        <script>
        (function () {
            var radios = document.querySelectorAll('input[name="<?php echo esc_attr($opt); ?>[wallet_max_mode]"]');
            var percentBlock = document.getElementById('carno-wallet-max-percent');
            var fixedBlock   = document.getElementById('carno-wallet-max-fixed');
            function update() {
                var mode = document.querySelector('input[name="<?php echo esc_attr($opt); ?>[wallet_max_mode]"]:checked');
                if (!mode) return;
                var isPercent = mode.value === 'percent';
                if (percentBlock) percentBlock.style.display = isPercent ? '' : 'none';
                if (fixedBlock)   fixedBlock.style.display   = isPercent ? 'none' : '';
            }
            radios.forEach(function (r) { r.addEventListener('change', update); });
            // مطمئن می‌شویم Select2 پس از رندر آماده است
            if (window.jQuery) {
                jQuery(function ($) {
                    if ($.fn && $.fn.selectWoo) {
                        $('.wc-product-search, .wc-enhanced-select').filter(':not(.select2-hidden-accessible)').each(function () {
                            var $el = $(this);
                            if ($el.hasClass('wc-product-search')) {
                                $el.selectWoo($.extend({ minimumInputLength: 2 }, window.wc_select_props || {}));
                            } else {
                                $el.selectWoo();
                            }
                        });
                    }
                });
            }
        })();
        </script>
        <?php
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

    // ─── فیلدهای پیامک کش‌بک ────────────────────────────────────

    public function field_cashback_sms_enabled() {
        $v = self::fetch('cashback_sms_enabled');
        printf(
            '<label><input type="checkbox" id="carno_sms_enabled_toggle" name="%s[cashback_sms_enabled]" value="1" %s> ارسال پیامک به کاربر هنگام کش‌بک</label>'
            . '<p class="description">در صورت فعال‌بودن، پس از هر کش‌بک یک پیامک اطلاع‌رسانی از طریق پیامیتو ارسال می‌شود.</p>',
            self::OPTION_KEY, checked('1', $v, false)
        );
    }

    public function field_sms_username() {
        $v = self::fetch('sms_username');
        printf(
            '<input type="text" id="carno_sms_username" name="%s[sms_username]" value="%s" class="regular-text">',
            self::OPTION_KEY, esc_attr($v)
        );
    }

    public function field_sms_password() {
        $v = self::fetch('sms_password');
        printf(
            '<input type="password" id="carno_sms_password" name="%s[sms_password]" value="%s" class="regular-text" autocomplete="new-password">'
            . '<p class="description">ApiKey از تنظیمات وب‌سرویس در پنل پیامیتو.</p>',
            self::OPTION_KEY, esc_attr($v)
        );
    }

    public function field_sms_from() {
        $v = self::fetch('sms_from');
        printf(
            '<input type="text" id="carno_sms_from" name="%s[sms_from]" value="%s" class="regular-text">'
            . '<p class="description">شماره اختصاصی فرستنده پیامک.</p>',
            self::OPTION_KEY, esc_attr($v)
        );
    }

    public function field_sms_from_support_one() {
        $v = self::fetch('sms_from_support_one');
        printf(
            '<input type="text" id="carno_sms_from_support_one" name="%s[sms_from_support_one]" value="%s" class="regular-text">'
            . '<p class="description">اختیاری - شماره فرستنده بکاپ در صورت ناموفق‌بودن شماره اصلی.</p>',
            self::OPTION_KEY, esc_attr($v)
        );
    }

    public function field_sms_from_support_two() {
        $v = self::fetch('sms_from_support_two');
        printf(
            '<input type="text" id="carno_sms_from_support_two" name="%s[sms_from_support_two]" value="%s" class="regular-text">'
            . '<p class="description">اختیاری - شماره فرستنده بکاپ دوم.</p>',
            self::OPTION_KEY, esc_attr($v)
        );
    }

    public function field_sms_template() {
        $v = self::fetch('sms_template');
        printf(
            '<textarea id="carno_sms_template" name="%s[sms_template]" rows="5" class="large-text">%s</textarea>'
            . '<p class="description">متغیرهای قابل استفاده: <code>{name}</code>، <code>{amount}</code>، <code>{balance}</code>، <code>{order_id}</code>، <code>{mobile}</code>، <code>{site_name}</code></p>',
            self::OPTION_KEY, esc_textarea($v)
        );
    }

    // ─── رندر صفحه ─────────────────────────────────────────────

    public function render_page() {
        $tab  = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'general';
        $tabs = [
            'general' => '⚙️ تنظیمات کلی',
            'sms'     => '📱 پیامک کش‌بک',
            'logs'    => '📋 لاگ‌ها',
        ];
        ?>
        <div class="wrap">
            <h1>⚙️ تنظیمات کیف پول</h1>

            <h2 class="nav-tab-wrapper">
                <?php foreach ($tabs as $tab_key => $tab_label) : ?>
                    <a href="<?php echo esc_url(add_query_arg(['page' => 'user-wallet-settings', 'tab' => $tab_key], admin_url('admin.php'))); ?>"
                       class="nav-tab <?php echo $tab === $tab_key ? 'nav-tab-active' : ''; ?>">
                        <?php echo esc_html($tab_label); ?>
                    </a>
                <?php endforeach; ?>
            </h2>

            <?php if ($tab === 'logs') : ?>
                <?php $this->render_logs_tab(); ?>
            <?php else : ?>
                <form method="post" action="options.php">
                    <?php
                    settings_fields('carno_wallet_settings_group');
                    do_settings_sections($tab === 'sms' ? 'user-wallet-settings-sms' : 'user-wallet-settings');
                    submit_button('ذخیره تنظیمات');
                    ?>
                </form>
                <?php if ($tab === 'sms') : ?>
                    <script>
                    (function () {
                        var toggle = document.getElementById('carno_sms_enabled_toggle');
                        var fieldIds = [
                            'carno_sms_username', 'carno_sms_password', 'carno_sms_from',
                            'carno_sms_from_support_one', 'carno_sms_from_support_two', 'carno_sms_template'
                        ];
                        if (!toggle) return;

                        function update() {
                            fieldIds.forEach(function (id) {
                                var el = document.getElementById(id);
                                if (!el) return;
                                var row = el.closest('tr');
                                if (row) row.style.display = toggle.checked ? '' : 'none';
                            });
                        }

                        toggle.addEventListener('change', update);
                        update();
                    })();
                    </script>
                    <?php $this->render_test_sms_box(); ?>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * باکس «آزمایش ارسال پیامک» - ارسال پیامک کش‌بک یک سفارش به یک شماره دلخواه
     */
    private function render_test_sms_box() {
        $transient_key = 'carno_wallet_test_sms_result_' . get_current_user_id();
        $result        = get_transient($transient_key);

        if ($result !== false) {
            delete_transient($transient_key);

            if (!empty($result['success'])) {
                printf(
                    '<div class="notice notice-success is-dismissible"><p>✅ پیامک آزمایشی با موفقیت ارسال شد.<br>متن ارسال‌شده: %s</p></div>',
                    nl2br(esc_html($result['message'] ?? ''))
                );
            } else {
                printf(
                    '<div class="notice notice-error is-dismissible"><p>❌ ارسال پیامک آزمایشی ناموفق بود: %s</p></div>',
                    esc_html($result['error'] ?? 'خطای نامشخص')
                );
            }
        }
        ?>
        <hr>
        <h2>🧪 آزمایش ارسال پیامک</h2>
        <p class="description">با وارد کردن شماره موبایل و شناسه سفارش، پیامک کش‌بک همان سفارش (بر اساس مقدار کش‌بک ثبت‌شده برای آن سفارش و الگوی بالا) به‌صورت آزمایشی و فوری به شماره وارد‌شده ارسال می‌شود.</p>
        <form method="post">
            <?php wp_nonce_field('carno_wallet_test_sms', 'carno_wallet_test_sms_nonce'); ?>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="test_sms_mobile">شماره موبایل</label></th>
                    <td><input type="text" id="test_sms_mobile" name="test_sms_mobile" class="regular-text" placeholder="09xxxxxxxxx" required></td>
                </tr>
                <tr>
                    <th scope="row"><label for="test_sms_order_id">شناسه سفارش</label></th>
                    <td>
                        <input type="number" id="test_sms_order_id" name="test_sms_order_id" class="regular-text" min="1" required>
                        <p class="description">شناسه سفارشی که کش‌بک در آن انجام شده (برای متغیرهای {amount}، {balance}، {order_id} استفاده می‌شود).</p>
                    </td>
                </tr>
            </table>
            <?php submit_button('ارسال پیامک آزمایشی', 'secondary'); ?>
        </form>
        <?php
    }

    /**
     * رندر تب لاگ‌ها (صفحه‌بندی‌شده، فقط نمایشی)
     */
    private function render_logs_tab() {
        $page = max(1, intval($_GET['paged'] ?? 1));
        $per_page = 20;

        $result = Carno_Wallet_Logger::get_logs(['page' => $page, 'per_page' => $per_page]);
        $rows   = $result['rows'];
        $total  = $result['total'];
        $pages  = max(1, ceil($total / $per_page));

        $level_labels   = ['info' => 'موفق', 'error' => 'خطا'];
        $channel_labels = ['sms' => 'پیامک'];

        echo '<p class="description">لاگ‌های افزونه (حداکثر ' . esc_html(Carno_Wallet_Logger::RETENTION_DAYS) . ' روز اخیر).</p>';

        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr><th style="width:140px">تاریخ</th><th style="width:80px">نوع</th><th style="width:100px">کانال</th><th>پیام</th></tr></thead><tbody>';

        if (empty($rows)) {
            echo '<tr><td colspan="4">لاگی ثبت نشده است.</td></tr>';
        } else {
            foreach ($rows as $row) {
                $level = $level_labels[$row->level] ?? $row->level;
                $color = $row->level === 'error' ? 'color:#d63638;' : 'color:#1d8a3e;';
                $channel = $channel_labels[$row->channel] ?? $row->channel;
                printf(
                    '<tr><td>%s</td><td style="%s">%s</td><td>%s</td><td>%s</td></tr>',
                    esc_html($row->created_at),
                    esc_attr($color),
                    esc_html($level),
                    esc_html($channel),
                    esc_html($row->message)
                );
            }
        }

        echo '</tbody></table>';

        if ($pages > 1) {
            echo '<div class="tablenav"><div class="tablenav-pages">';
            for ($i = 1; $i <= $pages; $i++) {
                $url = add_query_arg(['page' => 'user-wallet-settings', 'tab' => 'logs', 'paged' => $i], admin_url('admin.php'));
                printf('<a class="%s" style="margin-left:5px;" href="%s">%d</a>', $i === $page ? 'page-numbers current' : 'page-numbers', esc_url($url), $i);
            }
            echo '</div></div>';
        }
    }

    // ─── سانیتایز ──────────────────────────────────────────────

    public function sanitize($input) {
        $clean = [];

        $clean['wallet_max_ratio']         = min(100, max(1, intval($input['wallet_max_ratio']   ?? self::$defaults['wallet_max_ratio'])));
        $clean['wallet_max_mode']          = ($input['wallet_max_mode'] ?? 'percent') === 'fixed' ? 'fixed' : 'percent';
        $clean['wallet_max_fixed_amount']  = max(0, intval($input['wallet_max_fixed_amount'] ?? self::$defaults['wallet_max_fixed_amount']));

        // محصولات مستثنی: فقط شناسه‌های عددی معتبر محصول
        $raw_products = array_map('intval', (array) ($input['wallet_max_excluded_products'] ?? []));
        $clean['wallet_max_excluded_products'] = array_values(array_filter(array_unique($raw_products), function ($pid) {
            return $pid > 0 && wc_get_product($pid);
        }));

        // دسته‌های مستثنی: فقط Term IDهای معتبر product_cat
        $raw_cats = array_map('intval', (array) ($input['wallet_max_excluded_categories'] ?? []));
        $clean['wallet_max_excluded_categories'] = array_values(array_filter(array_unique($raw_cats), function ($cid) {
            $term = get_term($cid, 'product_cat');
            return $cid > 0 && $term && !is_wp_error($term);
        }));

        $clean['cashback_ratio']      = min(100, max(1, intval($input['cashback_ratio']      ?? self::$defaults['cashback_ratio'])));
        $clean['max_balance']         = max(0, intval($input['max_balance'] ?? self::$defaults['max_balance']));
        $clean['cashback_enabled']    = !empty($input['cashback_enabled'])  ? '1' : '0';
        $clean['refund_to_wallet']    = !empty($input['refund_to_wallet'])  ? '1' : '0';
        $clean['gateway_title']       = sanitize_text_field($input['gateway_title']       ?? self::$defaults['gateway_title']);
        $clean['gateway_description'] = sanitize_text_field($input['gateway_description'] ?? self::$defaults['gateway_description']);

        $valid_statuses               = ['processing', 'completed', 'on-hold'];
        $submitted                    = array_intersect((array) ($input['deduction_statuses'] ?? []), $valid_statuses);
        $clean['deduction_statuses']  = !empty($submitted) ? array_values($submitted) : self::$defaults['deduction_statuses'];

        $clean['cashback_sms_enabled'] = !empty($input['cashback_sms_enabled']) ? '1' : '0';
        $clean['sms_username']         = sanitize_text_field($input['sms_username']         ?? self::$defaults['sms_username']);
        $clean['sms_password']         = sanitize_text_field($input['sms_password']         ?? self::$defaults['sms_password']);
        $clean['sms_from']             = sanitize_text_field($input['sms_from']             ?? self::$defaults['sms_from']);
        $clean['sms_from_support_one'] = sanitize_text_field($input['sms_from_support_one'] ?? self::$defaults['sms_from_support_one']);
        $clean['sms_from_support_two'] = sanitize_text_field($input['sms_from_support_two'] ?? self::$defaults['sms_from_support_two']);
        $clean['sms_template']         = sanitize_textarea_field($input['sms_template']     ?? self::$defaults['sms_template']);

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
    public static function get_max_mode()            { return self::fetch('wallet_max_mode') === 'fixed' ? 'fixed' : 'percent'; }
    public static function get_max_fixed_amount()    { return floatval(self::fetch('wallet_max_fixed_amount')); }
    public static function get_max_excluded_products()     { return array_filter(array_map('intval', (array) self::fetch('wallet_max_excluded_products'))); }
    public static function get_max_excluded_categories()   { return array_filter(array_map('intval', (array) self::fetch('wallet_max_excluded_categories'))); }
    public static function get_cashback_ratio()      { return floatval(self::fetch('cashback_ratio'))     / 100; }
    public static function is_cashback_enabled()     { return self::fetch('cashback_enabled')  === '1'; }
    public static function get_deduction_statuses()  { return (array)  self::fetch('deduction_statuses'); }
    public static function get_gateway_title()       { return (string) self::fetch('gateway_title'); }
    public static function get_gateway_description() { return (string) self::fetch('gateway_description'); }
    public static function is_refund_to_wallet()     { return self::fetch('refund_to_wallet')   === '1'; }
    public static function get_max_balance()         { return floatval(self::fetch('max_balance')); }

    public static function is_cashback_sms_enabled() { return self::fetch('cashback_sms_enabled') === '1'; }

    public static function get_sms_credentials() {
        return [
            'username'         => (string) self::fetch('sms_username'),
            'password'         => (string) self::fetch('sms_password'),
            'from'             => (string) self::fetch('sms_from'),
            'from_support_one' => (string) self::fetch('sms_from_support_one'),
            'from_support_two' => (string) self::fetch('sms_from_support_two'),
        ];
    }

    public static function get_cashback_sms_template() { return (string) self::fetch('sms_template'); }
}
