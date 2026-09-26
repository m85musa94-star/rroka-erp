# مرجع الـ API — المنفَّذ فعليًا

جميع المسارات تحت `/api`. المصادقة: `Authorization: Bearer <token>` من `POST /api/auth/login`.
كل مسار محمي بصلاحية (الجدول `permissions`)، ويُسجَّل المستخدم المنفِّذ في `audit_log` تلقائيًا.

## شكل الأخطاء
| الحالة | متى | الجسم |
|---|---|---|
| 422 | رفض قاعدة رقابية في قاعدة البيانات | `{"error": "RROKA_…", "message": "…"}` |
| 422 | فشل التحقق من المدخلات | صيغة Laravel القياسية (`errors`) |
| 403 | لا توجد الصلاحية | `{"error": "FORBIDDEN", "permission": "…"}` |
| 409 | حالة لا تسمح بالعملية (مزامنة مكررة…) | `{"error": "DAFTRA_…"}` |
| 502 | فشل الاتصال بدفترة (مُسجَّل في `daftra_sync_log`) | `{"error": "DAFTRA_SYNC_FAILED", …}` |

## المسارات
| الطريقة | المسار | الصلاحية | ملاحظة |
|---|---|---|---|
| POST | `/auth/login` | — | 10 محاولات/دقيقة |
| POST | `/auth/logout` | — | |
| GET | `/clients` `?search=` | `clients.view` | |
| GET | `/clients/{id}` | `clients.view` | |
| POST | `/clients` | `clients.manage` | `business_name` إلزامي، `vat_number` 15 رقمًا |
| PATCH | `/clients/{id}` | `clients.manage` | |
| POST | `/clients/{id}/sync-to-daftra` | `daftra.sync` | `POST /clients` في دفترة؛ يُرسل `business_name` فقط (الحقل الوحيد المتحقَّق منه) |
| GET | `/quotations` `?status=` | `quotations.view` | |
| GET | `/quotations/{id}` | `quotations.view` | يشمل `totals` من العرض `v_quotation_totals` (قبل الضريبة) |
| POST | `/quotations` | `quotations.manage` | يُنشأ `DRAFT` مع بنوده |
| PUT | `/quotations/{id}` | `quotations.manage` | استبدال كامل — مسموح في `DRAFT` فقط |
| POST | `/quotations/{id}/send` | `quotations.manage` | `DRAFT → SENT` (يُرفض العرض الفارغ) |
| POST | `/quotations/{id}/approve` | `quotations.approve` | `SENT → APPROVED` ويُسجَّل المعتمِد |
| POST | `/quotations/{id}/reject` | `quotations.approve` | `SENT → REJECTED` |
| POST | `/quotations/{id}/sync-to-daftra` | `daftra.sync` | **مقفل** حتى `DAFTRA_ESTIMATE_MAPPING_VERIFIED=true` |
| GET | `/projects` | `projects.view` | |
| GET | `/projects/{id}` | `projects.view` | |
| POST | `/projects` | `projects.manage` | يُرفض إن لم يكن العرض `APPROVED`؛ العميل وقيمة العقد تُنسخ من العرض |
| GET | `/projects/{id}/costing` | `costing.view` | مكوّن بلا معدل = `null`، و`costing_gaps` تذكر الناقص |

## تدفق المزامنة مع دفترة
1. قفل استشاري (advisory lock) على الكيان، ورفض إن وُجد سجل `PENDING` سابق (منع الإرسال المزدوج).
2. إنشاء سجل `PENDING` في `daftra_sync_log` و**حفظه قبل** الاتصال.
3. الاتصال بدفترة.
4. نجاح → السجل `SUCCESS` مع `daftra_id`، وتحديث الكيان. فشل → السجل `FAILED` مع رمز HTTP ونص الخطأ، والكيان لا يتغير.
5. سجل بقي `PENDING` = انقطاع أثناء الاتصال → **يُراجَع يدويًا في دفترة قبل إعادة المحاولة**.

## لم يُنفَّذ بعد
المعاينات، التصاميم، BOM، المخزون، أوامر الإنتاج، ساعات العمل، الجودة، التركيب، إعدادات معدلات التكلفة —
الجداول والقواعد موجودة ومختبرة في قاعدة البيانات، والمسارات هي المرحلة التالية.
