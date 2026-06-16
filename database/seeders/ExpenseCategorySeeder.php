<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ExpenseCategorySeeder extends Seeder
{
    public function run(): void
    {
        // The GL-coded list below is the authoritative claim-category set.
        // Deactivate everything first so older placeholder categories drop out
        // of the dropdown without breaking historical claim-item references.
        DB::table('expense_categories')->update(['is_active' => false]);

        // ── Categories = the company's SQL Account GL expense accounts ──────────
        // code == gl_code (the account code is the natural unique key). Names are
        // tidied to Title Case for display; the underlying account is unchanged.
        $glCategories = [
            ['200-200', 'Computer & Software'],
            ['200-300', 'Office Equipment'],
            ['340-000', 'Deposit & Prepayment'],
            ['901-000', 'Advertisement'],
            ['902-000', 'Bank Charges'],
            ['903-000', 'Upkeep of Office & Warehouse'],
            ['905-000', 'Travelling Expenses (Local)'],
            ['906-000', 'Travelling Expenses (Oversea)'],
            ['907-000', 'Water & Electricity'],
            ['910-000', 'Telephone & Fax Charges'],
            ['911-000', 'Accounting & Administration Fees'],
            ['912-000', 'Secretarial Fees'],
            ['913-000', 'Sales Commission'],
            ['914(b)-000', 'Transportation'],
            ['915-000', 'Office & Warehouse Rental'],
            ['916-000', 'Toll, Parking & Fined'],
            ['917-000', 'Insurance'],
            ['919-000', 'Petrol'],
            ['920-000', 'Printing & Stationery'],
            ['921-000', 'Consultancy Fees'],
            ['922-000', 'Office Food & Refreshment'],
            ['924-000', 'Staff Recruitment Expenses'],
            ['926-000', 'Penalties'],
            ['927-000', 'Computer Peripherals'],
            ['928-000', 'Website Expenses'],
            ['929-000', 'Subscription Fees'],
            ['930-000', 'Staff Entertainment'],
            ['931-000', 'Entertainment'],
            ['932-000', 'Medical Fees'],
            ['933-000', 'Newspaper & Periodicals'],
            ['934-000', 'Postage & Courier'],
            ['935-000', 'Service Tax'],
            ['936-000', 'Seminar & Training'],
        ];

        $sort = 1;
        foreach ($glCategories as [$code, $name]) {
            DB::table('expense_categories')->updateOrInsert(
                ['code' => $code],
                [
                    'gl_code' => $code,
                    'name' => $name,
                    'company' => null,
                    'description' => null,
                    'keywords' => null,
                    'monthly_limit' => null,
                    'rate_type' => 'receipt',
                    'rate_amount' => null,
                    'limit_period' => 'monthly',
                    'applies_to_role' => null,
                    'requires_receipt' => true,
                    'is_active' => true,
                    'sort_order' => $sort++,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        // ── Extra Hours (not in the GL list; paid in bands, no receipt) ─────────
        DB::table('expense_categories')->updateOrInsert(
            ['code' => 'EXTRA_HOURS'],
            [
                'gl_code' => null,
                'name' => 'Extra Hours',
                'company' => null,
                'description' => 'Extra working hours, paid in bands: 4 hours = RM50, 8 hours = RM100. Specify the hours worked. Can be taken as cash or replacement leave.',
                'keywords' => json_encode(['extra hours', 'extra hour', 'extended hours', 'after hours', 'ot', 'overtime']),
                'monthly_limit' => null,
                'rate_type' => 'per_hour',
                'rate_amount' => null,
                'limit_period' => 'monthly',
                'applies_to_role' => null,
                'requires_receipt' => false,
                'is_active' => true,
                'sort_order' => 50,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        // ── Event / Programme day (RM150/day, all entities, no receipt) ─────────
        DB::table('expense_categories')->updateOrInsert(
            ['code' => 'EVENT_DAY'],
            [
                'gl_code' => null,
                'name' => 'Event / Programme Day',
                'company' => null,
                'description' => 'Full-day events (Community/ClubMama) and Parentcraft/Superkid programmes at RM150 per day. Enter the number of days.',
                'keywords' => json_encode(['event', 'clubmama', 'club mama', 'parentcraft', 'superkid', 'super kid', 'programme', 'program']),
                'monthly_limit' => null,
                'rate_type' => 'per_day',
                'rate_amount' => 150.00,
                'limit_period' => 'monthly',
                'applies_to_role' => null,
                'requires_receipt' => false,
                'is_active' => true,
                'sort_order' => 51,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        // ── Claritas-only allowances (entity-scoped) ────────────────────────────
        DB::table('expense_categories')->updateOrInsert(
            ['code' => 'CLARITAS_PHONE_PETROL'],
            [
                'gl_code' => null,
                'name' => 'Phone & Petrol Allowance',
                'company' => 'Claritas',
                'description' => 'Claritas monthly allowance covering phone and petrol for eligible staff, capped at RM500/month.',
                'keywords' => json_encode(['phone allowance', 'petrol allowance', 'allowance']),
                'monthly_limit' => 500.00,
                'rate_type' => 'receipt',
                'rate_amount' => null,
                'limit_period' => 'monthly',
                'applies_to_role' => null,
                'requires_receipt' => true,
                'is_active' => true,
                'sort_order' => 52,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
        DB::table('expense_categories')->updateOrInsert(
            ['code' => 'CLARITAS_OPTICAL_DENTAL'],
            [
                'gl_code' => null,
                'name' => 'Optical & Dental',
                'company' => 'Claritas',
                'description' => 'Claritas optical and dental benefit, capped at RM500 per calendar year.',
                'keywords' => json_encode(['optical', 'dental', 'glasses', 'spectacles', 'teeth', 'eye']),
                'monthly_limit' => 500.00,
                'rate_type' => 'receipt',
                'rate_amount' => null,
                'limit_period' => 'annual',
                'applies_to_role' => null,
                'requires_receipt' => true,
                'is_active' => true,
                'sort_order' => 53,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        // Default policy (unchanged)
        DB::table('expense_claim_policies')->updateOrInsert(
            ['company' => null],
            [
                'submission_deadline_day' => 20,
                'require_manager_approval' => true,
                'require_hr_approval' => true,
                'auto_approve_below' => 0,
                'reminder_days_before' => 3,
                'gst_enabled' => true,
                'gst_rate' => 8.00,
                'general_rules' => "All claims must be submitted by the 20th of each month with reporting manager's approval.\nIf the 20th falls on a weekend or public holiday, the deadline moves to the preceding working day.\nNo receipt, no claim — all bills and receipts must be attached.\nClaims must be itemised individually; do not lump multiple claims together.\nUse the correct entity-specific claim form (wrong form = rejection).\nFor Extra Hours, clearly specify the number of hours worked.\nAdmin reserves the right to refuse incomplete or non-compliant claims.",
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }
}
