<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Retire the "Event / Programme Day" claim category (code EVENT_DAY) — those claims are now
 * filed under the 914(b)-000 Transportation category. Soft-deactivated (is_active = false)
 * rather than deleted, so historical claim items that reference it keep rendering.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('expense_categories')->where('code', 'EVENT_DAY')->update(['is_active' => false]);
    }

    public function down(): void
    {
        DB::table('expense_categories')->where('code', 'EVENT_DAY')->update(['is_active' => true]);
    }
};
