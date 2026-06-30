<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Align the Companies table to the employee-canonical name. The 2026-06-25 employee-company
 * normalisation settled Enlinea on "Enlinea Sdn. Bhd." (the majority variant), but the
 * companies.name row still read "Enlinea Sdn Bhd" (no period) — a harmless drift bridged by
 * resolvers, but worth removing so the two stay consistent. Only renames if the with-period
 * form doesn't already exist (avoids creating a duplicate).
 */
return new class extends Migration
{
    public function up(): void
    {
        $exists = DB::table('companies')->where('name', 'Enlinea Sdn. Bhd.')->exists();
        if (! $exists) {
            DB::table('companies')->where('name', 'Enlinea Sdn Bhd')->update(['name' => 'Enlinea Sdn. Bhd.']);
        }
    }

    public function down(): void
    {
        DB::table('companies')->where('name', 'Enlinea Sdn. Bhd.')->update(['name' => 'Enlinea Sdn Bhd']);
    }
};
