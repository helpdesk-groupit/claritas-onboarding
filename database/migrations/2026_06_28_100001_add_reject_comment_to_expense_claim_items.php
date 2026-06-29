<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-item rejection feedback: when a Manager/PIC or HR rejects a claim, they can flag
 * specific items and leave a comment on each for the employee's reference. Stored on the
 * (frozen) rejected item so the employee sees exactly which lines to fix.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expense_claim_items', function (Blueprint $table) {
            $table->text('reject_comment')->nullable()->after('manager_remarks');
        });
    }

    public function down(): void
    {
        Schema::table('expense_claim_items', function (Blueprint $table) {
            $table->dropColumn('reject_comment');
        });
    }
};
