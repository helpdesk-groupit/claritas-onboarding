<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rejection workflow: an HR rejection is held for the approving manager to review and
 * RELEASE to the employee (optionally with a comment) before the employee may correct
 * it. A correction is filed as a NEW claim, linked back to the rejected one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expense_claims', function (Blueprint $table) {
            $table->timestamp('released_at')->nullable()->after('hr_remarks');
            $table->unsignedBigInteger('released_by')->nullable()->after('released_at'); // manager (employee id)
            $table->string('release_remarks', 1000)->nullable()->after('released_by');
            $table->unsignedBigInteger('correction_of_id')->nullable()->after('release_remarks')->index();
        });
    }

    public function down(): void
    {
        Schema::table('expense_claims', function (Blueprint $table) {
            $table->dropColumn(['released_at', 'released_by', 'release_remarks', 'correction_of_id']);
        });
    }
};
