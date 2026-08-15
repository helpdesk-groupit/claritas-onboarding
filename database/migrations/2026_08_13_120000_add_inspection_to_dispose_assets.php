<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 of the refined e-waste flow — inspection becomes a separate, later act.
 *
 * Marking an asset Not Good used to record its completeness in the same submit, defaulted
 * to `complete` when the operator said nothing. That made "never inspected" and "inspected,
 * all parts intact" the same row, so the quarterly gate had nothing to test. Completeness is
 * now set only by an inspection, and `inspected_at` is what says one happened.
 *
 * `company` is the owning company CONFIRMED at inspection, resolved against the registered
 * companies. The asset's own company_name is free text (Claritas Asia Sdn. Bhd. / Claritas
 * Asia Sdn Bhd / blank all appear), and from Phase 4 the cycle splits per company so the
 * right management approver signs — a fuzzy match is not good enough to decide who may
 * authorise a disposal.
 *
 * Rows already in the queue are deliberately left UNINSPECTED rather than backfilled: they
 * never were inspected, and stamping them would let the first quarterly cycle sweep assets
 * nobody has looked at. They surface as "Not inspected" and are chased by the Phase 3
 * reminders.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dispose_assets', function (Blueprint $table) {
            $table->timestamp('inspected_at')->nullable()->after('ewaste_parts_removed');
            $table->unsignedBigInteger('inspected_by')->nullable()->after('inspected_at');
            $table->string('company')->nullable()->after('inspected_by');

            $table->foreign('inspected_by', 'dispose_assets_inspected_by_fk')
                ->references('id')->on('users')->nullOnDelete();

            // The quarterly gate asks "any e-waste row still uninspected?" on every run.
            $table->index(['decommission_type', 'inspected_at'], 'dispose_assets_type_inspected_idx');
        });
    }

    public function down(): void
    {
        Schema::table('dispose_assets', function (Blueprint $table) {
            $table->dropForeign('dispose_assets_inspected_by_fk');
            $table->dropIndex('dispose_assets_type_inspected_idx');
            $table->dropColumn(['inspected_at', 'inspected_by', 'company']);
        });
    }
};
