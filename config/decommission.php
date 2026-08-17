<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Batch-number prefixes
    |--------------------------------------------------------------------------
    | e_waste → EWA-YYYY-QN. Consumed by AssetDecommissionBatch::generateBatchNumber().
    |
    | There is no vendor_return prefix any more: a rental return is an Asset Acceptance &
    | Return Form, not a disposal batch, and is numbered RTA-YYYY-NNNN from
    | config('vendors.aarf_prefixes'). Anything numbered here archives assets out of
    | inventory as WASTE; anything numbered there hands them back to their owner.
    */
    'batch_prefixes' => [
        'e_waste' => 'EWA',
    ],

    /*
    |--------------------------------------------------------------------------
    | Organisation identity (shown on RFQ/report emails + PDFs)
    |--------------------------------------------------------------------------
    */
    'org_name' => env('DECOMMISSION_ORG_NAME', 'Claritas Asia Sdn. Bhd.'),
    'org_email' => env('DECOMMISSION_ORG_EMAIL', env('MAIL_FROM_ADDRESS', 'hr@claritas.com')),

    /*
    |--------------------------------------------------------------------------
    | Quarterly e-waste sweep
    |--------------------------------------------------------------------------
    | The command runs daily and self-gates. `sweep_day` is the day-of-month within
    | each quarter-start month (Jan/Apr/Jul/Oct) on which the sweep fires. `--force`
    | bypasses the gate for testing / manual "Run sweep now".
    */
    'sweep_day' => (int) env('DECOMMISSION_SWEEP_DAY', 1),

    /*
    |--------------------------------------------------------------------------
    | Private storage directories (role-gated via SecureFileController)
    |--------------------------------------------------------------------------
    | Registered in secure_file_url() $sensitiveDirectories and
    | SecureFileController::DIRECTORY_PERMISSIONS.
    */
    'sensitive_directories' => [
        'ewaste_quotations',
        'ewaste_receipts',
        'decommission_reports',
    ],

    /*
    |--------------------------------------------------------------------------
    | Email copy
    |--------------------------------------------------------------------------
    */
    'copy' => [
        'rfq_subject' => 'Request for Quotation — IT Asset E-Waste Disposal',
        'rfq_intro' => 'We have the following IT assets awaiting e-waste disposal and invite your quotation for their collection and recycling.',
        'awaiting_subject' => 'E-Waste Cycle — Assets Awaiting Decommissioning',
        'approval_subject' => 'Action Required — E-Waste Quotation Awaiting Finance Approval',
        'final_subject' => 'E-Waste Cycle Completed — Final Report',
    ],
];
