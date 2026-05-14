<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add `source_company_id` to `department_company_access` — the company
     * whose team this row authorises (the service provider). Each row now
     * reads as: "<source_company>'s <department> team also handles tickets
     * from <company_id> (the served / client company)."
     *
     * Without this column, when two companies both have a "Group IT" team
     * the pivot can't disambiguate which one serves a given raiser. With it,
     * every routing rule has an explicit owner.
     *
     * Backfill rule: for each existing pivot row, set source_company_id to
     * the first company with auto-derived members for that department. If
     * the dept has no auto-derived members at any company (rare), leave
     * source_company_id NULL — the admin must edit via Department Settings.
     */
    public function up(): void
    {
        Schema::table('department_company_access', function (Blueprint $table) {
            $table->foreignId('source_company_id')
                  ->nullable()
                  ->after('id')
                  ->constrained('companies')
                  ->cascadeOnDelete();

            // Drop the old 2-column unique; replace with 3-column.
            $table->dropUnique(['department', 'company_id']);
            $table->unique(['source_company_id', 'department', 'company_id'],
                           'dca_source_dept_served_unique');
            $table->index('source_company_id');
        });

        // PHP backfill — uses the model's existing auto-derive logic so the
        // chosen source for each row matches what the dept-management UI
        // would have inferred.
        $rows = DB::table('department_company_access')->get(['id', 'department']);
        foreach ($rows as $row) {
            $sourceIds = \App\Models\Ticket::defaultServedCompanyIdsForDepartmentPublic($row->department);
            if (!empty($sourceIds)) {
                DB::table('department_company_access')
                    ->where('id', $row->id)
                    ->update(['source_company_id' => $sourceIds[0]]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('department_company_access', function (Blueprint $table) {
            $table->dropUnique('dca_source_dept_served_unique');
            $table->dropForeign(['source_company_id']);
            $table->dropColumn('source_company_id');
            $table->unique(['department', 'company_id']);
        });
    }
};
