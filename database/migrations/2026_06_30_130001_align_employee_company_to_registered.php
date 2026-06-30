<?php

use App\Models\Company;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Make employees.company match the EXACT registered name from Company Registration (the single
 * source of truth). Earlier normalisation settled on login/short variants ("Claritas … Sdn Bhd",
 * "Nuren Singapore") that don't match the registered spellings ("… Sdn. Bhd.",
 * "Nuren (Singapore) Pte. Ltd."). Each distinct employee value is resolved to its registered
 * Company via Company::forName() (tolerant of punctuation / trailing entity words) and rewritten
 * to that exact name. Values that don't resolve to a registered company are left untouched.
 *
 * Down() is a no-op — the prior per-row value isn't recorded (one-way canonicalisation).
 */
return new class extends Migration
{
    public function up(): void
    {
        $values = DB::table('employees')->whereNotNull('company')->where('company', '!=', '')
            ->distinct()->pluck('company');

        foreach ($values as $current) {
            $registered = Company::forName($current)?->name;
            if ($registered && $registered !== $current) {
                DB::table('employees')->where('company', $current)->update(['company' => $registered]);
            }
        }
    }

    public function down(): void
    {
        // Irreversible canonicalisation — original per-row variant not recorded.
    }
};
