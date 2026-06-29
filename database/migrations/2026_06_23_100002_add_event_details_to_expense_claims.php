<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Claim-level "Category B" fields for the new inline claim builder: the event date and
 * project/client are set ONCE per claim (no longer per item) — every item on the claim
 * inherits them. The approving manager is already on `manager_id`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expense_claims', function (Blueprint $table) {
            $table->date('event_date')->nullable()->after('event');
            $table->string('project_client')->nullable()->after('event_date');
        });
    }

    public function down(): void
    {
        Schema::table('expense_claims', function (Blueprint $table) {
            $table->dropColumn(['event_date', 'project_client']);
        });
    }
};
