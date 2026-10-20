# قائمة إنجاز أي وحدة أو شاشة أو مستند جديد

## قاعدة البيانات
- [ ] الجداول في `database/schema/rroka_schema.sql` (قبل `COMMIT;`) **و** migration جديدة متكررة الأمان (`IF NOT EXISTS` / `CREATE OR REPLACE`)، ومُجرَّبة مرتين على قاعدة التطوير.
- [ ] كل ضابط Trigger/Constraint برسالة `RROKA_*`، وترجمتها في `lang/ar/rroka.php` و`lang/en/rroka.php` (`errors`).
- [ ] لكل ضابط اختبار «مسموح + ممنوع» في `database/sql-tests/schema_rules_test.sql`.
- [ ] مشغّلات التدقيق (`fn_audit`) وتحديث `updated_at`.
- [ ] الترقيم عبر `fn_doc_number` (يعمل مع وضع معاينة الاستيراد).
- [ ] المعتمد لا يُعدَّل، والحذف للمسودة فقط (`fn_delete_draft_only`).
- [ ] الأعمدة المحسوبة لا تُقرأ داخل BEFORE triggers.

## إن كان مستندًا يُرحَّل
- [ ] فرع في `fn_post_document`، والنوع في كل القوائم: `journal_entries_source_type_check`، `journal_entries_document_source`، `ux_journal_entries_document`، `posting_exclusions`، `fn_journal_entry_auto`، `fn_is_demo_document`، `v_posting_backlog`، و`JournalEntry::DOCUMENT_SOURCES` و`documentUrl`، و`rroka.journal_source`.
- [ ] الأدوار المطلوبة في `fn_posting_gaps` و`PostingController::REQUIRED_ROLES`، وتحديث مطابقة الدفاتر إن كان له حساب رقابي.
- [ ] أثره على الإقرار الضريبي (`v_vat_documents`) وقفل الضريبة.
- [ ] فحص في `Exceptions::definitions()` إن كان له خلل محتمل، وإجراؤه في `Exceptions::actions()`.

## الكود والواجهة
- [ ] متحكم بـ `ListView` و`partials/control-panel` و`partials/statusbar` و`partials/chatter` و`partials/delete-button` و`partials/journal-link` و`partials/attachments`.
- [ ] الصلاحيات في المسارات والمتحكم، والقائمة في `AppMenu`، والبطاقة في `HomeBoard` إن كان ينتظر إجراءً.
- [ ] `RecordDeleteController::TYPES` و`DocumentAttachmentController::TYPES` عند الحاجة.
- [ ] **تعريف استيراد في `ImportRegistry`** لكل شاشة إدخال (و`approve` للمستند).
- [ ] تقرير في `ReportRegistry`/`ModuleReports` إن كان للبيانات تحليل.
- [ ] كل نص عربي داخل `__()` وترجمته في `lang/en.json`؛ الصفحات الجديدة في `LocalizationTest`.
- [ ] لا ألوان ثابتة؛ متغيرات `:root` بقيمتي الفاتح والداكن. الملفات الثابتة عبر `Asset::url()`.
- [ ] الطباعة الرسمية ترث `layouts/print`.

## الاختبار والتسليم
- [ ] `scripts/test-db.sh` ثم `php artisan test` كلها ناجحة، و`vendor/bin/pint`.
- [ ] فحص في المتصفح بالعربية والإنجليزية، فاتح وداكن، وعرض الجوال، مع صور لصاحب المشروع.
- [ ] رفع `App\Support\Release::VERSION`، وتحديث `CLAUDE.md` و`docs/PROJECT_CONTEXT.md` (و`docs/ACCOUNTING_PLAN.md` إن مسّ المحاسبة).
- [ ] الرفع إلى الفرع المحدد فقط.
