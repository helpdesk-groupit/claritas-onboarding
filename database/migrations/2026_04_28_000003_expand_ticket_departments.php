<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Convert the strict enum to a flexible varchar so future departments
        // can be added without schema changes.
        DB::statement("ALTER TABLE tickets MODIFY department VARCHAR(50) NOT NULL");

        // Rename existing values to match the new naming convention.
        DB::table('tickets')->where('department', 'IT')->update(['department' => 'Group IT']);
        DB::table('tickets')->where('department', 'HR')->update(['department' => 'HRA']);
    }

    public function down(): void
    {
        // Revert names first so they fit back inside the enum.
        DB::table('tickets')->where('department', 'Group IT')->update(['department' => 'IT']);
        DB::table('tickets')->where('department', 'HRA')->update(['department' => 'HR']);
        // Any rows in the new departments would fail the enum constraint —
        // collapse them to 'Admin' as the safest catch-all.
        DB::table('tickets')->whereNotIn('department', ['HR', 'IT', 'Finance', 'Admin'])
            ->update(['department' => 'Admin']);

        DB::statement("ALTER TABLE tickets MODIFY department ENUM('HR', 'IT', 'Finance', 'Admin') NOT NULL");
    }
};
