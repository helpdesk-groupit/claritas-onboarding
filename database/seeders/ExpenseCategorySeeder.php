<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
            // NB: 914(b)-000 (Transportation) is intentionally NOT in this receipt-based loop.
            // It is the Extra Hours category — created below as a per_hour, no-receipt category.
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

        // Detection keywords for the common GL categories (boosts auto-categorisation).
        // Only the categories people actually describe in free text need these; the rest
        // fall back to name-word matching in ExpenseCategory::detectFromDescription().
        $glKeywords = [
            // Computer BIG items + software (vs 200-300 furniture, vs 927 small peripherals).
            '200-200' => ['computer', 'software', 'laptop', 'license', 'licence', 'software license',
                'software licence', 'subscription software', 'server', 'desktop', 'monitor', 'google play',
                'play store', 'app store', 'apple', 'capcut', 'canva', 'adobe', 'figma', 'notion', 'microsoft',
                'office 365', 'chatgpt', 'openai', 'github', 'app', 'plugin'],
            '200-300' => ['office equipment', 'printer', 'furniture', 'desk', 'chair', 'cabinet', 'table'],
            '340-000' => ['deposit', 'prepayment', 'rental deposit', 'security deposit'],
            '901-000' => ['advertisement', 'advertising', 'ads', 'ad spend', 'facebook ad', 'google ad',
                'promotion', 'promo', 'promotional', 'event hosting', 'emcee', 'emceeing', 'master of ceremony'],
            '902-000' => ['bank charge', 'bank fee', 'transfer fee', 'service charge', 'cheque book', 'stamp duty'],
            '903-000' => ['upkeep', 'coway', 'cleaner', 'cleaning', 'aircon', 'aircond', 'air cond', 'air conditioning',
                'wiring', 'plumbing', 'pest control', 'renovation', 'duplicate key',
                'office maintenance', 'warehouse maintenance'],
            '905-000' => ['travel', 'travelling', 'flight', 'air ticket', 'taxi', 'grab', 'justgrab', 'grabcar',
                'grab car', 'e-hailing', 'ehailing', 'ride hailing', 'mycar', 'indrive', 'airasia ride', 'train',
                'hotel', 'accommodation', 'lodging', 'meal allowance'],
            '906-000' => ['overseas travel', 'oversea travel', 'international flight', 'airport tax'],
            '907-000' => ['water bill', 'electricity', 'electric bill', 'utility', 'utilities', 'tnb', 'tenaga',
                'kcp', 'indah water'],
            '910-000' => ['phone bill', 'telephone', 'mobile', 'fax', 'internet', 'data plan', 'prepaid', 'postpaid'],
            '911-000' => ['accounting fee', 'administration fee', 'admin fee', 'accounting', 'administration'],
            '912-000' => ['secretarial', 'secretarial fee'],
            '913-000' => ['sales commission', 'commission'],
            // 914(b)-000 keywords live on the Extra Hours category block below (per_hour, no receipt).
            '915-000' => ['office rental', 'warehouse rental', 'rental', 'tenancy'],
            '916-000' => ['toll', 'parking', 'car park', 'carpark', 'fine', 'smarttag', 'touch n go', 'tng'],
            '917-000' => ['insurance', 'premium', 'coverage'],
            '919-000' => ['petrol', 'fuel', 'diesel', 'mileage'],
            '920-000' => ['printing', 'stationery', 'stationary', 'paper', 'ink', 'toner', 'pen', 'out of pocket', 'carton', 'carton box', 'packaging', 'packing', 'bubble wrap', 'envelope'],
            '921-000' => ['consultancy', 'consultant', 'outsourced', 'professional fee'],
            '922-000' => ['food', 'refreshment', 'lunch', 'dinner', 'meal', 'drinks', 'coffee', 'catering',
                'pantry', 'ro water', 'mineral water', 'milk', 'groceries', 'grocery', 'provision', 'sundry',
                'biscuit', 'snack', 'bread', 'honey', 'madu', 'speed mart', 'speedmart', 'kk mart',
                'kk super mart', 'mynews', 'mini market', 'minimarket'],
            '924-000' => ['recruitment', 'hiring', 'job ad', 'interview', 'jobstreet', 'hiring consultant'],
            '926-000' => ['penalty', 'penalties', 'late charge', 'late payment'],
            // Computer SMALL items / peripherals (incl. keyboard & mouse, vs 200-200 big items).
            '927-000' => ['computer peripheral', 'cable', 'adapter', 'hard disk', 'usb', 'webcam', 'headset',
                'ram', 'cd', 'keyboard', 'mouse'],
            '928-000' => ['website', 'hosting', 'domain', 'ssl', 'data hosting'],
            '929-000' => ['subscription', 'membership', 'saas', 'renewal', 'sugarcrm', 'partnership subscription'],
            '930-000' => ['staff entertainment', 'team lunch', 'team dinner', 'staff makan', 'team building',
                'gathering', 'makan session', 'staff birthday'],
            '931-000' => ['entertainment', 'client lunch', 'client dinner', 'client makan', 'business lunch'],
            // Medical only — dental/optical/glasses belong to the Optical & Dental benefit
            // category, so they're intentionally NOT here (they'd otherwise steal dental/optical
            // receipts from Optical & Dental, which carries the stronger dental/optical signals).
            '932-000' => ['medical', 'clinic', 'klinik', 'doctor', 'hospital', 'pharmacy', 'medicine'],
            '933-000' => ['newspaper', 'periodical', 'magazine', 'book'],
            '934-000' => ['postage', 'courier', 'poslaju', 'stamp', 'mailing'],
            '936-000' => ['seminar', 'training', 'workshop', 'course', 'conference'],
        ];

        // Per-category descriptions (from the company chart of accounts) — stored on the
        // category AND fed to the OCR's category prompt so the AI classifies with context.
        $glDescriptions = [
            '200-200' => 'Computer big items: server, desktop, monitor, software licenses, etc.',
            '200-300' => 'Office furniture, etc.',
            '340-000' => 'Deposit (e.g. rental agreement / contract) and prepayments.',
            '901-000' => 'Marketing-related advertisement expenses, etc.',
            '902-000' => 'Misc bank charges such as cheque book, stamp duty, etc.',
            '903-000' => 'Upkeep of office & warehouse: Coway monthly, cleaner monthly, duplicate key, aircon repair, etc.',
            '905-000' => 'Local travel: taxi, air ticket, hotel, meal allowances, etc.',
            '906-000' => 'Oversea travel: taxi, air ticket, airport tax, hotel, meal allowances, etc.',
            '907-000' => 'Water & electricity: KCP (water), Tenaga, Indah Water, etc.',
            '910-000' => 'Telephone & fax: prepaid, postpaid subsidy, etc.',
            '911-000' => 'Office administration and accounting expenses, etc.',
            '912-000' => 'Office secretarial expenses, etc.',
            '913-000' => 'Sales commission, etc.',
            // 914(b)-000 description lives on the Extra Hours category block below.
            '915-000' => 'Office rentals, etc.',
            '916-000' => 'Toll claims, parking claims, fines, etc.',
            '917-000' => 'Office insurance claims, etc.',
            '919-000' => 'Petrol / mileage claims, etc.',
            '920-000' => 'Printing, stationeries, out-of-pocket expenses, etc.',
            '921-000' => 'Outsourced work / consultancy fees, etc.',
            '922-000' => 'Office food & refreshment: pantry items, RO water, etc.',
            '924-000' => 'Staff recruitment: JobStreet fees, hiring consultant fees, etc.',
            '926-000' => 'Misc penalties.',
            '927-000' => 'Computer small items: hard-disk, RAM, CD, cables, etc.',
            '928-000' => 'Website / data hosting package, etc.',
            '929-000' => 'Partnership subscriptions such as Microsoft, SugarCRM, etc.',
            '930-000' => 'Staff-related events: team building / gathering, birthday, makan session, etc.',
            '931-000' => 'Customer-related events: business lunch, entertainment, etc.',
            '932-000' => 'Medical claims, etc.',
            '933-000' => 'Newspaper subscription, book purchase, etc.',
            '934-000' => 'Courier fees, stamps, postage, etc.',
            '935-000' => 'Misc taxes, etc.',
            '936-000' => 'Training and seminar claims, etc.',
        ];

        // Role-restricted GL categories. Medical Fees is intern/probationer-only: regular
        // staff claim medical through a separate process, while interns/probationers may
        // claim it here capped at RM100/month (the cap is applied in ClaimRulesService).
        $glAppliesToRole = [
            '932-000' => 'intern',
        ];

        $sort = 1;
        foreach ($glCategories as [$code, $name]) {
            DB::table('expense_categories')->updateOrInsert(
                ['code' => $code],
                [
                    'gl_code' => $code,
                    'name' => $name,
                    'company' => null,
                    'description' => $glDescriptions[$code] ?? null,
                    'keywords' => isset($glKeywords[$code]) ? json_encode($glKeywords[$code]) : null,
                    'monthly_limit' => null,
                    'rate_type' => 'receipt',
                    'rate_amount' => null,
                    'limit_period' => 'monthly',
                    'applies_to_role' => $glAppliesToRole[$code] ?? null,
                    'requires_receipt' => true,
                    'is_active' => true,
                    'sort_order' => $sort++,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        // ── 914(b)-000 Transportation = Extra Hours (paid in bands, no receipt) ──
        // Extra working hours are claimed under GL 914(b)-000 "Transportation" (Finance books
        // overtime there), and this GL line is used ONLY for extra hours — so the category is
        // per_hour + no-receipt and displays on the form as "914(b)-000: Transportation".
        // Keyed by `code` = '914(b)-000' (the same record the receipt-based Transportation used
        // to be — repurposed in place, so existing claim items keep their id/GL/name).
        DB::table('expense_categories')->updateOrInsert(
            ['code' => '914(b)-000'],
            [
                'gl_code' => '914(b)-000',
                'name' => 'Transportation',
                'company' => null,
                'description' => 'Extra working hours, paid in bands: 4 hours = RM50, 8 hours = RM100. Specify the hours worked. Can be taken as cash or replacement leave. No receipt required.',
                'keywords' => json_encode(['extra hours', 'extra hour', 'extended hours', 'after hours', 'ot', 'overtime', 'transport', 'transportation']),
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

        // Retire the old standalone "Extra Hours" category (code EXTRA_HOURS), if present:
        // re-point its claim items onto 914(b)-000 and delete it, so only one category remains.
        $transportId = DB::table('expense_categories')->where('code', '914(b)-000')->value('id');
        $extraHoursId = DB::table('expense_categories')->where('code', 'EXTRA_HOURS')->value('id');
        if ($transportId && $extraHoursId && $transportId !== $extraHoursId) {
            DB::table('expense_claim_items')->where('expense_category_id', $extraHoursId)
                ->update(['expense_category_id' => $transportId]);
            DB::table('expense_categories')->where('id', $extraHoursId)->delete();
        }

        // Event / Programme Day (code EVENT_DAY) was retired — those claims are now filed under
        // 914(b)-000 Transportation. Existing rows are soft-deactivated by migration
        // 2026_07_21_110000_deactivate_event_programme_day_category; not re-seeded here so it
        // stays out of the dropdown (the deactivate-all-first step at the top handles legacy rows).

        // ── Season parking — posts to GL 916-000, FLAT RM80/month subsidy ───────
        // Enlinea files season parking under the SAME GL line as tolls/casual parking
        // ("916-000: TOLL, PARKING & FINED"), so this category carries gl_code 916-000
        // and the canonical name — it appears on the report exactly like the real form.
        // It stays a SEPARATE category record only to encode the flat-RM80 rule + the
        // once-a-month cap; the claimable amount is always RM80 regardless of the receipt
        // total (the season-pass receipt is evidence, not the basis for the amount).
        DB::table('expense_categories')->updateOrInsert(
            ['code' => 'PARKING_JAYAONE'],
            [
                'gl_code' => '916-000',
                'name' => 'Toll, Parking & Fined',
                'company' => null,
                'description' => 'Season (office) parking subsidy, filed under 916-000 Toll, Parking & Fined and paid as a flat RM80 per month. Attach the season-pass receipt; the claimable amount is fixed at RM80 regardless of the receipt total.',
                // SEASON-qualified phrases ONLY — they must out-score 916-000 "Toll, Parking
                // & Fined" so a season pass claims the flat RM80, while a casual per-trip
                // parking receipt (no "season") still lands on 916-000 at its full value.
                // Deliberately NO bare "jaya one"/"car park" tokens: casual daily Jaya One
                // parking (e.g. a card statement of RM5.50/7.70 entries) carries the Jaya One
                // name too and must stay on 916-000 at actual value — only "season" context
                // (TETAP TIARA "CAR PARK SEASON …", "Season Holder") marks the RM80 subsidy.
                'keywords' => json_encode([
                    'season parking', 'seasonal parking', 'season pass', 'season carpark',
                    'season car park', 'car park season', 'carpark season', 'season floating',
                    'season bay', 'season holder', 'office parking', 'monthly parking',
                    'tetap tiara', 'season',
                ]),
                'monthly_limit' => 80.00,
                'rate_type' => 'fixed',
                'rate_amount' => 80.00,
                'limit_period' => 'monthly',
                'applies_to_role' => null,
                'requires_receipt' => true,
                'is_active' => true,
                'sort_order' => 54,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        // ── Claritas-only allowances (entity-scoped) ────────────────────────────
        // Support Allowance: the eligible employee (Chung Ming Choon) claims his ACTUAL petrol /
        // transport receipts up to RM500/month — receipt-based (enter the real amount, not a
        // distance), one line per receipt. Posts to the Petrol GL (919-000) so accounting is
        // unchanged, and is carved out of the mileage GL via config claims.mileage.receipt_categories
        // so it takes amounts, not km. Resolve his id by work email so it's correct across DBs.
        $supportAllowanceEmployee = DB::table('employees')->where('company_email', 'mcchung@claritas.asia')->value('id')
            ?? DB::table('employees')->where('full_name', 'Chung Ming Choon')->value('id');
        if (! $supportAllowanceEmployee) {
            Log::warning('ExpenseCategorySeeder: could not resolve Chung Ming Choon (mcchung@claritas.asia) for the Support Allowance restriction; category left unrestricted.');
        }
        DB::table('expense_categories')->updateOrInsert(
            ['code' => 'CLARITAS_SUPPORT_ALLOWANCE'],
            [
                'gl_code' => '919-000',
                'name' => 'Support Allowance',
                'company' => 'Claritas',
                'description' => 'Monthly support allowance — actual petrol / transport receipts up to RM500/month, posted to the Petrol GL (919-000). Enter each receipt as a line with its real amount; capped at RM500/month.',
                'keywords' => json_encode(['support allowance', 'allowance']),
                'monthly_limit' => 500.00,
                'rate_type' => 'receipt',
                'rate_amount' => null,
                'limit_period' => 'monthly',
                'applies_to_role' => null,
                'applies_to_employee_ids' => $supportAllowanceEmployee ? json_encode([(int) $supportAllowanceEmployee]) : null,
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
                // 'ever' = the benefit follows the person: current Claritas staff AND ex-Claritas
                // staff who moved to another entity (e.g. Enlinea) keep it, resolved from the
                // employee company timeline. See ClaimRulesService::companyAllows.
                'company_scope' => 'ever',
                'description' => 'Claritas optical and dental benefit, capped at RM500 per calendar year.',
                'keywords' => json_encode(['optical', 'dental', 'dental clinic', 'dentist', 'pergigian', 'klinik pergigian', 'optometry', 'optician', 'glasses', 'spectacles', 'teeth', 'eye', 'lens', 'contact lens']),
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
