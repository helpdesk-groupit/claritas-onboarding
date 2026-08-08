<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Turns "how far back did this sweep reach" from a log line into state.
 *
 * The message cap used to be the COVERAGE bound: a sweep read the newest N and
 * stopped, so on a mailbox whose daily volume exceeded N the mail between one
 * run and the next was read by no run, ever, then aged out of the window. The
 * old coverage_warning column noticed that and said so — correctly, repeatedly,
 * and to nobody. A sweep now keeps paging older until it reaches the previous
 * run's coverage, which needs three facts persisted per run:
 *
 *  - covered_back_to    the oldest message this run examined. The next run's
 *                       target, and the only thing that can answer "is the
 *                       chain of sweeps contiguous".
 *  - coverage_gap_from  the target a run could NOT reach before its budget ran
 *                       out. Inherited by the next run so an unmet target is
 *                       retried rather than forgotten — without it, each run
 *                       would target its predecessor's start time and the hole
 *                       behind it would be permanent.
 *  - passes             how many mailbox passes it took. Observability: a run
 *                       that quietly needs 8 passes every night is a workflow
 *                       whose cron should fire more often.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_workflow_runs', function (Blueprint $table) {
            $table->timestamp('covered_back_to')->nullable()->after('coverage_warning');
            $table->timestamp('coverage_gap_from')->nullable()->after('covered_back_to');
            $table->unsignedSmallInteger('passes')->default(0)->after('coverage_gap_from');
        });
    }

    public function down(): void
    {
        Schema::table('email_workflow_runs', function (Blueprint $table) {
            $table->dropColumn(['covered_back_to', 'coverage_gap_from', 'passes']);
        });
    }
};
