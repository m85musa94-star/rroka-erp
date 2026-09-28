# RRoka ERP — نظام التشغيل والتكلفة لورشة إر روكا للأثاث

نظام **تشغيل وتكلفة فقط** (Operations & Costing). المحاسبة والفوترة الرسمية والضرائب في **دفترة**.
القرارات والسياق: [`docs/PROJECT_CONTEXT.md`](docs/PROJECT_CONTEXT.md) · مرجع الـ API: [`docs/api_reference.md`](docs/api_reference.md)

## البنية

| المسار | المحتوى |
|---|---|
| `database/schema/rroka_schema.sql` | مخطط PostgreSQL — **المصدر الوحيد** للجداول والقواعد الرقابية |
| `database/sql-tests/schema_rules_test.sql` | اختبارات القواعد الرقابية (مسموح/ممنوع لكل قاعدة) |
| جذر المستودع | تطبيق Laravel 13: الواجهة العربية + REST API — يحمّل المخطط أعلاه عبر migration |
| `.github/workflows/tests.yml` | تشغيل كل الاختبارات وبناء صورة النشر تلقائيًا مع كل رفع |
| `Dockerfile`, `docker/` | صورة التشغيل للخادم — انظر [`docs/DEPLOYMENT.md`](docs/DEPLOYMENT.md) |

## التشغيل محليًا (للمطوّر)

```bash
# 1) قاعدة البيانات: PostgreSQL 16، مستخدم وقاعدتان
createdb rroka && createdb rroka_testing

# 2) اختبار القواعد الرقابية على قاعدة مؤقتة
PGHOST=127.0.0.1 PGUSER=... PGPASSWORD=... scripts/test-db.sh

# 3) التطبيق
composer install
cp .env.example .env && php artisan key:generate   # ثم عبّئ DB_PASSWORD
php artisan migrate
php artisan rroka:create-admin owner@example.com "اسم المدير"
php artisan test
php artisan serve
```

## ما لا يفعله النظام عمدًا
- لا قيود محاسبية، لا حساب VAT، لا فواتير رسمية → دفترة.
- لا معدلات تكلفة افتراضية: التكلفة تبقى "غير مكتملة" حتى تُدخل المعدلات الحقيقية.
- لا مخزون منتجات تامة (Make-to-Order).
