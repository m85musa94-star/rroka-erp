<?php

return [
    'required' => 'حقل :attribute مطلوب.',
    'string' => 'حقل :attribute يجب أن يكون نصًا.',
    'email' => 'حقل :attribute يجب أن يكون بريدًا إلكترونيًا صحيحًا.',
    'numeric' => 'حقل :attribute يجب أن يكون رقمًا.',
    'integer' => 'حقل :attribute يجب أن يكون عددًا صحيحًا.',
    'date' => 'حقل :attribute يجب أن يكون تاريخًا صحيحًا.',
    'array' => 'حقل :attribute غير صالح.',
    'boolean' => 'حقل :attribute غير صالح.',
    'in' => 'قيمة :attribute غير صالحة.',
    'exists' => 'قيمة :attribute غير موجودة.',
    'unique' => 'قيمة :attribute مستخدمة مسبقًا.',
    'regex' => 'صيغة :attribute غير صحيحة.',
    'confirmed' => 'تأكيد :attribute غير مطابق.',
    'gt' => ['numeric' => 'حقل :attribute يجب أن يكون أكبر من :value.'],
    'min' => [
        'numeric' => 'حقل :attribute يجب ألا يقل عن :min.',
        'string' => 'حقل :attribute يجب ألا يقل عن :min حروف.',
        'array' => 'يجب إدخال :min عنصر على الأقل في :attribute.',
    ],
    'max' => [
        'numeric' => 'حقل :attribute يجب ألا يزيد على :max.',
        'string' => 'حقل :attribute يجب ألا يزيد على :max حرفًا.',
    ],
    'after_or_equal' => 'حقل :attribute يجب أن يكون في :date أو بعده.',

    'attributes' => [
        'business_name' => 'اسم العميل', 'phone' => 'الجوال', 'email' => 'البريد الإلكتروني',
        'vat_number' => 'الرقم الضريبي', 'password' => 'كلمة المرور', 'name' => 'الاسم',
        'client_id' => 'العميل', 'lines' => 'البنود', 'lines.*.description' => 'وصف البند',
        'lines.*.quantity' => 'الكمية', 'lines.*.unit_price' => 'سعر الوحدة', 'discount_amount' => 'الخصم',
        'quotation_id' => 'عرض السعر', 'title' => 'العنوان', 'hourly_cost' => 'تكلفة الساعة',
        'effective_from' => 'تاريخ السريان', 'basis_note' => 'أساس الاحتساب', 'rate_pct' => 'النسبة',
        'basis' => 'أساس التحميل', 'code' => 'الرمز', 'name_ar' => 'الاسم', 'valid_until' => 'صالح حتى',
    ],
];
