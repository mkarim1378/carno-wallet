<?php
if (!defined('ABSPATH')) exit;

/**
 * پنل مدیریت: آپلود فایل، جستجو و ویرایش موجودی
 */
class Carno_Wallet_Admin {

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

        // باگ‌فیکس: هر دو action باید register شوند
        add_action('admin_post_upload_wallet_excel', [$this, 'handle_excel_upload']);
        add_action('admin_post_update_single_wallet', [$this, 'handle_single_update']); // قبلاً missing بود
    }

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

    public function enqueue_scripts($hook) {
        if ($hook !== 'toplevel_page_user-wallet') return;
        wp_enqueue_style('carno-wallet-admin', CARNO_WALLET_URL . 'assets/admin-style.css', [], CARNO_WALLET_VERSION);
    }

    public function render_admin_page() {
        ?>
        <div class="wrap">
            <h1>مدیریت کیف پول کاربران</h1>

            <?php $this->render_notices(); ?>

            <div class="card" style="max-width: 600px; margin-top: 20px;">
                <h2>آپلود فایل اکسل / CSV</h2>
                <p>فایل باید دو ستون داشته باشد:</p>
                <ul>
                    <li><strong>ستون A:</strong> شماره موبایل (نام کاربری)</li>
                    <li><strong>ستون B:</strong> مبلغ موجودی (به تومان)</li>
                </ul>

                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="upload_wallet_excel">
                    <?php wp_nonce_field('wallet_excel_upload', 'wallet_nonce'); ?>
                    <table class="form-table">
                        <tr>
                            <th><label for="excel_file">انتخاب فایل:</label></th>
                            <td>
                                <input type="file" name="excel_file" id="excel_file" accept=".csv" required>
                                <p class="description">فرمت مجاز: CSV</p>
                            </td>
                        </tr>
                    </table>
                    <?php submit_button('آپلود و اعمال موجودی'); ?>
                </form>
            </div>

            <div class="card" style="max-width: 800px; margin-top: 20px;">
                <h2>جستجوی کاربر</h2>
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

    private function render_notices() {
        if (!empty($_GET['success']) && $_GET['success'] === 'uploaded') {
            $updated = intval($_GET['updated'] ?? 0);
            $failed  = intval($_GET['failed'] ?? 0);
            echo '<div class="notice notice-success is-dismissible"><p>';
            echo 'آپلود موفق — بروزرسانی‌شده: <strong>' . $updated . '</strong> | یافت نشد: <strong>' . $failed . '</strong>';
            echo '</p></div>';
        }

        if (!empty($_GET['success']) && $_GET['success'] === 'balance_updated') {
            echo '<div class="notice notice-success is-dismissible"><p>موجودی کاربر با موفقیت بروزرسانی شد.</p></div>';
        }

        $errors = [
            'no_file'       => 'هیچ فایلی انتخاب نشده است.',
            'invalid_type'  => 'فرمت فایل پشتیبانی نمی‌شود. لطفاً از CSV استفاده کنید.',
            'process_failed'=> 'خطا در پردازش فایل.',
            'invalid_user'  => 'کاربر مورد نظر یافت نشد.',
        ];

        if (!empty($_GET['error']) && isset($errors[$_GET['error']])) {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($errors[$_GET['error']]) . '</p></div>';
        }
    }

    private function render_user_search_result() {
        if (empty($_GET['search_user'])) return;

        // بررسی nonce برای جستجو
        if (empty($_GET['search_nonce']) || !wp_verify_nonce($_GET['search_nonce'], 'wallet_search')) {
            echo '<p style="color:red;">درخواست نامعتبر.</p>';
            return;
        }

        $username = sanitize_text_field($_GET['search_user']);
        $user = get_user_by('login', $username);

        // جستجو با ایمیل اگر با login پیدا نشد
        if (!$user) {
            $user = get_user_by('email', $username);
        }

        if (!$user) {
            echo '<p style="color: red;">کاربری با این مشخصات یافت نشد.</p>';
            return;
        }

        $balance = Carno_Wallet_Core::get_user_balance($user->ID);
        ?>
        <div style="margin-top: 20px; padding: 15px; background: #f0f0f1; border-radius: 4px;">
            <h3><?php echo esc_html($user->display_name); ?></h3>
            <p><strong>نام کاربری:</strong> <?php echo esc_html($user->user_login); ?></p>
            <p><strong>ایمیل:</strong> <?php echo esc_html($user->user_email); ?></p>
            <p><strong>موجودی فعلی:</strong> <?php echo number_format($balance); ?> تومان</p>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top: 15px;">
                <input type="hidden" name="action" value="update_single_wallet">
                <input type="hidden" name="user_id" value="<?php echo esc_attr($user->ID); ?>">
                <?php wp_nonce_field('update_wallet_' . $user->ID, 'wallet_nonce'); ?>

                <label><strong>موجودی جدید (تومان):</strong></label><br>
                <input type="number" name="new_balance" value="<?php echo esc_attr($balance); ?>"
                       min="0" step="1000" style="width: 200px; margin-top: 5px;">
                <button type="submit" class="button button-primary" style="margin-right: 8px;">بروزرسانی موجودی</button>
            </form>
        </div>
        <?php
    }

    // ─── هندلر آپلود فایل ──────────────────────────────────────

    public function handle_excel_upload() {
        if (!current_user_can('manage_options')) wp_die('دسترسی غیرمجاز');
        check_admin_referer('wallet_excel_upload', 'wallet_nonce');

        if (empty($_FILES['excel_file']['name'])) {
            wp_redirect(add_query_arg(['page' => 'user-wallet', 'error' => 'no_file'], admin_url('admin.php')));
            exit;
        }

        $file     = $_FILES['excel_file'];
        $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if ($file_ext !== 'csv') {
            wp_redirect(add_query_arg(['page' => 'user-wallet', 'error' => 'invalid_type'], admin_url('admin.php')));
            exit;
        }

        $result = $this->process_csv($file['tmp_name']);

        if ($result['success']) {
            wp_redirect(add_query_arg([
                'page'    => 'user-wallet',
                'success' => 'uploaded',
                'updated' => $result['updated'],
                'failed'  => $result['failed'],
            ], admin_url('admin.php')));
        } else {
            wp_redirect(add_query_arg(['page' => 'user-wallet', 'error' => 'process_failed'], admin_url('admin.php')));
        }
        exit;
    }

    // ─── هندلر ویرایش تک‌کاربر (قبلاً وجود نداشت) ─────────────

    public function handle_single_update() {
        if (!current_user_can('manage_options')) wp_die('دسترسی غیرمجاز');

        $user_id = intval($_POST['user_id'] ?? 0);
        check_admin_referer('update_wallet_' . $user_id, 'wallet_nonce');

        if (!$user_id || !get_userdata($user_id)) {
            wp_redirect(add_query_arg(['page' => 'user-wallet', 'error' => 'invalid_user'], admin_url('admin.php')));
            exit;
        }

        $new_balance = floatval($_POST['new_balance'] ?? 0);
        Carno_Wallet_Core::set_user_balance($user_id, $new_balance);

        wp_redirect(add_query_arg(['page' => 'user-wallet', 'success' => 'balance_updated'], admin_url('admin.php')));
        exit;
    }

    // ─── پردازش CSV ────────────────────────────────────────────

    private function process_csv($file_path) {
        $updated = 0;
        $failed  = 0;

        $handle = fopen($file_path, 'r');
        if ($handle === false) {
            return ['success' => false];
        }

        // حذف BOM در صورت وجود
        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($handle);
        }

        // رد کردن ردیف هدر
        fgetcsv($handle);

        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) < 2) continue;

            $username = trim($row[0]);
            $amount   = floatval($row[1]);

            if ($username === '' || $amount < 0) {
                $failed++;
                continue;
            }

            $user = get_user_by('login', $username);
            if (!$user) {
                $user = get_user_by('email', $username);
            }

            if ($user) {
                Carno_Wallet_Core::set_user_balance($user->ID, $amount);
                $updated++;
            } else {
                $failed++;
            }
        }

        fclose($handle);

        return ['success' => true, 'updated' => $updated, 'failed' => $failed];
    }
}
