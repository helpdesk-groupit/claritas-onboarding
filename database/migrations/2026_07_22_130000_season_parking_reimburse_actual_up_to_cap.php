<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Season parking (PARKING_JAYAONE) changes from a FLAT RM80 subsidy to a CAPPED reimbursement:
 * you now claim the actual season-pass receipt up to RM80/month (min(receipt, 80)), matching
 * the other capped categories (Medical, Optical & Dental, Support Allowance).
 *
 * Mechanically that means it becomes a normal receipt-based category (rate_type 'receipt',
 * no flat rate_amount) whose RM80 monthly_limit is the cap. The claim amount is then derived
 * as min(receipt total, remaining cap) by ExpenseClaimController, and locked (read-only) in the
 * form. Existing season-parking claim items keep their stored amount — only the category config
 * changes, so nothing historical is rewritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('expense_categories')->where('code', 'PARKING_JAYAONE')->update([
            'rate_type' => 'receipt',
            'rate_amount' => null,
            'description' => 'Season (office) parking subsidy, filed under 916-000 Toll, Parking & Fined. '
                .'Reimburses the actual season-pass receipt up to RM80 per month; attach the season-pass receipt.',
        ]);
    }

    public function down(): void
    {
        DB::table('expense_categories')->where('code', 'PARKING_JAYAONE')->update([
            'rate_type' => 'fixed',
            'rate_amount' => 80.00,
            'description' => 'Season (office) parking subsidy, filed under 916-000 Toll, Parking & Fined and paid as a flat RM80 per month. '
                .'Attach the season-pass receipt; the claimable amount is fixed at RM80 regardless of the receipt total.',
        ]);
    }
};
