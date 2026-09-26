<?php

return [
    // Verified: https://{subdomain}.daftra.com/api2, headers `apikey` + `Authorization: Bearer`.
    'subdomain' => env('DAFTRA_SUBDOMAIN'),
    'api_key' => env('DAFTRA_API_KEY'),
    'token' => env('DAFTRA_TOKEN'),
    'timeout' => (int) env('DAFTRA_TIMEOUT', 20),

    // The Estimate/InvoiceItem inner field names have NOT been verified against
    // docs.daftara.dev yet. Estimate sync stays blocked until someone checks the
    // docs, confirms DaftraSyncService::estimatePayload(), and sets this to true.
    'estimate_mapping_verified' => (bool) env('DAFTRA_ESTIMATE_MAPPING_VERIFIED', false),
];
