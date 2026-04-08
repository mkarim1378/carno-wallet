# 📋 دستورالعمل مهاجرت برای توسعه‌دهندگان

## ⚠️ تغییرات شکاف‌آور (Breaking Changes)

از نسخه **1.3.0** به بعد، ساختار فایل‌ها تغییر کرده است.

---

## 🔴 توابع قدیم (ممنوعه)

```php
// ❌ این راه‌ها دیگر کار نمی‌کنند
require_once 'includes/class-wallet-core.php';
require_once 'includes/class-wallet-cart.php';
require_once 'includes/class-wallet-admin.php';
require_once 'includes/class-wallet-gateway.php';
require_once 'includes/class-wallet-xlsx-reader.php';
```

---

## 🟢 راه‌های جدید (توصیه‌شده)

### استفاده مستقیم از Helpers

```php
// ✅ قدیم (هنوز کار می‌کند اما Deprecated)
$balance = Carno_Wallet_Core::get_user_balance($user_id);

// ✅ جدید (توصیه‌شده)
$balance = Carno_Wallet_Helpers::get_user_balance($user_id);
```

### استفاده از توابع جدید

```php
// کاربران لاگین‌شده
if (Carno_Wallet_Helpers::is_user_logged_in()) {
    $user_id = Carno_Wallet_Helpers::get_current_user_id();
    $balance = Carno_Wallet_Helpers::get_user_balance($user_id);
}

// مدیری‌ها
if (Carno_Wallet_Helpers::current_user_can_manage_wallet()) {
    Carno_Wallet_Helpers::set_user_balance($user_id, 100000);
}

// تایید موجودی
if (Carno_Wallet_Helpers::has_sufficient_balance($user_id, 50000)) {
    Carno_Wallet_Helpers::deduct_balance($user_id, 50000);
}

// فرمت‌کردن
echo Carno_Wallet_Helpers::format_currency(100000);
// نتیجه: 100000 تومان
```

---

## 📂 ساختار نسخه 1.3.0+

```
includes/
├── helpers/                      ← تابع‌های کمکی
│   └── class-helpers.php         ← استفاده کنید!
├── admin/                        ← اجزای مدیریت (داخلی)
│   ├── class-wallet-core.php
│   ├── class-wallet-cart.php
│   ├── class-wallet-admin.php
│   └── class-wallet-xlsx-reader.php
├── gateway/                      ← درگاه‌های پرداخت (داخلی)
│   └── class-wallet-gateway.php
└── api/                          ← REST API (داخلی)
    └── class-wallet-api.php
```

**نکته:** فایل‌های در `admin/`, `gateway/`, `api/` برای استفاده داخلی است.

---

## 🚀 نمونه‌های استفاده

### مثال ۱: عملیات موجودی

```php
// تابع‌های Helpers
$user_id = get_current_user_id();
$balance = Carno_Wallet_Helpers::get_user_balance($user_id);

// کسر کردن
Carno_Wallet_Helpers::deduct_balance($user_id, 50000);

// اضافه کردن
Carno_Wallet_Helpers::add_to_balance($user_id, 100000);

// تنظیم
Carno_Wallet_Helpers::set_user_balance($user_id, 250000);
```

### مثال ۲: تایید و اعتبارسنجی

```php
// تایید موجودی
if (!Carno_Wallet_Helpers::has_sufficient_balance($user_id, 100000)) {
    wp_die('موجودی کافی نیست');
}

// تایید مبلغ
$amount = 50000;
if (!Carno_Wallet_Helpers::is_valid_amount($amount)) {
    wp_die('مبلغ نامعتبر است');
}

// تایید دسترسی مدیریتی
if (!Carno_Wallet_Helpers::current_user_can_manage_wallet()) {
    wp_die('دسترسی غیرمجاز');
}
```

### مثال ۳: نمایش و فرمت‌کردن

```php
// دریافت موجودی
$balance = Carno_Wallet_Helpers::get_user_balance($user_id);

// نمایش
echo 'موجودی شما: ' . Carno_Wallet_Helpers::format_currency($balance);
// نتیجه: موجودی شما: 1500000 تومان

// نام کاربر
$name = Carno_Wallet_Helpers::get_user_display_name($user_id);
echo "سلام $name";
```

---

## 📚 API Reference

### توابع موجود در Helpers

| تابع | پارامتر | نتیجه | مثال |
|------|--------|-------|------|
| `get_user_balance` | `int $user_id` | `float` | `Carno_Wallet_Helpers::get_user_balance(1)` |
| `set_user_balance` | `int $user_id, float $amount` | `bool` | `Carno_Wallet_Helpers::set_user_balance(1, 100000)` |
| `deduct_balance` | `int $user_id, float $amount` | `float` | `Carno_Wallet_Helpers::deduct_balance(1, 50000)` |
| `add_to_balance` | `int $user_id, float $amount` | `float` | `Carno_Wallet_Helpers::add_to_balance(1, 100000)` |
| `has_sufficient_balance` | `int $user_id, float $required_amount` | `bool` | `Carno_Wallet_Helpers::has_sufficient_balance(1, 50000)` |
| `format_currency` | `float $amount, bool $with_currency` | `string` | `Carno_Wallet_Helpers::format_currency(100000)` |
| `sanitize_amount` | `string $value` | `float` | `Carno_Wallet_Helpers::sanitize_amount('۱۰۰،۰۰۰')` |
| `is_valid_amount` | `float $amount` | `bool` | `Carno_Wallet_Helpers::is_valid_amount(100000)` |
| `get_user_display_name` | `int $user_id` | `string` | `Carno_Wallet_Helpers::get_user_display_name(1)` |
| `is_user_logged_in` | - | `bool` | `Carno_Wallet_Helpers::is_user_logged_in()` |
| `get_current_user_id` | - | `int` | `Carno_Wallet_Helpers::get_current_user_id()` |
| `current_user_can_manage_wallet` | - | `bool` | `Carno_Wallet_Helpers::current_user_can_manage_wallet()` |

---

## ✅ چک‌لیست مهاجرت

- [ ] تمام `require_once` در افزونه‌های سفارشی را به `Carno_Wallet_Helpers` تغییر دهید
- [ ] تمام `Carno_Wallet_Core::get_user_balance()` را به `Carno_Wallet_Helpers::get_user_balance()` تغییر دهید
- [ ] تمام `Carno_Wallet_Core::deduct_balance()` را به `Carno_Wallet_Helpers::deduct_balance()` تغییر دهید
- [ ] تمام `Carno_Wallet_Core::add_to_balance()` را به `Carno_Wallet_Helpers::add_to_balance()` تغییر دهید
- [ ] افزونه خود را تست کنید
- [ ] برای هرگونه خطا راه‌حل کنید

---

## 🆘 RTL Support

توابع Helpers از **RTL** (راست‌چپ) پشتیبانی کاملی دارند:

```php
// نمایش قیمت با RS (ریال علامت)
$formatted = Carno_Wallet_Helpers::format_currency(100000, true);
// نتیجه: 100000 تومان
```

---

## 📧 سوالات؟

اگر از مهاجرت مشکلی رو به‌رو شدیدساله:
1. `ARCHITECTURE.md` را بخوانید
2. نمونه‌های بالا را بررسی کنید
3. مستندات کد را چک کنید
