# دليل النشر والتشغيل على استضافة Hostinger
**Project:** Clinic Appointment Management & Automated WhatsApp Booking System  
**Backend:** Laravel 11 / MySQL / Meta WhatsApp Cloud API  

---

## 1. المتطلبات الأساسية على استضافة Hostinger
1. خطة استضافة تدعم **PHP 8.2 أو 8.3** مع إضافات `pdo_mysql`, `curl`, `mbstring`, `openssl`, `intl`.
2. قاعدة بيانات MySQL جديدة (من لوحة تحكم hPanel: Databases -> MySQL Databases).
3. نطاق (Domain) أو نطاق فرعي مع شهادة **SSL مجانية (HTTPS)** مفعلة (إلزامي لمستقبل WhatsApp Webhook).

---

## 2. خطوات النشر خطوة بخطوة

### الخطوة 1: رفع المشروع
- قم بضغط ملفات المشروع (باستثناء مجلد `vendor` ومجلد `.git` وملف `.env` المحلي) بصيغة `zip`.
- ارفع الملف عبر **File Manager** في Hostinger إلى مجلد الموقع (مثلاً: `public_html`).
- قم بفك الضغط.
- **توجيه الـ Document Root:**
  - في استضافة Hostinger، تأكد أن مسار تشغيل النطاق يشير إلى مجلد **`public_html/public`** وليس المجلد الرئيسي، لضمان حماية ملفات Laravel الداخلية.

### الخطوة 2: تثبيت الحزم (عبر SSH في Hostinger)
افتح اتصال SSH مع حساب الاستضافة ونفّذ:
```bash
composer install --optimize-autoloader --no-dev
```

### الخطوة 3: إعداد ملف البيئة `.env` في السيرفر
انسخ `.env.example` إلى `.env` واضبط المتغيرات الحقيقية:
```env
APP_NAME="عيادة الشفاء التخصصية"
APP_ENV=production
APP_KEY=base64:... # نفذ php artisan key:generate
APP_DEBUG=false
APP_URL=https://your-clinic-domain.com

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=u123456789_clinic_db
DB_USERNAME=u123456789_clinic_user
DB_PASSWORD=YOUR_STRONG_HOSTINGER_PASSWORD

# إعدادات WhatsApp Cloud API (من Meta Developers Portal)
WHATSAPP_TOKEN="EAAxxxxxx..."
WHATSAPP_PHONE_NUMBER_ID="123456789012345"
WHATSAPP_VERIFY_TOKEN="clinic_secure_verify_secret_2026"
WHATSAPP_APP_SECRET="app_secret_from_meta"
WHATSAPP_API_VERSION="v21.0"
```

### الخطوة 4: ترحيل قاعدة البيانات والبيانات الأولية
نفّذ عبر SSH:
```bash
php artisan migrate --force
php artisan db:seed --class=ClinicSeeder --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

### الخطوة 5: إنشاء حساب السكرتيرة للـ API
نفّذ عبر `php artisan tinker` على السيرفر:
```php
\App\Models\User::create([
    'name' => 'سكرتيرة العيادة',
    'email' => 'secretary@yourclinic.com',
    'password' => bcrypt('StrongPasswordHere'),
]);
```

---

## 3. ربط الـ Webhook في بوابة Meta للمطورين (Meta Developer Portal)

1. ادخل إلى [developers.facebook.com](https://developers.facebook.com) واختر تطبيق WhatsApp الخاص بالعيادة.
2. اذهب إلى **WhatsApp ➔ Configuration ➔ Webhook**.
3. في حقل **Callback URL** ضع:
   ```
   https://your-clinic-domain.com/api/v1/whatsapp/webhook
   ```
4. في حقل **Verify Token** ضع نفس القيمة المحددة في `.env`:
   ```
   clinic_secure_verify_secret_2026
   ```
5. اضغط **Verify and Save**.
6. في قائمة الـ Webhook Fields، فعّل خيار: **`messages`**.

---

## 4. الفحص الدوري لتنظيف الحجوزات المنتهية (Cron Job)

في لوحة تحكم Hostinger hPanel ➔ **Cron Jobs**، أضف أمر الفحص التلقائي ليعمل كل دقيقة لتنظيف المواعيد المعلقة التي لم يؤكدها المرضى:
```bash
* * * * * cd /home/u123456789/domains/your-domain.com/public_html && php artisan schedule:run >> /dev/null 2>&1
```
