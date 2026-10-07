# إر روكا للأثاث — RRoka ERP

ورشة تصنيع أثاث سعودية (Make-to-Order بالكامل، أقل من 10 مشاريع/شهر).
التفاصيل الكاملة للسياق والقرارات: `docs/PROJECT_CONTEXT.md`.

## قواعد حاكمة (لا تُكسر دون مراجعة رسمية مُسجَّلة في `docs/PROJECT_CONTEXT.md`)

1. **النظام = التشغيل والتكلفة والمحاسبة (مُعدَّلة رسميًا 2026-10-06).** دليل الحسابات والقيود والبنوك والمصروفات
   والمبيعات والتحصيل وضريبة المخرجات/المدخلات وتقرير الإقرار والقوائم المالية **هنا**.
   **دفترة = بوابة ختم الفاتورة الضريبية فقط** (حل فوترة إلكترونية متوافق مع ZATCA): الفاتورة تُنشأ هنا وتُرسل لتصدر نظاميًا؛ حسابات دفترة لا تُستخدم.
   لا تُبنى فوترة إلكترونية (منصة «فاتورة») داخل النظام. بداية الدفاتر 2026-01-01. الخطة: `docs/ACCOUNTING_PLAN.md`.
   تفسير سابق ما زال ساريًا (2026-10-05): ضريبة عرض السعر من نسبة يختارها المستخدم (`vat_rates`، بلا نسبة = بلا ضريبة).
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
- الخزينة والعهد: `PaymentAccountController` (صندوق/بنك/عهدة موظف + كشف حركات وتصدير CSV)، `TreasuryTransferController` (صرف العهدة وإرجاعها والتحويل). كل مصروف معتمد له `payment_account_id`. الرصيد للعهدة فقط (`fn_custody_balance`، `v_payment_account_summary`)؛ لا رصيد للصندوق أو البنك هنا لأن التحصيل في دفترة.
- محرك التكلفة (المرحلة ١): `CostingController` (البداية، مراكز التكلفة، الكهرباء، اعتماد/إلغاء موحّد عبر `CostingController::TYPES`)، `EmployeeCostCardController`، `MachineCostCardController`، `OverheadPoolController`، `MaterialCostController`. كل سجل معدل يستخدم `fn_cost_record_guard` (إصدار، مسودة→معتمد نهائي، منع الرجوع بالتاريخ) و`VersionedCostRecord`. المعادلات أعمدة محسوبة في قاعدة البيانات؛ الخطة في `docs/COSTING_ENGINE_PLAN.md`.
- محرك التكلفة (المرحلة ٢): `CostEstimateController` (ورقة تكلفة لكل بند عرض: `/quotations/{q}/lines/{n}/costing`)، والتقدير مربوط برقم البند (`cost_estimates.quotation_id + line_no`) لأن البنود تُعاد كتابتها عند التعديل (`Quotation::dropOrphanEstimates`). الأرقام من `v_estimate_costs`؛ الاعتماد يمر عبر `fn_quotation_costing_gate` (يرفض الناقص ويجمّد `cost_estimate_snapshots`). الاختبارات غير الخاصة بالتكلفة تعمل بعروض معفاة (`ApiTestCase::$costingGate = false`).
- المظهر: فاتح/داكن/تلقائي لكل مستخدم (`users.theme`، `App\Support\Theme`، `data-theme` على `<html>`). **لا ألوان ثابتة في القوالب أو CSS المكوّنات**: كل لون متغير في `:root` له قيمة داكنة في النطاقين (`@media (prefers-color-scheme: dark)` و`:root[data-theme="dark"]`). ألوان الرسوم `--series-1..7` مُتحقَّق منها لكل وضع، والطباعة فاتحة دائمًا.
  الملفات الثابتة تُربط دائمًا عبر `App\Support\Asset::url()` (رابط بإصدار من محتوى الملف)؛ الرابط الثابت جعل المتصفح يحتفظ بملف ألوان قديم فلم يعمل الوضع الداكن.
- الطباعة الرسمية: `layouts/print` (ورق الشركة المعتمد `public/img/letterhead-a4.png` من `docs/branding/roka-letterhead-a4.pdf`، يتكرر في كل صفحة A4 والمحتوى بين الترويسة والتذييل). أي مستند مطبوع جديد يرث هذا القالب. عرض السعر المطبوع `quotations.print` لا يُظهر أي تكلفة. الفاتورة الضريبية تصدر من دفترة فيُضبط الورق هناك.
- المحاسبة (المرحلة ١): `AccountController` (دليل الحسابات شجرة + دفتر الأستاذ برصيد متحرك وCSV؛ الدليل المقترح `ChartTemplate` بزر في دليل فارغ فقط)، `JournalEntryController` (مسودة → ترحيل؛ التصحيح بقيد عكسي مطابق فقط)، `AccountingController` (ميزان المراجعة، الفترات الشهرية: إقفال بالترتيب وإعادة فتح بسبب). القواعد في قاعدة البيانات: `fn_journal_entry_guard` (توازن، بداية الدفاتر `accounting_settings.books_start`، فترة مقفلة، ترقيم بلا فجوات `journal_sequences`)، `fn_journal_line_guard`، `fn_account_guard`، `fn_fiscal_period_guard`. كل الأرصدة من `v_ledger_lines` (المرحَّل فقط). الأعمدة المحسوبة لا تُقرأ داخل BEFORE triggers (استعمل الأعمدة الأساسية). الصلاحيات `accounting.view|manage|post|close`.
- دليل الحسابات (نمط Odoo، 2026-10-07): `accounts.detail_type` (النوع التفصيلي يشتق `account_type` عبر `fn_account_class`/`fn_account_detail`؛ مطلوب للحساب الذي يقبل القيود وممنوع للمجموعة)، `accounts.reconcile` (إلزامي للعملاء والموردين)، حساب «أرباح السنة الحالية» واحد فقط. القائمة `accounting.accounts.index` (ListView)، النموذج `accounts.create/edit` بأزرار ذكية، المجموعات `accounting.accounts.groups`. الأرشفة `is_active=false` بدل الحذف.
- التقارير المالية (نمط Odoo، 2026-10-07): `App\Accounting\FinancialReports` (قائمة الدخل، الميزانية العمومية مع فحص التوازن وأرباح السنة المحسوبة، دفتر الأستاذ العام بحسابات تُفتح، ميزان المراجعة) + `ReportOptions` (فترات جاهزة، مقارنة بالفترة السابقة أو السنة الماضية، إظهار الصفري، فتح الكل). `FinancialReportController` على `/accounting/reports/{key}`؛ الطباعة `?print=1` على الورق الرسمي، والتصدير `?export=xlsx` عبر `App\Support\Xlsx` (كاتب XLSX بلا مكتبات). الحساب بلا نوع يظهر «غير مصنّف» مع تحذير. تقارير التحليل المحورية لها أيضًا فترات سريعة وطباعة وExcel، والسالب بين قوسين.
- الربط المحاسبي (الخطوة أ، 2026-10-08): `fn_post_document(type, id, user)` يكتب ويرحّل قيد المستند (`EXPENSE`/`PURCHASE`/`TRANSFER`/`STOCK`) عبر `fn_journal_auto_insert` (علم `rroka.auto_posting` المحلي؛ `fn_journal_entry_auto` يمنع غيره من إنشاء أو عكس قيد مستند). المشغّلات `trg_*_post_gl` تعمل عند الاعتماد إذا `accounting_settings.auto_posting`. قيد واحد لكل مستند (`ux_journal_entries_document`)، والاستبعاد بسبب في `posting_exclusions`، والمتأخرات `v_posting_backlog`. الربط `payment_accounts.account_id` و`expense_categories.account_id` والأدوار في `PostingController::REQUIRED_ROLES`. الشاشات: `accounting.posting.settings|backlog|reconciliation`؛ رابط القيد في المستند `partials/journal-link`.
- رقم الإصدار الظاهر أسفل كل صفحة في `App\Support\Release::VERSION` (تاريخ.تسلسل؛ ثابت لا إعداد، لأن ذاكرة الإعدادات المؤقتة على الاستضافة أخفت القيمة)؛ يُرفع مع كل دفعة تُرفع للفرع ليتحقق صاحب المشروع من نجاح النشر.
- حذف البيانات التجريبية: `DemoData::blockers()` يسمّي السجلات غير التجريبية المرتبطة بها (من مفاتيح قاعدة البيانات)، وحذفها معها بموافقة صريحة (`with_linked`) — لا قيود محاسبية ولا حركات خامات حقيقية.
- الحذف في كل الشاشات: `RecordDeleteController::TYPES` + `partials/delete-button`؛ المستند يُحذف مسودةً فقط (`fn_delete_draft_only` في قاعدة البيانات)، والبيانات الأساسية فقط إن لم تُستخدم (المفاتيح الأجنبية ترفض، فتُوقف بدل الحذف).
- الرئيسية (2026-10-08): شبكة التطبيقات + «البدء بالمنظومة» (قائمة خطوات تُحسب من البيانات حتى تكتمل) + «ما ينتظر إجراءً منك» (بطاقات لا تظهر إلا إن كان فيها شيء، كل بطاقة رابط لسجلاتها) — `App\Support\HomeBoard`، وكل بند مقيّد بصلاحيته. لوحة المحاسبة `accounting.dashboard` (بطاقة لكل خزينة على نمط يوميات Odoo) هي مدخل تطبيق المحاسبة.
