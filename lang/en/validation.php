<?php

return [
    'required' => 'The :attribute field is required.',
    'string' => 'The :attribute field must be text.',
    'email' => 'The :attribute field must be a valid email address.',
    'numeric' => 'The :attribute field must be a number.',
    'integer' => 'The :attribute field must be a whole number.',
    'date' => 'The :attribute field must be a valid date.',
    'array' => 'The :attribute field is invalid.',
    'boolean' => 'The :attribute field is invalid.',
    'in' => 'The selected :attribute is invalid.',
    'exists' => 'The selected :attribute does not exist.',
    'unique' => 'The :attribute is already in use.',
    'regex' => 'The :attribute format is invalid.',
    'confirmed' => 'The :attribute confirmation does not match.',
    'gt' => ['numeric' => 'The :attribute field must be greater than :value.'],
    'min' => [
        'numeric' => 'The :attribute field must be at least :min.',
        'string' => 'The :attribute field must be at least :min characters.',
        'array' => 'Enter at least :min item(s) in :attribute.',
    ],
    'max' => [
        'numeric' => 'The :attribute field must not be greater than :max.',
        'string' => 'The :attribute field must not be longer than :max characters.',
    ],
    'after_or_equal' => 'The :attribute field must be on or after :date.',

    'attributes' => [
        'business_name' => 'customer name', 'phone' => 'mobile', 'email' => 'email',
        'vat_number' => 'VAT number', 'password' => 'password', 'name' => 'name',
        'client_id' => 'customer', 'lines' => 'lines', 'lines.*.description' => 'line description',
        'lines.*.quantity' => 'quantity', 'lines.*.unit_price' => 'unit price', 'discount_amount' => 'discount',
        'quotation_id' => 'quotation', 'title' => 'title', 'hourly_cost' => 'hourly cost',
        'effective_from' => 'effective date', 'basis_note' => 'calculation basis', 'rate_pct' => 'rate',
        'basis' => 'allocation basis', 'code' => 'code', 'name_ar' => 'name', 'valid_until' => 'valid until',
    ],
];
