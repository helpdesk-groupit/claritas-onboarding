<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Counts the messages a sweep could not read at all — and therefore never
 * offered to the detection engine.
 *
 * Until now these left NO trace in any counter. `scanned_count` records what the
 * adapter RETURNED, so a message dropped inside the adapter simply never
 * existed as far as the run was concerned: twenty documents a day disappeared
 * under a green "success" tick, visible only to somebody grepping the
 * application log for "unreadable". A number on the run is what makes the loss
 * legible — and, since the run now goes `partial` when it is non-zero, what
 * makes the badge stop lying.
 *
 * Expected to be 0. It is the exception that matters, not the value.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_workflow_runs', function (Blueprint $table) {
            $table->unsignedInteger('unreadable_count')->default(0)->after('failed_count');
        });
    }

    public function down(): void
    {
        Schema::table('email_workflow_runs', function (Blueprint $table) {
            $table->dropColumn('unreadable_count');
        });
    }
};
