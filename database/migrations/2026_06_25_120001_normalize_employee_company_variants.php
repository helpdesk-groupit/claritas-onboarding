<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Merge duplicate company-name variants on employees.company so each company is a single
 * value (the approver list and company selector were showing "Enlinea Sdn Bhd" AND
 * "Enlinea Sdn. Bhd." as separate companies). Canonical = the most-used variant; the
 * Claritas 5/5 tie is broken to the variant that already holds the active logins so
 * registered users keep their company string.
 *
 * Data merge — irreversible (we don't record which rows came from which variant), so down()
 * is a no-op. Only touches employees.company; company-specific categories are rare and can be
 * re-pointed manually if any exist.
 */
return new class extends Migration
{
    public function up(): void
    {
        $merges = [
            // minority / duplicate variant  =>  canonical
            'Enlinea Sdn Bhd' => 'Enlinea Sdn. Bhd.',                               // 27 under "Sdn. Bhd."
            'Claritas Consulting (Asia) Sdn. Bhd.' => 'Claritas Consulting (Asia) Sdn Bhd', // logins under "Sdn Bhd"
        ];

        foreach ($merges as $from => $to) {
            DB::table('employees')->where('company', $from)->update(['company' => $to]);
        }
    }

    public function down(): void
    {
        // Irreversible data merge — original variant per row is not recorded.
    }
};
