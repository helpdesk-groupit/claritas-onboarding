<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HR "Reverse": un-approve a claim that was already fully approved (manager + HR). It behaves
 * like a rejection (reason + optional per-item flags, employee files a correction), but lands in
 * a distinct terminal status `reversed`. These columns record who reversed it, when, and why —
 * kept separate from the hr_approved_* columns so the original approval trail is preserved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expense_claims', function (Blueprint $table) {
            $table->timestamp('reversed_at')->nullable()->after('hr_remarks');
            $table->unsignedBigInteger('reversed_by')->nullable()->after('reversed_at');
            $table->string('reverse_remarks', 1000)->nullable()->after('reversed_by');
        });

        // Add the new terminal status to the ENUM (placed after hr_rejected).
        DB::statement("ALTER TABLE expense_claims MODIFY status ENUM('draft','submitted','manager_approved','manager_rejected','hr_approved','hr_rejected','reversed','paid','cancelled') NOT NULL DEFAULT 'draft'");
    }

    public function down(): void
    {
        // Revert any reversed rows so the ENUM can be narrowed without truncation.
        DB::table('expense_claims')->where('status', 'reversed')->update(['status' => 'hr_approved']);
        DB::statement("ALTER TABLE expense_claims MODIFY status ENUM('draft','submitted','manager_approved','manager_rejected','hr_approved','hr_rejected','paid','cancelled') NOT NULL DEFAULT 'draft'");

        Schema::table('expense_claims', function (Blueprint $table) {
            $table->dropColumn(['reversed_at', 'reversed_by', 'reverse_remarks']);
        });
    }
};
