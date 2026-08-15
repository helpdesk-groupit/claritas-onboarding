<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5 — who, in management, may authorise a disposal for a given company.
 *
 * An explicit company → user mapping, and it has to be explicit: the real approvers span
 * companies (one person is CEO of one entity and CTO of another), so this cannot be derived
 * from the user's own employer, their designation, or their app role — an employee record
 * carries exactly one company, and a role would approve every company's cycles at once.
 *
 * Several approvers per company are normal and the FIRST decision counts: Enlinea is approved
 * by either of two people, and waiting for both would stall a cycle behind whoever is on
 * leave. Keyed by company NAME to match asset_decommission_batches.company and
 * dispose_assets.company, which are already the registered name — one vocabulary across the
 * module, at the cost of a rename orphaning rows here exactly as it would there.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ewaste_company_approvers', function (Blueprint $table) {
            $table->id();
            $table->string('company');
            $table->unsignedBigInteger('user_id');
            $table->timestamps();

            // One row per person per company — listing somebody twice would double them in
            // every notification the cycle sends.
            $table->unique(['company', 'user_id'], 'eca_company_user_unique');
            $table->index('company', 'eca_company_idx');
            $table->foreign('user_id', 'eca_user_fk')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ewaste_company_approvers');
    }
};
