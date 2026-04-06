<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ExpenseCategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            [
                'name' => 'Transportation / Mileage',
                'code' => 'TRANSPORT',
                'description' => 'Grab, taxi, fuel, toll, mileage claims for business travel',
                'keywords' => json_encode(['grab', 'taxi', 'fuel', 'petrol', 'diesel', 'mileage', 'e-hailing', 'uber', 'bus', 'lrt', 'mrt', 'train', 'transit']),
                'monthly_limit' => null,
                'requires_receipt' => true,
                'is_active' => true,
                'sort_order' => 1,
            ],
            [
                'name' => 'Meals & Entertainment',
                'code' => 'MEALS',
                'description' => 'Business meals, client entertainment, team meals',
                'keywords' => json_encode(['lunch', 'dinner', 'breakfast', 'meal', 'food', 'restaurant', 'cafe', 'coffee', 'tea', 'entertainment', 'client meal', 'team lunch']),
                'monthly_limit' => null,
                'requires_receipt' => true,
                'is_active' => true,
                'sort_order' => 2,
            ],
            [
                'name' => 'Parking',
                'code' => 'PARKING',
                'description' => 'Parking fees for business purposes',
                'keywords' => json_encode(['parking', 'park', 'valet', 'car park']),
                'monthly_limit' => null,
                'requires_receipt' => true,
                'is_active' => true,
                'sort_order' => 3,
            ],
            [
                'name' => 'Office Parking - JayaOne',
                'code' => 'PARKING_JAYAONE',
                'description' => 'Monthly office parking at JayaOne (capped at RM80/month)',
                'keywords' => json_encode(['jayaone', 'jaya one', 'office parking', 'monthly parking']),
                'monthly_limit' => 80.00,
                'requires_receipt' => true,
                'is_active' => true,
                'sort_order' => 4,
            ],
            [
                'name' => 'Toll',
                'code' => 'TOLL',
                'description' => 'Highway toll charges for business travel',
                'keywords' => json_encode(['toll', 'highway', 'tng', 'touch n go', 'smart tag', 'plus', 'nkve', 'duke', 'sprint', 'lksa']),
                'monthly_limit' => null,
                'requires_receipt' => true,
                'is_active' => true,
                'sort_order' => 5,
            ],
            [
                'name' => 'Office Supplies',
                'code' => 'OFFICE_SUPPLIES',
                'description' => 'Stationery, printer ink, office materials',
                'keywords' => json_encode(['stationery', 'paper', 'ink', 'toner', 'pen', 'office supply', 'supplies', 'printer', 'cartridge']),
                'monthly_limit' => null,
                'requires_receipt' => true,
                'is_active' => true,
                'sort_order' => 6,
            ],
            [
                'name' => 'Accommodation / Travel',
                'code' => 'ACCOMMODATION',
                'description' => 'Hotel, lodging, flights for business travel',
                'keywords' => json_encode(['hotel', 'accommodation', 'lodging', 'flight', 'airfare', 'airline', 'travel', 'airasia', 'booking']),
                'monthly_limit' => null,
                'requires_receipt' => true,
                'is_active' => true,
                'sort_order' => 7,
            ],
            [
                'name' => 'Communication',
                'code' => 'COMMUNICATION',
                'description' => 'Phone bills, internet charges for business use',
                'keywords' => json_encode(['phone', 'mobile', 'internet', 'telco', 'broadband', 'data', 'sim', 'topup', 'reload', 'wifi']),
                'monthly_limit' => null,
                'requires_receipt' => true,
                'is_active' => true,
                'sort_order' => 8,
            ],
            [
                'name' => 'Extra Hours',
                'code' => 'EXTRA_HOURS',
                'description' => 'Claims for extra working hours (specify hours clearly)',
                'keywords' => json_encode(['extra hours', 'overtime', 'ot', 'extra hour', 'extended hours', 'after hours']),
                'monthly_limit' => null,
                'requires_receipt' => false,
                'is_active' => true,
                'sort_order' => 9,
            ],
            [
                'name' => 'Training / Conference',
                'code' => 'TRAINING',
                'description' => 'Training fees, conference registration, seminars',
                'keywords' => json_encode(['training', 'conference', 'seminar', 'workshop', 'course', 'certification', 'exam', 'webinar']),
                'monthly_limit' => null,
                'requires_receipt' => true,
                'is_active' => true,
                'sort_order' => 10,
            ],
            [
                'name' => 'Medical',
                'code' => 'MEDICAL',
                'description' => 'Medical expenses not covered by insurance',
                'keywords' => json_encode(['medical', 'clinic', 'hospital', 'doctor', 'pharmacy', 'medicine', 'prescription', 'dental', 'optical']),
                'monthly_limit' => null,
                'requires_receipt' => true,
                'is_active' => true,
                'sort_order' => 11,
            ],
            [
                'name' => 'Utilities',
                'code' => 'UTILITIES',
                'description' => 'Electricity, water or utility claims for work purposes',
                'keywords' => json_encode(['utility', 'electricity', 'water', 'electric', 'bill', 'tnb']),
                'monthly_limit' => null,
                'requires_receipt' => true,
                'is_active' => true,
                'sort_order' => 12,
            ],
            [
                'name' => 'Miscellaneous',
                'code' => 'MISC',
                'description' => 'Other business-related expenses not in other categories',
                'keywords' => json_encode(['misc', 'miscellaneous', 'other', 'sundry']),
                'monthly_limit' => null,
                'requires_receipt' => true,
                'is_active' => true,
                'sort_order' => 99,
            ],
        ];

        foreach ($categories as $cat) {
            DB::table('expense_categories')->updateOrInsert(
                ['code' => $cat['code']],
                array_merge($cat, [
                    'created_at' => now(),
                    'updated_at' => now(),
                ])
            );
        }

        // Default policy
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
                'general_rules' => "All claims must be submitted by the 20th of each month with reporting manager's signature.\nLate claims will be processed in the following month.\nFull Name must be filled in correctly.\nDo not use \"Petty Cash\" as Expense Type.\nProper categorization of expenses is mandatory.\nAll claim items must be for legitimate business purposes only.\nFor Extra Hours claims, clearly specify the number of hours worked.\nPlease prepare separate claim forms for different events versus personal claims.\nAdmin reserves the right to refuse incomplete or non-compliant claims.",
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }
}
