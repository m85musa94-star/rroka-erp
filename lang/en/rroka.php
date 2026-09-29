<?php

return [
    // Business-rule refusals raised by the database (RROKA_*) or the services.
    'errors' => [
        'RROKA_QUOTATION_LOCKED' => 'A quotation cannot be edited after it is sent or closed. Return it to draft first if it was sent.',
        'RROKA_QUOTATION_TRANSITION' => 'This quotation status change is not allowed.',
        'RROKA_QUOTATION_EMPTY' => 'A quotation without lines cannot be sent.',
        'RROKA_QUOTATION_NEGATIVE' => 'The discount is larger than the lines total.',
        'RROKA_PROJECT_NEEDS_APPROVED_QUOTATION' => 'A project can only be created from an approved quotation.',
        'RROKA_PROJECT_CLIENT_MISMATCH' => 'The project customer does not match the quotation customer.',
        'RROKA_PROJECT_TRANSITION' => 'This project stage change is not allowed.',
        'RROKA_PROJECT_IMMUTABLE' => 'The customer, quotation and contract value are fixed once the project is created.',
        'RROKA_DESIGN_RELEASE_NEEDS_CLIENT_APPROVAL' => 'A design version cannot be released to production before the customer approves it.',
        'RROKA_DESIGN_VERSION_CLOSED' => 'The design version is closed and cannot be reopened.',
        'RROKA_DESIGN_VERSION_IMMUTABLE' => 'A design version cannot be moved to another design.',
        'RROKA_BOM_LOCKED' => 'The bill of materials of a released version is frozen; create a new version.',
        'RROKA_PRODUCTION_NEEDS_RELEASED_DESIGN' => 'A production order can only be created on a design version released to production.',
        'RROKA_PRODUCTION_DESIGN_PROJECT_MISMATCH' => 'The design version does not belong to this project.',
        'RROKA_PRODUCTION_PROJECT_NOT_ACTIVE' => 'The project is not active.',
        'RROKA_PRODUCTION_ORDER_IMMUTABLE' => 'The project or design of a production order cannot be changed.',
        'RROKA_PRODUCTION_ORDER_CLOSED' => 'The production order is closed.',
        'RROKA_PRODUCTION_NEEDS_FINAL_QC' => 'A production order cannot be closed before a passed final quality inspection.',
        'RROKA_STOCK_INSUFFICIENT' => 'Not enough quantity in stock.',
        'RROKA_STOCK_UNRESERVE_EXCEEDS' => 'The quantity exceeds what is reserved for this project.',
        'RROKA_STOCK_ISSUE_EXCEEDS_RESERVATION' => 'The quantity exceeds what is reserved for this project.',
        'RROKA_STOCK_RETURN_EXCEEDS_ISSUED' => 'The returned quantity exceeds what was issued to the project.',
        'RROKA_STOCK_PROJECT_MISMATCH' => 'The production order belongs to another project.',
        'RROKA_MOVEMENT_IMMUTABLE' => 'Stock movements cannot be edited; record a reversing movement.',
        'RROKA_DERIVED_TABLE' => 'Balances are computed from movements and cannot be edited directly.',
        'RROKA_RATE_HISTORY' => 'Rates cannot be edited or deleted; add a new rate with a new effective date.',
        'RROKA_TIME_LOG_ORDER_STATE' => 'Hours cannot be logged on a planned or cancelled production order.',
        'RROKA_LABOR_DAY_OVER_24H' => 'The worker\'s total hours for the day exceed 24.',
        'RROKA_AUDIT_IMMUTABLE' => 'The audit log cannot be modified.',
        'DAFTRA_ALREADY_SYNCED' => 'This record has already been sent to Daftra.',
        'DAFTRA_SYNC_IN_PROGRESS' => 'A previous send attempt is unresolved; check the sync log.',
        'DAFTRA_SYNC_FAILED' => 'Sending to Daftra failed; the error was recorded in the sync log.',
        'DAFTRA_ESTIMATE_MAPPING_NOT_VERIFIED' => 'Sending quotations to Daftra is disabled until the Daftra API fields are verified.',
        'DAFTRA_QUOTATION_NOT_ISSUED' => 'Only a sent or approved quotation can be sent to Daftra.',
        'DAFTRA_CLIENT_NOT_SYNCED' => 'Send the customer to Daftra first.',
        'RROKA_STUDIO_FILE_IMMUTABLE' => 'An image file cannot be replaced; upload a new image instead.',
        'RROKA_STUDIO_CLIENT_MISMATCH' => 'The selected project belongs to another customer.',
        'RROKA_STUDIO_PRIVATE_ASSET' => "This image belongs to another customer and can only appear in that customer's quotations.",
        'RROKA_STUDIO_ASSET_IN_USE' => 'The image is used in a quotation, so it cannot be deleted or moved away from its customer.',
        'RROKA_DESIGN_TRANSITION' => 'This design version stage change is not allowed (draft → client review → approved → released).',
        'RROKA_PRODUCTION_TRANSITION' => 'This manufacturing order stage change is not allowed (planned → in progress → done).',
        'CHECK_VIOLATION' => 'The entered data breaks one of the validity rules.',
        'DUPLICATE' => 'This record already exists.',
        'INVALID_REFERENCE' => 'Invalid reference.',
        'BUSINESS_RULE' => 'The operation was refused by the control rules.',
    ],

    'status' => [
        'CLIENT_REVIEW' => 'Client review', 'CLIENT_APPROVED' => 'Client approved', 'RELEASED_FOR_PRODUCTION' => 'Released', 'SUPERSEDED' => 'Superseded', 'PLANNED' => 'Planned', 'IN_PROGRESS' => 'In progress',
        'DRAFT' => 'Draft', 'SENT' => 'Sent', 'APPROVED' => 'Approved', 'REJECTED' => 'Rejected',
        'EXPIRED' => 'Expired', 'CANCELLED' => 'Cancelled', 'ACTIVE' => 'Active', 'IN_PRODUCTION' => 'In production',
        'INSTALLATION' => 'Installation', 'COMPLETED' => 'Completed', 'ON_HOLD' => 'On hold',
        'PENDING' => 'Pending', 'SUCCESS' => 'Succeeded', 'FAILED' => 'Failed',
    ],

    'permission_groups' => [
        'clients' => 'Customers', 'surveys' => 'Site surveys', 'quotations' => 'Quotations',
        'projects' => 'Projects', 'designs' => 'Designs', 'bom' => 'Bills of materials',
        'inventory' => 'Inventory', 'production' => 'Production', 'quality' => 'Quality',
        'installations' => 'Installation', 'costing' => 'Costing & profitability', 'settings' => 'Settings',
        'daftra' => 'Daftra', 'studio' => 'Studio', 'users' => 'Users & roles', 'audit' => 'Audit log',
    ],

    'entities' => [
        'raw_materials' => 'material', 'designs' => 'design', 'design_versions' => 'design version', 'design_bom_lines' => 'BOM line', 'production_orders' => 'manufacturing order',
        'clients' => 'customer', 'quotations' => 'quotation', 'quotation_lines' => 'line', 'projects' => 'project',
        'studio_assets' => 'image', 'users' => 'user', 'roles' => 'role',
    ],

    'fields' => [
        'code' => 'Code', 'name' => 'Name', 'uom' => 'Unit', 'is_active' => 'Active', 'file_url' => 'Design file', 'change_notes' => 'Version notes', 'planned_start' => 'Planned start', 'planned_end' => 'Planned end', 'started_at' => 'Started', 'client_approved_at' => 'Client approval', 'released_at' => 'Released on', 'released_by' => 'Released by', 'waste_pct' => 'Waste %', 'material_id' => 'Material',
        'category' => 'Category', 'tags' => 'Tags', 'project_id' => 'Project', 'studio_asset_id' => 'Image',
        'status' => 'Status', 'business_name' => 'Name', 'client_type' => 'Type', 'phone' => 'Mobile',
        'email' => 'Email', 'vat_number' => 'VAT number', 'commercial_reg_no' => 'Commercial registration',
        'city' => 'City', 'address' => 'Address', 'notes' => 'Notes', 'daftra_client_id' => 'Daftra customer ID',
        'daftra_client_number' => 'Daftra customer number', 'daftra_estimate_id' => 'Daftra estimate ID',
        'issue_date' => 'Issue date', 'valid_until' => 'Valid until', 'discount_amount' => 'Discount',
        'approved_at' => 'Approved at', 'approved_by' => 'Approved by', 'client_id' => 'Customer',
        'title' => 'Title', 'start_date' => 'Start date', 'target_date' => 'Target delivery',
        'completed_at' => 'Completed at', 'manager_id' => 'Project manager', 'contract_value' => 'Contract value',
        'description' => 'Description', 'quantity' => 'Quantity', 'unit_price' => 'Unit price', 'unit' => 'Unit',
    ],

    'studio_category' => ['CLIENT_REFERENCE' => 'From customers', 'FINISHED_WORK' => 'Finished work', 'CATALOG' => 'Product catalogue', 'SITE' => 'Site photos', 'MATERIAL' => 'Materials & samples'],

    'movement_type' => ['RECEIPT' => 'Receipt', 'RESERVE' => 'Reserve for project', 'UNRESERVE' => 'Unreserve', 'ISSUE' => 'Issue to production', 'RETURN' => 'Return to store', 'ADJUST_IN' => 'Adjustment in', 'ADJUST_OUT' => 'Adjustment out'],

    'qc_stage' => ['IN_PROCESS' => 'In process', 'FINAL' => 'Final', 'PRE_DELIVERY' => 'Pre-delivery', 'POST_INSTALLATION' => 'Post-installation'],

    'qc_result' => ['PASS' => 'Pass', 'FAIL' => 'Fail', 'REWORK' => 'Rework'],

    'client_type' => ['INDIVIDUAL' => 'Individual', 'COMPANY' => 'Company'],

    'overhead_basis' => [
        'PCT_OF_DIRECT_LABOR' => 'Percentage of direct labour cost',
        'PCT_OF_PRIME_COST' => 'Percentage of prime cost (materials + labour + machines)',
    ],

    'gaps' => [
        'MATERIAL_COST_UNKNOWN' => 'The cost of some issued materials is unknown',
        'WORKER_RATE_MISSING' => 'One or more worker hourly rates are not entered',
        'MACHINE_RATE_MISSING' => 'One or more machine hourly costs are not entered',
        'OVERHEAD_RATE_MISSING' => 'The overhead rate is not entered',
    ],
];
