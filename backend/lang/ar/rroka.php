<?php

return [
    // Business-rule refusals raised by the database (RROKA_*) or the services.
    'errors' => [
        'RROKA_QUOTATION_LOCKED' => 'لا يمكن تعديل عرض السعر بعد إرساله أو إغلاقه. أعده إلى مسودة أولًا إن كان مُرسَلًا.',
        'RROKA_QUOTATION_TRANSITION' => 'هذا الانتقال في حالة عرض السعر غير مسموح.',
        'RROKA_QUOTATION_EMPTY' => 'لا يمكن إرسال عرض سعر بلا بنود.',
        'RROKA_QUOTATION_NEGATIVE' => 'الخصم أكبر من مجموع البنود.',
        'RROKA_PROJECT_NEEDS_APPROVED_QUOTATION' => 'لا يُنشأ مشروع إلا على عرض سعر معتمد.',
        'RROKA_PROJECT_CLIENT_MISMATCH' => 'عميل المشروع لا يطابق عميل عرض السعر.',
        'RROKA_PROJECT_IMMUTABLE' => 'العميل وعرض السعر وقيمة العقد ثابتة بعد إنشاء المشروع.',
        'RROKA_DESIGN_RELEASE_NEEDS_CLIENT_APPROVAL' => 'لا تُصدَر نسخة التصميم للإنتاج قبل موافقة العميل عليها.',
        'RROKA_DESIGN_VERSION_CLOSED' => 'نسخة التصميم مغلقة ولا يمكن إعادة فتحها.',
        'RROKA_DESIGN_VERSION_IMMUTABLE' => 'لا يمكن نقل نسخة التصميم إلى تصميم آخر.',
        'RROKA_BOM_LOCKED' => 'قائمة مواد النسخة المُصدَرة للإنتاج مجمَّدة؛ أنشئ نسخة جديدة.',
        'RROKA_PRODUCTION_NEEDS_RELEASED_DESIGN' => 'لا يُنشأ أمر إنتاج إلا على نسخة تصميم مُصدَرة للإنتاج.',
        'RROKA_PRODUCTION_DESIGN_PROJECT_MISMATCH' => 'نسخة التصميم لا تتبع هذا المشروع.',
        'RROKA_PRODUCTION_PROJECT_NOT_ACTIVE' => 'المشروع غير نشط.',
        'RROKA_PRODUCTION_ORDER_IMMUTABLE' => 'لا يمكن تغيير مشروع أمر الإنتاج أو تصميمه.',
        'RROKA_PRODUCTION_ORDER_CLOSED' => 'أمر الإنتاج مغلق.',
        'RROKA_PRODUCTION_NEEDS_FINAL_QC' => 'لا يُغلق أمر الإنتاج قبل فحص جودة نهائي ناجح.',
        'RROKA_STOCK_INSUFFICIENT' => 'الكمية المتاحة في المخزون لا تكفي.',
        'RROKA_STOCK_UNRESERVE_EXCEEDS' => 'الكمية أكبر من المحجوز لهذا المشروع.',
        'RROKA_STOCK_ISSUE_EXCEEDS_RESERVATION' => 'الكمية أكبر من المحجوز لهذا المشروع.',
        'RROKA_STOCK_RETURN_EXCEEDS_ISSUED' => 'الكمية المرتجعة أكبر من المصروف للمشروع.',
        'RROKA_STOCK_PROJECT_MISMATCH' => 'أمر الإنتاج يتبع مشروعًا آخر.',
        'RROKA_MOVEMENT_IMMUTABLE' => 'حركات المخزون لا تُعدَّل؛ سجّل حركة عكسية.',
        'RROKA_DERIVED_TABLE' => 'الأرصدة تُحسب آليًا من الحركات ولا تُعدَّل مباشرة.',
        'RROKA_RATE_HISTORY' => 'المعدلات لا تُعدَّل ولا تُحذف؛ أضف معدلًا جديدًا بتاريخ سريان جديد.',
        'RROKA_TIME_LOG_ORDER_STATE' => 'لا تُسجَّل ساعات على أمر إنتاج مخطط أو ملغى.',
        'RROKA_LABOR_DAY_OVER_24H' => 'مجموع ساعات العامل في اليوم يتجاوز ٢٤ ساعة.',
        'RROKA_AUDIT_IMMUTABLE' => 'سجل التدقيق لا يُعدَّل.',
        'DAFTRA_ALREADY_SYNCED' => 'سبق إرسال هذا السجل إلى دفترة.',
        'DAFTRA_SYNC_IN_PROGRESS' => 'توجد محاولة إرسال سابقة لم تُحسم؛ راجع سجل المزامنة.',
        'DAFTRA_SYNC_FAILED' => 'فشل الإرسال إلى دفترة، وسُجّل الخطأ في سجل المزامنة.',
        'DAFTRA_ESTIMATE_MAPPING_NOT_VERIFIED' => 'إرسال عروض الأسعار إلى دفترة موقوف حتى التحقق من حقول واجهة دفترة.',
        'DAFTRA_QUOTATION_NOT_ISSUED' => 'لا يُرسل إلى دفترة إلا عرض سعر مُرسَل أو معتمد.',
        'DAFTRA_CLIENT_NOT_SYNCED' => 'أرسل العميل إلى دفترة أولًا.',
        'CHECK_VIOLATION' => 'البيانات المُدخلة تخالف أحد شروط الصحة.',
        'DUPLICATE' => 'هذا السجل موجود مسبقًا.',
        'INVALID_REFERENCE' => 'مرجع غير صالح.',
        'BUSINESS_RULE' => 'العملية مرفوضة وفق القواعد الرقابية.',
    ],

    'status' => [
        'DRAFT' => 'مسودة', 'SENT' => 'مُرسَل', 'APPROVED' => 'معتمد', 'REJECTED' => 'مرفوض',
        'EXPIRED' => 'منتهي', 'CANCELLED' => 'ملغى', 'ACTIVE' => 'نشط', 'IN_PRODUCTION' => 'قيد الإنتاج',
        'INSTALLATION' => 'قيد التركيب', 'COMPLETED' => 'مكتمل', 'ON_HOLD' => 'متوقف',
        'PENDING' => 'قيد التنفيذ', 'SUCCESS' => 'نجح', 'FAILED' => 'فشل',
    ],

    'client_type' => ['INDIVIDUAL' => 'فرد', 'COMPANY' => 'منشأة'],

    'overhead_basis' => [
        'PCT_OF_DIRECT_LABOR' => 'نسبة من تكلفة العمالة المباشرة',
        'PCT_OF_PRIME_COST' => 'نسبة من التكلفة الأولية (خامات + عمالة + آلات)',
    ],

    'gaps' => [
        'MATERIAL_COST_UNKNOWN' => 'تكلفة بعض الخامات المصروفة غير معروفة',
        'WORKER_RATE_MISSING' => 'أجر ساعة عامل أو أكثر غير مُدخل',
        'MACHINE_RATE_MISSING' => 'تكلفة ساعة آلة أو أكثر غير مُدخلة',
        'OVERHEAD_RATE_MISSING' => 'نسبة المصروفات غير المباشرة غير مُدخلة',
    ],
];
