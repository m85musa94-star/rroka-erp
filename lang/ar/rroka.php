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
        'RROKA_PROJECT_TRANSITION' => 'هذا الانتقال بين مراحل المشروع غير مسموح.',
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
        'RROKA_STUDIO_FILE_IMMUTABLE' => 'ملف الصورة لا يُستبدل؛ ارفع صورة جديدة بدلًا منه.',
        'RROKA_STUDIO_CLIENT_MISMATCH' => 'المشروع المختار يتبع عميلًا آخر.',
        'RROKA_STUDIO_PRIVATE_ASSET' => 'هذه الصورة خاصة بعميل آخر، ولا تظهر إلا في عروض أسعاره.',
        'RROKA_STUDIO_ASSET_IN_USE' => 'الصورة مستخدمة في عرض سعر، فلا تُحذف ولا تُنقل عن عميلها.',
        'RROKA_DESIGN_TRANSITION' => 'هذا الانتقال في مراحل نسخة التصميم غير مسموح (المسار: إعداد ← مراجعة العميل ← موافقة ← إصدار).',
        'RROKA_PRODUCTION_TRANSITION' => 'هذا الانتقال في مراحل أمر التصنيع غير مسموح (مخطط ← قيد التنفيذ ← مكتمل).',
        'RROKA_EMPLOYEE_IMMUTABLE' => 'رقم الموظف لا يتغير.',
        'RROKA_EMPLOYEE_TERMINATION' => 'إنهاء الخدمة يتطلب تاريخ آخر يوم عمل وسببه.',
        'RROKA_EMPLOYEE_MANAGER_LOOP' => 'لا يصح هذا المدير المباشر: يصنع حلقة في التسلسل الإداري.',
        'RROKA_EMPLOYEE_NOT_EMPLOYED' => 'التاريخ خارج فترة عمل الموظف (قبل تعيينه أو بعد انتهاء خدمته).',
        'CHECK_VIOLATION' => 'البيانات المُدخلة تخالف أحد شروط الصحة.',
        'DUPLICATE' => 'هذا السجل موجود مسبقًا.',
        'INVALID_REFERENCE' => 'مرجع غير صالح.',
        'BUSINESS_RULE' => 'العملية مرفوضة وفق القواعد الرقابية.',
    ],

    'status' => [
        'CLIENT_REVIEW' => 'مراجعة العميل', 'CLIENT_APPROVED' => 'وافق العميل', 'RELEASED_FOR_PRODUCTION' => 'مُصدَر للإنتاج', 'SUPERSEDED' => 'مُستبدَل', 'PLANNED' => 'مخطط', 'IN_PROGRESS' => 'قيد التنفيذ',
        'DRAFT' => 'مسودة', 'SENT' => 'مُرسَل', 'APPROVED' => 'معتمد', 'REJECTED' => 'مرفوض',
        'EXPIRED' => 'منتهي', 'CANCELLED' => 'ملغى', 'ACTIVE' => 'نشط', 'IN_PRODUCTION' => 'قيد الإنتاج',
        'INSTALLATION' => 'قيد التركيب', 'COMPLETED' => 'مكتمل', 'ON_HOLD' => 'متوقف',
        'PENDING' => 'قيد التنفيذ', 'SUCCESS' => 'نجح', 'FAILED' => 'فشل',
    ],

    'permission_groups' => [
        'clients' => 'العملاء', 'surveys' => 'المعاينات', 'quotations' => 'عروض الأسعار',
        'projects' => 'المشاريع', 'designs' => 'التصاميم', 'bom' => 'قوائم المواد',
        'inventory' => 'المخزون', 'production' => 'الإنتاج', 'quality' => 'الجودة',
        'installations' => 'التركيب', 'costing' => 'التكلفة والربحية', 'settings' => 'الإعدادات',
        'daftra' => 'دفترة', 'hr' => 'الموارد البشرية', 'studio' => 'الاستوديو', 'users' => 'المستخدمون والأدوار', 'audit' => 'سجل التدقيق',
    ],

    'entities' => [
        'workers' => 'الموظف', 'departments' => 'القسم', 'job_positions' => 'المسمى الوظيفي', 'employee_documents' => 'الوثيقة',
        'raw_materials' => 'الخامة', 'designs' => 'التصميم', 'design_versions' => 'نسخة التصميم', 'design_bom_lines' => 'بند قائمة المواد', 'production_orders' => 'أمر التصنيع',
        'clients' => 'العميل', 'quotations' => 'عرض السعر', 'quotation_lines' => 'بند', 'projects' => 'المشروع',
        'studio_assets' => 'الصورة', 'users' => 'المستخدم', 'roles' => 'الدور',
    ],

    'fields' => [
        'department_id' => 'القسم', 'job_id' => 'المسمى الوظيفي', 'trade' => 'المهنة', 'work_phone' => 'هاتف العمل', 'work_email' => 'بريد العمل', 'mobile' => 'الجوال الشخصي', 'nationality' => 'الجنسية', 'id_type' => 'نوع الهوية', 'id_number' => 'رقم الهوية', 'birth_date' => 'تاريخ الميلاد', 'gender' => 'الجنس', 'hire_date' => 'تاريخ التعيين', 'employment_type' => 'نوع التوظيف', 'is_direct_labor' => 'عمالة مباشرة', 'termination_date' => 'انتهاء الخدمة', 'termination_reason' => 'سبب انتهاء الخدمة', 'iban' => 'الآيبان', 'emergency_contact' => 'جهة الطوارئ', 'emergency_phone' => 'هاتف الطوارئ', 'doc_type' => 'نوع الوثيقة', 'doc_number' => 'رقم الوثيقة', 'issue_date' => 'تاريخ الإصدار', 'expiry_date' => 'تاريخ الانتهاء', 'parent_id' => 'يتبع', 'user_id' => 'حساب المستخدم',
        'code' => 'الرمز', 'name' => 'الاسم', 'uom' => 'الوحدة', 'is_active' => 'نشط', 'file_url' => 'ملف التصميم', 'change_notes' => 'ملاحظات النسخة', 'planned_start' => 'البدء المخطط', 'planned_end' => 'الانتهاء المخطط', 'started_at' => 'بدأ', 'client_approved_at' => 'موافقة العميل', 'released_at' => 'تاريخ الإصدار للإنتاج', 'released_by' => 'أصدرها', 'waste_pct' => 'نسبة الهالك', 'material_id' => 'الخامة',
        'category' => 'التصنيف', 'tags' => 'الوسوم', 'project_id' => 'المشروع', 'studio_asset_id' => 'الصورة',
        'status' => 'الحالة', 'business_name' => 'الاسم', 'client_type' => 'النوع', 'phone' => 'الجوال',
        'email' => 'البريد', 'vat_number' => 'الرقم الضريبي', 'commercial_reg_no' => 'السجل التجاري',
        'city' => 'المدينة', 'address' => 'العنوان', 'notes' => 'ملاحظات', 'daftra_client_id' => 'رقم العميل في دفترة',
        'daftra_client_number' => 'رقم العميل في دفترة', 'daftra_estimate_id' => 'رقم العرض في دفترة',
        'issue_date' => 'تاريخ الإصدار', 'valid_until' => 'صالح حتى', 'discount_amount' => 'الخصم',
        'approved_at' => 'تاريخ الاعتماد', 'approved_by' => 'المعتمِد', 'client_id' => 'العميل',
        'title' => 'العنوان', 'start_date' => 'تاريخ البدء', 'target_date' => 'التسليم المستهدف',
        'completed_at' => 'تاريخ الإكمال', 'manager_id' => 'مدير المشروع', 'contract_value' => 'قيمة العقد',
        'description' => 'الوصف', 'quantity' => 'الكمية', 'unit_price' => 'سعر الوحدة', 'unit' => 'الوحدة',
    ],

    'studio_category' => ['CLIENT_REFERENCE' => 'صور من العملاء', 'FINISHED_WORK' => 'أعمال منجزة', 'CATALOG' => 'كتالوج المنتجات', 'SITE' => 'صور المواقع', 'MATERIAL' => 'خامات وعينات'],

    'movement_type' => ['RECEIPT' => 'استلام', 'RESERVE' => 'حجز للمشروع', 'UNRESERVE' => 'فك حجز', 'ISSUE' => 'صرف للإنتاج', 'RETURN' => 'إرجاع للمخزن', 'ADJUST_IN' => 'تسوية بالزيادة', 'ADJUST_OUT' => 'تسوية بالنقص'],

    'qc_stage' => ['IN_PROCESS' => 'أثناء التصنيع', 'FINAL' => 'فحص نهائي', 'PRE_DELIVERY' => 'قبل التسليم', 'POST_INSTALLATION' => 'بعد التركيب'],

    'qc_result' => ['PASS' => 'ناجح', 'FAIL' => 'فاشل', 'REWORK' => 'يحتاج إعادة عمل'],

    'doc_type' => ['NATIONAL_ID' => 'هوية وطنية', 'IQAMA' => 'إقامة', 'PASSPORT' => 'جواز سفر', 'WORK_PERMIT' => 'رخصة عمل', 'HEALTH_CERT' => 'شهادة صحية', 'DRIVING_LICENSE' => 'رخصة قيادة', 'OTHER' => 'أخرى'],

    'employment_type' => ['FULL_TIME' => 'دوام كامل', 'PART_TIME' => 'دوام جزئي', 'CONTRACTOR' => 'متعاقد مستقل'],

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
