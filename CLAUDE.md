# إر روكا للأثاث — RRoka ERP

ورشة تصنيع أثاث سعودية (Make-to-Order بالكامل، أقل من 10 مشاريع/شهر).
التفاصيل الكاملة للسياق والقرارات: `docs/PROJECT_CONTEXT.md`.

## قواعد حاكمة (لا تُكسر دون مراجعة رسمية مُسجَّلة في `docs/PROJECT_CONTEXT.md`)

1. **النظام = Operations & Costing فقط.** لا محرك محاسبي ولا ضريبي داخلي.
   الفوترة الرسمية، القيود، التحصيل، VAT/ZATCA/Zakat → **دفترة (Daftra)** عبر API.
2. **Zero Assumption Policy.** معدلات التكلفة (أجر ساعة العامل، تكلفة ساعة الآلة،
   نسبة تحميل المصاريف غير المباشرة) تبقى `NULL` صراحةً — لا صفر، ولا متوسطات صناعية.
   أي حساب يعتمد عليها يجب أن يُرجع "غير مُفعَّل" لا رقمًا.
3. **لا مخزون منتجات تامة.** المخزون = خامات ومستلزمات فقط.
4. **القواعد الرقابية تُفرض في قاعدة البيانات (Triggers/Constraints) وفي الـ API معًا**،
   لا في الواجهة فقط.
5. لا تُختلق بيانات تجريبية على أنها حقيقية؛ أي Seed يُوسم صراحة كبيانات اختبار.

## حقائق Daftra API (مُتحقَّق منها من docs.daftara.dev)

- Base URL: `https://{subdomain}.daftra.com/api2`
- Headers: `apikey` + `Authorization: Bearer <token>` معًا
- `POST /clients` → `{"Client": {"business_name": ...}}` → 202 + `id`, `client_number`
- `POST /estimates` → `Estimate` + `InvoiceItem[]` → 202 + `id`
- تحويل عرض سعر لفاتورة: نقطة منفصلة — **لم تُفحص بعد، لا تُنفَّذ قبل التحقق**.

## بنية الكود وأوامر التطوير

- `database/schema/rroka_schema.sql` هو المصدر الوحيد للمخطط؛ Laravel يحمّله كما هو
  (`backend/database/migrations/0000_00_00_000000_load_rroka_schema.php`). لا تُنشئ جداول الأعمال بـ Schema Builder.
- كل قاعدة رقابية جديدة في المخطط تحتاج اختبارًا في `database/tests/schema_rules_test.sql` (مسموح + ممنوع).
- اختبارات: `scripts/test-db.sh` (مع PGHOST/PGUSER/PGPASSWORD) ثم `cd backend && php artisan test`.
- التنسيق: `cd backend && vendor/bin/pint`.
- رفض قاعدة في قاعدة البيانات يُرفع بـ `RROKA_*` ويظهر للـ API كـ 422 (`bootstrap/app.php`).
- معدلات التكلفة جداول مؤرَّخة وإلحاقية فقط (`worker_rates`, `machine_rates`, `overhead_rates`).
