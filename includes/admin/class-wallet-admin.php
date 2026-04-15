<?php
if (!defined('ABSPATH')) exit;

/**
 * پنل مدیریت: آپلود فایل، جستجو و ویرایش موجودی
 *
 * ساختار مورد انتظار فایل اکسل:
 *   ردیف ۱ (هدر): username | amount
 *   ردیف ۲ به بعد: داده‌ها
 *
 * مهم: ستون username باید در اکسل به فرمت «Text» تنظیم شود تا صفر اول حفظ شود.
 */
class Carno_Wallet_Admin {

    // سرستون‌های مورد انتظار در فایل اکسل
    const COL_USERNAME = 'username';
    const COL_AMOUNT   = 'amount';

    private static $instance = null;

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
        add_action('admin_post_upload_wallet_excel', [$this, 'handle_excel_upload']);
        add_action('admin_post_update_single_wallet', [$this, 'handle_single_update']);

        // ستون موجودی در صفحه کاربران وردپرس
        add_filter('manage_users_columns',       [$this, 'users_column_header']);
        add_filter('manage_users_custom_column', [$this, 'users_column_value'], 10, 3);
        add_filter('manage_users_sortable_columns', [$this, 'users_column_sortable']);
        add_action('pre_get_users',              [$this, 'users_column_orderby']);
    }

    // ─── منوی ادمین ────────────────────────────────────────────

    /**
     * افزودن منوی مدیریت کیف پول
     */
    public function add_admin_menu() {
        add_menu_page(
            'مدیریت کیف پول',
            'کیف پول کاربران',
            'manage_options',
            'user-wallet',
            [$this, 'render_admin_page'],
            'dashicons-money-alt',
            30
        );
    }

    /**
     * بارگذاری استایل‌های ادمین
     */
    public function enqueue_scripts($hook) {
        if ($hook !== 'toplevel_page_user-wallet') return;
        wp_enqueue_style('carno-wallet-admin', CARNO_WALLET_URL . 'assets/admin-style.css', [], CARNO_WALLET_VERSION);
    }

    // ─── صفحه اصلی ادمین ───────────────────────────────────────

    /**
     * رندر صفحه اصلی مدیریت کیف پول
     */
    public function render_admin_page() {
        ?>
        <div class="wrap">
            <h1>📊 مدیریت کیف پول کاربران</h1>

            <?php $this->render_notices(); ?>

            <div class="card carno-wallet-card" style="max-width: 680px; margin-top: 20px;">
                <h2>📥 آپلود فایل اکسل</h2>

                <div class="carno-file-format-box">
                    <p><strong>فرمت مورد انتظار فایل <code>.xlsx</code>:</strong></p>
                    <table class="carno-sample-table">
                        <thead>
                            <tr>
                                <th>ستون A</th>
                                <th>ستون B</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr class="header-row">
                                <td><code><?php echo esc_html(self::COL_USERNAME); ?></code></td>
                                <td><code><?php echo esc_html(self::COL_AMOUNT); ?></code></td>
                            </tr>
                            <tr>
                                <td>09123456789</td>
                                <td>150000</td>
                            </tr>
                            <tr>
                                <td>09987654321</td>
                                <td>80000</td>
                            </tr>
                        </tbody>
                    </table>
                    <p class="carno-notice-zero">
                        ⚠️ برای حفظ صفر اول شماره موبایل، ستون A را در اکسل به فرمت
                        <strong>Text</strong> تنظیم کنید، سپس داده وارد نمایید.
                    </p>
                </div>

                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data" style="margin-top: 16px;">
                    <input type="hidden" name="action" value="upload_wallet_excel">
                    <?php wp_nonce_field('wallet_excel_upload', 'wallet_nonce'); ?>
                    <table class="form-table">
                        <tr>
                            <th><label for="excel_file">انتخاب فایل:</label></th>
                            <td>
                                <input type="file" name="excel_file" id="excel_file" accept=".xlsx" required>
                                <p class="description">فرمت مجاز: <strong>.xlsx</strong></p>
                            </td>
                        </tr>
                    </table>
                    <?php submit_button('آپلود و اعمال موجودی'); ?>
                </form>
            </div>

            <div class="card carno-wallet-card" style="max-width: 800px; margin-top: 20px;">
                <h2>🔍 جستجوی کاربر</h2>
                <form method="get">
                    <input type="hidden" name="page" value="user-wallet">
                    <?php wp_nonce_field('wallet_search', 'search_nonce'); ?>
                    <input type="text" name="search_user" placeholder="شماره موبایل یا نام کاربری"
                           value="<?php echo esc_attr($_GET['search_user'] ?? ''); ?>">
                    <button type="submit" class="button">جستجو</button>
                </form>

                <?php $this->render_user_search_result(); ?>
            </div>
        </div>
        <?php
    }

    // ─── نمایش پیام‌های موفقیت/خطا ────────────────────────────

    /**
     * رندر پیام‌های اطلاع‌رسانی
     */
    private function render_notices() {
        if (!empty($_GET['success']) && $_GET['success'] === 'uploaded') {
            $updated = intval($_GET['updated'] ?? 0);
            $failed  = intval($_GET['failed'] ?? 0);

            $transient_key = 'carno_wallet_upload_result_' . get_current_user_id();
            $detail        = get_transient($transient_key);
            delete_transient($transient_key);

            echo '<div class="notice notice-success is-dismissible">';
            echo '<p>✅ آپلود موفق — شارژشده: <strong>' . $updated . '</strong> | یافت نشد / ناموفق: <strong>' . $failed . '</strong></p>';

            if (!empty($detail['updated_list'])) {
                echo '<p><strong>✅ شماره‌های شارژشده (' . count($detail['updated_list']) . '):</strong></p>';
                echo '<div style="max-height:160px;overflow-y:auto;background:#f6ffed;border:1px solid #b7eb8f;border-radius:4px;padding:8px 12px;margin-bottom:8px;direction:ltr;font-family:monospace;">';
                echo implode('<br>', array_map('esc_html', $detail['updated_list']));
                echo '</div>';
            }

            if (!empty($detail['failed_list'])) {
                echo '<p><strong>❌ شماره‌های ناموفق (' . count($detail['failed_list']) . '):</strong></p>';
                echo '<div style="max-height:160px;overflow-y:auto;background:#fff2f0;border:1px solid #ffccc7;border-radius:4px;padding:8px 12px;margin-bottom:8px;direction:ltr;font-family:monospace;">';
                echo implode('<br>', array_map('esc_html', $detail['failed_list']));
                echo '</div>';
            }

            echo '</div>';
        }

        if (!empty($_GET['success']) && $_GET['success'] === 'balance_updated') {
            echo '<div class="notice notice-success is-dismissible"><p>✅ موجودی کاربر با موفقیت بروزرسانی شد.</p></div>';
        }

        $errors = [
            'no_file'          => 'هیچ فایلی انتخاب نشده است.',
            'invalid_type'     => 'فرمت فایل پشتیبانی نمی‌شود. لطفاً از فرمت .xlsx استفاده کنید.',
            'invalid_header'   => 'سرستون‌های فایل اشتباه است. باید username و amount باشند.',
            'zip_missing'      => 'افزونه ZipArchive در PHP فعال نیست. با هاستینگ تماس بگیرید.',
            'process_failed'   => 'خطا در پردازش فایل. فایل ممکن است خراب باشد.',
            'invalid_user'     => 'کاربر مورد نظر یافت نشد.',
        ];

        if (!empty($_GET['error']) && isset($errors[$_GET['error']])) {
            echo '<div class="notice notice-error is-dismissible"><p>❌ ' . esc_html($errors[$_GET['error']]) . '</p></div>';
        }
    }

    // ─── نمایش نتیجه جستجو ─────────────────────────────────────

    /**
     * رندر نتایج جستجوی کاربر
     */
    private function render_user_search_result() {
        if (empty($_GET['search_user'])) return;

        if (empty($_GET['search_nonce']) || !wp_verify_nonce($_GET['search_nonce'], 'wallet_search')) {
            echo '<p style="color:red;">❌ درخواست نامعتبر.</p>';
            return;
        }

        $username = sanitize_text_field($_GET['search_user']);
        $user     = get_user_by('login', $username) ?: get_user_by('email', $username);

        if (!$user) {
            echo '<p style="color: red;">❌ کاربری با این مشخصات یافت نشد.</p>';
            return;
        }

        $balance = Carno_Wallet_Helpers::get_user_balance($user->ID);
        ?>
        <div style="margin-top: 20px; padding: 15px; background: #f0f0f1; border-radius: 4px;">
            <h3><?php echo esc_html($user->display_name); ?></h3>
            <p><strong>نام کاربری:</strong> <?php echo esc_html($user->user_login); ?></p>
            <p><strong>ایمیل:</strong> <?php echo esc_html($user->user_email); ?></p>
            <p><strong>💳 موجودی فعلی:</strong> <?php echo Carno_Wallet_Helpers::format_currency($balance); ?></p>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top: 15px;">
                <input type="hidden" name="action" value="update_single_wallet">
                <input type="hidden" name="user_id" value="<?php echo esc_attr($user->ID); ?>">
                <?php wp_nonce_field('update_wallet_' . $user->ID, 'wallet_nonce'); ?>

                <label><strong>موجودی جدید (تومان):</strong></label><br>
                <input type="number" name="new_balance" value="<?php echo esc_attr($balance); ?>"
                       min="0" step="1000" style="width: 200px; margin-top: 5px;">
                <button type="submit" class="button button-primary" style="margin-right: 8px;">✏️ بروزرسانی موجودی</button>
            </form>
        </div>
        <?php
    }

    // ─── ستون موجودی در صفحه کاربران وردپرس ──────────────────

    /**
     * اضافه‌کردن ستون موجودی به جدول کاربران
     */
    public function users_column_header($columns) {
        $columns['wallet_balance'] = '💳 موجودی کیف پول';
        return $columns;
    }

    /**
     * نمایش مقدار موجودی در ستون کاربران
     */
    public function users_column_value($value, $column_name, $user_id) {
        if ($column_name !== 'wallet_balance') return $value;

        $balance = Carno_Wallet_Helpers::get_user_balance($user_id);
        return Carno_Wallet_Helpers::format_currency($balance);
    }

    /**
     * تعیین ستون‌های قابل مرتب‌سازی
     */
    public function users_column_sortable($columns) {
        $columns['wallet_balance'] = 'wallet_balance';
        return $columns;
    }

    /**
     * مرتب‌سازی کاربران بر اساس موجودی
     */
    public function users_column_orderby($query) {
        if (!is_admin() || $query->get('orderby') !== 'wallet_balance') return;

        $query->set('meta_key', CARNO_WALLET_META_KEY);
        $query->set('orderby', 'meta_value_num');
    }

    // ─── هندلر آپلود فایل ──────────────────────────────────────

    /**
     * مدیریت آپلود فایل اکسل
     */
    public function handle_excel_upload() {
        if (!Carno_Wallet_Helpers::current_user_can_manage_wallet()) {
            wp_die('دسترسی غیرمجاز');
        }
        check_admin_referer('wallet_excel_upload', 'wallet_nonce');

        if (empty($_FILES['excel_file']['name'])) {
            wp_redirect(add_query_arg(['page' => 'user-wallet', 'error' => 'no_file'], admin_url('admin.php')));
            exit;
        }

        $file     = $_FILES['excel_file'];
        $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if ($file_ext !== 'xlsx') {
            wp_redirect(add_query_arg(['page' => 'user-wallet', 'error' => 'invalid_type'], admin_url('admin.php')));
            exit;
        }

        $result = $this->process_xlsx($file['tmp_name']);

        if (isset($result['error'])) {
            $error_map = [
                'zip_extension_missing' => 'zip_missing',
                'cannot_open_file'      => 'process_failed',
                'invalid_header'        => 'invalid_header',
            ];
            $error_code = $error_map[$result['error']] ?? 'process_failed';
            wp_redirect(add_query_arg(['page' => 'user-wallet', 'error' => $error_code], admin_url('admin.php')));
            exit;
        }

        $transient_key = 'carno_wallet_upload_result_' . get_current_user_id();
        set_transient($transient_key, [
            'updated_list' => $result['updated_list'],
            'failed_list'  => $result['failed_list'],
        ], 60);

        wp_redirect(add_query_arg([
            'page'    => 'user-wallet',
            'success' => 'uploaded',
            'updated' => $result['updated'],
            'failed'  => $result['failed'],
        ], admin_url('admin.php')));
        exit;
    }

    // ─── هندلر ویرایش تک‌کاربر ─────────────────────────────────

    /**
     * مدیریت بروزرسانی موجودی تک کاربر
     */
    public function handle_single_update() {
        if (!Carno_Wallet_Helpers::current_user_can_manage_wallet()) {
            wp_die('دسترسی غیرمجاز');
        }

        $user_id = intval($_POST['user_id'] ?? 0);
        check_admin_referer('update_wallet_' . $user_id, 'wallet_nonce');

        if (!$user_id || !get_userdata($user_id)) {
            wp_redirect(add_query_arg(['page' => 'user-wallet', 'error' => 'invalid_user'], admin_url('admin.php')));
            exit;
        }

        $new_balance = floatval($_POST['new_balance'] ?? 0);
        Carno_Wallet_Helpers::set_user_balance($user_id, $new_balance);

        wp_redirect(add_query_arg(['page' => 'user-wallet', 'success' => 'balance_updated'], admin_url('admin.php')));
        exit;
    }

    // ─── پردازش فایل XLSX ──────────────────────────────────────

    /**
     * پردازش فایل اکسل
     */
    private function process_xlsx($file_path) {
        $result = Carno_Wallet_XLSX_Reader::read($file_path);

        if (isset($result['error'])) {
            return $result;
        }

        $rows             = $result['rows'];
        $updated_list     = [];
        $failed_list      = [];

        // پیدا کردن ردیف هدر
        $header_row = null;
        $header_idx = null;
        foreach ($rows as $row_num => $row) {
            $values = array_values($row);
            if (!empty($values[0])) {
                $header_row = array_map('strtolower', array_map('trim', $values));
                $header_idx = $row_num;
                break;
            }
        }

        // بررسی سرستون‌ها
        if (
            $header_row === null ||
            !in_array(self::COL_USERNAME, $header_row, true) ||
            !in_array(self::COL_AMOUNT, $header_row, true)
        ) {
            return ['error' => 'invalid_header'];
        }

        $username_col = array_search(self::COL_USERNAME, $header_row);
        $amount_col   = array_search(self::COL_AMOUNT, $header_row);

        // پردازش ردیف‌ها
        foreach ($rows as $row_num => $row) {
            if ($row_num <= $header_idx) continue;

            $username = trim($row[$username_col] ?? '');
            $amount   = floatval($row[$amount_col] ?? 0);

            if ($username === '') continue;

            if ($amount < 0) {
                $failed_list[] = $username . ' (مبلغ نامعتبر)';
                continue;
            }

            $user = get_user_by('login', $username) ?: get_user_by('email', $username);

            if ($user) {
                Carno_Wallet_Helpers::set_user_balance($user->ID, $amount);
                $updated_list[] = $username;
            } else {
                $failed_list[] = $username;
            }
        }

        return [
            'updated'      => count($updated_list),
            'failed'       => count($failed_list),
            'updated_list' => $updated_list,
            'failed_list'  => $failed_list,
        ];
    }
}
