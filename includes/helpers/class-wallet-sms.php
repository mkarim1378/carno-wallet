<?php
if (!defined('ABSPATH')) exit;

/**
 * ارسال پیامک کش‌بک از طریق وب‌سرویس REST پیامیتو (Payamak-Panel SmartSMS)
 */
class Carno_Wallet_SMS {

    const API_URL = 'https://rest.payamak-panel.com/api/SmartSMS/Send';

    // کدهای خطای شناخته‌شده در پاسخ پیامیتو (Value)
    const ERROR_CODES = [
        '0'  => 'نام کاربری یا رمز عبور اشتباه است',
        '4'  => 'محدودیت حجم ارسال (حداکثر ۱۰۰ شماره)',
        '5'  => 'شماره فرستنده معتبر نیست',
        '7'  => 'متن حاوی کلمات فیلتر شده است',
        '9'  => 'ارسال از خطوط عمومی از طریق وب‌سرویس امکان‌پذیر نیست',
        '14' => 'متن حاوی لینک است',
        '15' => 'عدم وجود «لغو11» در انتهای متن پیامک',
    ];

    private function __construct() {}

    /**
     * ثبت هندلر اکشن کرون برای ارسال پیامک کش‌بک (آسینک)
     */
    public static function init() {
        add_action('carno_wallet_send_cashback_sms', [__CLASS__, 'handle_cashback_sms'], 10, 4);
    }

    /**
     * ارسال پیامک از طریق REST API پیامیتو
     *
     * @param string $mobile  شماره موبایل گیرنده
     * @param string $message متن پیامک
     * @return array{success: bool, value: string|null, error: string|null}
     */
    public static function send($mobile, $message) {
        $creds = Carno_Wallet_Settings::get_sms_credentials();

        if (empty($creds['username']) || empty($creds['password']) || empty($creds['from'])) {
            return ['success' => false, 'value' => null, 'error' => 'تنظیمات پیامک (نام کاربری/رمز/شماره فرستنده) کامل نیست'];
        }

        $body = [
            'username' => $creds['username'],
            'password' => $creds['password'],
            'to'       => $mobile,
            'text'     => $message,
            'from'     => $creds['from'],
        ];

        if (!empty($creds['from_support_one'])) {
            $body['fromSupportOne'] = $creds['from_support_one'];
        }
        if (!empty($creds['from_support_two'])) {
            $body['fromSupportTwo'] = $creds['from_support_two'];
        }

        $response = wp_remote_post(self::API_URL, [
            'timeout' => 15,
            'body'    => $body,
        ]);

        if (is_wp_error($response)) {
            return ['success' => false, 'value' => null, 'error' => $response->get_error_message()];
        }

        $data  = json_decode(wp_remote_retrieve_body($response), true);
        $value = $data['Value'] ?? null;

        if (($data['RetStatus'] ?? null) == 1 && $value !== null && !isset(self::ERROR_CODES[(string) $value])) {
            return ['success' => true, 'value' => $value, 'error' => null];
        }

        $error = self::ERROR_CODES[(string) $value] ?? ($data['StrRetStatus'] ?? 'خطای نامشخص در ارسال پیامک');

        return ['success' => false, 'value' => $value, 'error' => $error];
    }

    /**
     * جایگزینی متغیرهای الگوی پیامک
     *
     * @param string $template متن الگو با placeholderهای {amount}, {balance}, {order_id}, {name}, {mobile}, {site_name}
     * @param array  $vars     مقادیر جایگزین
     * @return string
     */
    public static function render_template($template, $vars) {
        $replacements = [];
        foreach ($vars as $key => $value) {
            $replacements['{' . $key . '}'] = $value;
        }
        return strtr($template, $replacements);
    }

    /**
     * ساخت متن پیامک کش‌بک از روی الگوی تنظیمات
     *
     * @param int    $order_id
     * @param string $mobile
     * @param float  $amount
     * @param float  $balance_after
     * @param string $name
     * @return string
     */
    public static function build_message($order_id, $mobile, $amount, $balance_after, $name = '') {
        $vars = [
            'amount'    => Carno_Wallet_Helpers::format_currency($amount),
            'balance'   => Carno_Wallet_Helpers::format_currency($balance_after),
            'order_id'  => $order_id,
            'name'      => $name,
            'mobile'    => $mobile,
            'site_name' => get_bloginfo('name'),
        ];

        return self::render_template(Carno_Wallet_Settings::get_cashback_sms_template(), $vars);
    }

    /**
     * مقدار کش‌بک ثبت‌شده روی یک سفارش (در صورت نبودن، تخمین از روی subtotal و درصد فعلی کش‌بک)
     *
     * @param WC_Order $order
     * @return float
     */
    public static function get_order_cashback_amount($order) {
        $amount = floatval($order->get_meta(CARNO_WALLET_ORDER_CASHBACK_AMOUNT_KEY, true));
        if ($amount > 0) {
            return $amount;
        }

        return floor(floatval($order->get_subtotal()) * Carno_Wallet_Settings::get_cashback_ratio());
    }

    /**
     * ارسال آزمایشی پیامک کش‌بک یک سفارش به یک شماره دلخواه (سینک)
     *
     * @param string $mobile
     * @param int    $order_id
     * @return array{success: bool, value: string|null, error: string|null, message?: string}
     */
    public static function send_test($mobile, $order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return ['success' => false, 'value' => null, 'error' => 'سفارش یافت نشد'];
        }

        $amount  = self::get_order_cashback_amount($order);
        $user_id = $order->get_user_id();
        $balance = $user_id ? Carno_Wallet_Helpers::get_user_balance($user_id) : 0;
        $name    = $user_id ? get_userdata($user_id)->display_name : $order->get_billing_first_name();

        $message = self::build_message($order_id, $mobile, $amount, $balance, $name);
        $result  = self::send($mobile, $message);
        $result['message'] = $message;

        Carno_Wallet_Logger::log(
            $result['success'] ? 'info' : 'error',
            'sms_test',
            sprintf('پیامک آزمایشی برای سفارش #%d به %s %s', $order_id, $mobile, $result['success'] ? 'ارسال شد' : ('ناموفق بود: ' . $result['error'])),
            ['order_id' => $order_id, 'mobile' => $mobile, 'amount' => $amount, 'result' => $result]
        );

        return $result;
    }

    /**
     * هندلر اکشن آسینک: ارسال پیامک کش‌بک به کاربر
     *
     * @param int   $user_id
     * @param int   $order_id
     * @param float $amount        مبلغ کش‌بک اضافه‌شده
     * @param float $balance_after موجودی پس از کش‌بک
     */
    public static function handle_cashback_sms($user_id, $order_id, $amount, $balance_after) {
        $order = wc_get_order($order_id);
        $user  = get_userdata($user_id);

        if (!$user) {
            Carno_Wallet_Logger::log('error', 'sms', 'کاربر یافت نشد', ['user_id' => $user_id, 'order_id' => $order_id]);
            return;
        }

        $mobile  = $user->user_login;
        $message = self::build_message($order_id, $mobile, $amount, $balance_after, $user->display_name);
        $result  = self::send($mobile, $message);

        $context = ['user_id' => $user_id, 'order_id' => $order_id, 'mobile' => $mobile, 'amount' => $amount, 'result' => $result];

        if ($result['success']) {
            Carno_Wallet_Logger::log('info', 'sms', sprintf('پیامک کش‌بک برای %s ارسال شد', $mobile), $context);
            if ($order) {
                $order->add_order_note(sprintf('📱 پیامک کش‌بک به شماره %s ارسال شد.', $mobile));
            }
        } else {
            Carno_Wallet_Logger::log('error', 'sms', sprintf('ارسال پیامک کش‌بک به %s ناموفق بود: %s', $mobile, $result['error']), $context);
            if ($order) {
                $order->add_order_note(sprintf('⚠️ ارسال پیامک کش‌بک به شماره %s ناموفق بود: %s', $mobile, $result['error']));
            }
        }
    }
}
