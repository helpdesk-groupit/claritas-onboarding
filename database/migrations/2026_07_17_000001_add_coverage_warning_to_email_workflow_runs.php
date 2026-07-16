<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records when a sweep's message cap may have hidden mail it had not already
 * seen — i.e. when the cap threatens NEW documents rather than just leaving the
 * known backlog unread.
 *
 * Kept on the run rather than recomputed on read: only the sweep itself knows
 * the date of the oldest message it looked at, and that is the whole basis of
 * the judgement. Nullable, because the healthy case is silence.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_workflow_runs', function (Blueprint $table) {
            $table->text('coverage_warning')->nullable()->after('error');
        });
    }

    public function down(): void
    {
        Schema::table('email_workflow_runs', function (Blueprint $table) {
            $table->dropColumn('coverage_warning');
        });
    }
};
