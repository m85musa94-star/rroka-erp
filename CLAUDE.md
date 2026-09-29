# إر روكا للأثاث — RRoka ERP

ورشة تصنيع أثاث سعودية (Make-to-Order بالكامل، أقل من 10 مشاريع/شهر).
التفاصيل الكاملة للسياق والقرارات: `docs/PROJECT_CONTEXT.md`.

## قواعد حاكمة (لا تُكسر دون مراجعة رسمية مُسجَّلة في `docs/PROJECT_CONTEXT.md`)

1. **النظام = Operations & Costing فقط.** لا محرك محاسبي ولا ضريبي داخلي.
   الفوترة الرسمية، القيود، التحصيل، VAT/ZATCA/Zakat → **دفترة (Daftra)** عبر API.
   تفسير معتمد (2026-09-30، تجربة): مستندات المشتريات والمصروفات تُلتقط هنا مرة واحدة وتُرسل لدفترة؛ لا قيود ولا احتساب ضريبة هنا.
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
- نقاط القراءة (`/invoices.json`، `/invoice_payments.json`، …) **غير مُتحقَّق منها**؛ `DaftraProbe` يفحصها على الحساب الفعلي.
  لا يُبنى تقرير مالي على حقل لم يظهر في نتيجة الفحص.

## بنية الكود وأوامر التطوير

- `database/schema/rroka_schema.sql` هو المصدر الوحيد للمخطط؛ Laravel يحمّله كما هو
  (`database/migrations/0000_00_00_000000_load_rroka_schema.php`). لا تُنشئ جداول الأعمال بـ Schema Builder.
- **النظام يعمل فعليًا منذ 2026-09-28:** أي تعديل على المخطط يُضاف إلى `rroka_schema.sql` (للتثبيت الجديد) **وإلى migration جديدة** بصيغة `CREATE OR REPLACE`/`IF NOT EXISTS` (لقاعدة الإنتاج القائمة)، ويُختبر الترقية من النسخة السابقة.
- كل قاعدة رقابية جديدة في المخطط تحتاج اختبارًا في `database/sql-tests/schema_rules_test.sql` (مسموح + ممنوع).
- اختبارات: `scripts/test-db.sh` (مع PGHOST/PGUSER/PGPASSWORD) ثم `php artisan test`.
- التنسيق: `vendor/bin/pint`.
- رفض قاعدة في قاعدة البيانات يُرفع بـ `RROKA_*` ويظهر للـ API كـ 422 (`bootstrap/app.php`).
- معدلات التكلفة جداول مؤرَّخة وإلحاقية فقط (`worker_rates`, `machine_rates`, `overhead_rates`).
- النشر: `Dockerfile` في الجذر (صورة serversideup/php FrankenPHP) و`docker/start.sh`؛ الدليل في `docs/DEPLOYMENT.md`.
- `SetAuditUser`: كل طلب كتابة معاملة واحدة، و`rroka.user_id` محلي للمعاملة (`AuditContext::apply`)؛ مسارات المزامنة مع دفترة `audit.user:manual` وتطبّق السياق بنفسها.
- الاستضافة المختارة: Laravel Cloud. تطبيق Laravel في جذر المستودع مباشرة (نُقل من `backend/` لأن المنصة تبني من الجذر).
- الواجهة Blade بلا خطوة بناء (CSS في `public/css/app.css`)؛ الرسائل العربية في `lang/ar/rroka.php`.
- الواجهة ثنائية اللغة: كل نص ثابت يُكتب بالعربية داخل `__('…')` وترجمته في `lang/en.json`، والرسائل ذات المفاتيح في `lang/en/*.php`.
  لغة المستخدم في `users.locale` (`SetLocale`)، والاتجاه `dir` يتبعها. `LocalizationTest` يفشل إن بقي نص عربي في صفحة إنجليزية —
  أي صفحة جديدة تُضاف إلى قائمته، وأي نص جديد يحتاج ترجمته.
- الواجهة على نمط Odoo: `AppMenu` (شبكة التطبيقات وقائمة الشريط العلوي)، `ListView` (بحث/فلاتر/تجميع/عرض قائمة أو بطاقات)، `partials/control-panel`، `partials/statusbar`، و`partials/chatter` (سجل النشاط من `audit_log` عبر `ActivityLog`). كل وحدة جديدة تستخدمها.
- التقارير: `app/Reports` (محرك جدول محوري واحد بـ GROUPING SETS، فالنسب لا تُجمع خطأً)، يُسجَّل كل تقرير في `ReportRegistry` مع أبعاده ومقاييسه وفلاتره وصلاحيته. القيمة غير القابلة للحساب تُعرض "—" لا صفرًا.
- الاستوديو: `studio_assets` + `StudioStorage` (قرص `studio` خاص، مصغّرات GD، منع التكرار بـ SHA-256). الصور تُقدَّم عبر `studio.file` بعد فحص الصلاحية فقط.
  في الإنتاج يُرفض الرفع إن كان القرص محليًا (`StudioStorage::isReady`). صورة العميل لا تظهر إلا في عروض أسعاره (Trigger).
- التصنيع: `ProductionController` (أوامر التصنيع: مكونات من قائمة مواد النسخة، حجز/صرف/إرجاع عبر `stock_movements`، ساعات، فحوصات)، `DesignController` (نسخ ومراحل وقائمة مواد)، `MaterialController` (المخزون). الحجز والصرف على مستوى المشروع (`fn_project_reserved`/`fn_project_issued`).
- الموارد البشرية: `Employee` (جدول `workers` نفسه الذي يستخدمه `Worker` للتكلفة)، `EmployeeController` (الدليل `hr.view` بلا بيانات شخصية)، `ContractController` (`hr.contracts`)، `AttendanceController` (`hr.attendance`)، `LeaveController` (خدمة ذاتية عبر `workers.user_id`؛ الاعتماد `hr.leave_approve`). صلاحية القائمة الوهمية `self.employee` في `AppMenu::can`. لا احتساب رواتب.
- المشتريات والمصروفات: `PurchaseController` (اعتماد الفاتورة يُدخل المخزون عبر `fn_purchase_invoice_post`)، `ExpenseController` (المصروف على مشروع يدخل `v_project_actual_cost.direct_expense_cost`). الاستلام اليدوي في المخزون ملغى. صور المستندات فئة `DOCUMENT` في الاستوديو ولا تظهر في المعرض (`scopeGallery`).
- المظهر: فاتح/داكن/تلقائي لكل مستخدم (`users.theme`، `App\Support\Theme`، `data-theme` على `<html>`). **لا ألوان ثابتة في القوالب أو CSS المكوّنات**: كل لون متغير في `:root` له قيمة داكنة في النطاقين (`@media (prefers-color-scheme: dark)` و`:root[data-theme="dark"]`). ألوان الرسوم `--series-1..7` مُتحقَّق منها لكل وضع، والطباعة فاتحة دائمًا.
  الملفات الثابتة تُربط دائمًا عبر `App\Support\Asset::url()` (رابط بإصدار من محتوى الملف)؛ الرابط الثابت جعل المتصفح يحتفظ بملف ألوان قديم فلم يعمل الوضع الداكن.
