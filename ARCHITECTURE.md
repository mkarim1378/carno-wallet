# 📁 معماری چندفایلی کارنو ولت (MVC)

## مقدمه

این پروژه از معماری **MVC** (Model-View-Controller) الهام‌گرفته استفاده می‌کند تا کد را تقسیم‌بندی و سازمان‌دهی کند.

## ساختار پوشه‌ها

```
carno-wallet/
├── carno-wallet.php              # فایل اصلی افزونه
├── assets/                       # فایل‌های CSS/JS
│   ├── admin-style.css
│   └── wallet-cart.js
├── includes/                     # کوریا (وابستگی‌ها)
│   ├── helpers/                  # کمکی‌ها و ابزارها
│   │   └── class-helpers.php     # تابع‌های مشترک
│   ├── admin/                    # بخش مدیریت
│   │   ├── class-wallet-core.php # هسته اصلی
│   │   ├── class-wallet-cart.php # مدیریت سبد خرید
│   │   ├── class-wallet-admin.php # رابط ادمین
│   │   └── class-wallet-xlsx-reader.php # خواننده فایل اکسل
│   ├── gateway/                  # درگاه‌های پرداخت
│   │   └── class-wallet-gateway.php # درگاه WooCommerce
│   └── api/                      # API Endpoints
│       └── class-wallet-api.php  # REST API
```

## توضیح فهرست‌ها

### 📦 `helpers/`
شامل **تابع‌های کمکی** و ابزارهای مشترکی است که توسط دیگر فایل‌ها استفاده می‌شود.

**فایل‌ها:**
- `class-helpers.php` — تمام توابع کمکی مانند:
  - مدیریت موجودی (`get_user_balance`, `deduct_balance`)
  - تایید اعتبارات (`has_sufficient_balance`)
  - فرمت‌کردن مبالغ (`format_currency`)

### 🔐 `admin/`
شامل **منطق مدیریت و اجرا** است.

**فایل‌ها:**
- `class-wallet-core.php` — هسته اصلی:
  - نمایش موجودی در داشبورد
  - مدیریت ریفند‌های سفارش
  
- `class-wallet-cart.php` — مدیریت سبد خرید:
  - اعمال خصم خودکار
  - ذخیره اطلاعات کیف پول
  
- `class-wallet-admin.php` — رابط مدیریتی:
  - آپلود فایل اکسل
  - جستجو و ویرایش کاربران
  - ستون‌های جدول کاربران
  
- `class-wallet-xlsx-reader.php` — خواننده اکسل:
  - پردازش فایل‌های `.xlsx`
  - حفظ صفر اول

### 💳 `gateway/`
شامل **درگاه‌های پرداخت** است.

**فایل‌ها:**
- `class-wallet-gateway.php` — درگاه WooCommerce:
  - پرداخت کامل/جزئی
  - مدیریت موجودی

### 🌐 `api/`
شامل **REST API Endpoints** است (برای توسعه آینده).

**فایل‌ها:**
- `class-wallet-api.php` — API v1:
  - دریافت موجودی
  - شارژ کیف پول
  - برداشت
  - تاریخچه تراکنش‌ها

## فلوی اجرایی

```
Plugin Load (carno-wallet.php)
    ↓
Load Helpers (class-helpers.php)
    ↓
Load Admin Components
    ├─→ class-wallet-core.php
    ├─→ class-wallet-cart.php
    ├─→ class-wallet-admin.php
    └─→ class-wallet-xlsx-reader.php
    ↓
Load Gateway (class-wallet-gateway.php)
    ↓
Load API (class-wallet-api.php)
```

## مثال: کاربران چگونه استفاده می‌کنند

### ۱. نمایش موجودی
```
User Dashboard → Carno_Wallet_Core::display_wallet_balance()
                    ↓
                Carno_Wallet_Helpers::get_user_balance()
```

### ۲. خصم در سبد خرید
```
Add to Cart → Carno_Wallet_Cart::apply_wallet_discount()
               ↓
            Carno_Wallet_Helpers::get_user_balance()
               ↓
            Deduct from total
```

### ۳. آپلود اکسل
```
Admin Upload File → Carno_Wallet_Admin::handle_excel_upload()
                        ↓
                    Carno_Wallet_XLSX_Reader::read()
                        ↓
                    Carno_Wallet_Helpers::set_user_balance()
```

## مزایای معماری جدید

✅ **تفکیک مسئولیت‌ها** — هر فایل یک وظیفه دارد  
✅ **قابلیت نگهداری** — یافتن و اصلاح کد آسان‌تر است  
✅ **قابلیت توسعه** — اضافه‌کردن فیچرهای جدید ساده‌تر  
✅ **تست‌پذیری** — تست کردن هر بخش جداگانه  
✅ **استفاده مجدد** — توابع Helper در جای‌های مختلف بکار می‌روند  

## نسخه‌ی جدید

**v1.3.0** — معماری چندفایلی (MVC)

## توسعه‌دهندگان

به یاد داشته باشید:

1. **Helpers اول** — اگر تابع عمومی بنویسید، در `helpers/` قرار دهید
2. **Import کلاس‌ها** — همیشه کلاس‌ها را در اول فایل import کنید
3. **استفاده از Helpers** — از توابع Helper بجای تکرار کد استفاده کنید
4. **Documentation** — کد PHP خود را document کنید (PHPDoc)
