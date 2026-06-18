<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-item approver routing: each line item can be approved by a different
 * manager (e.g. event/programme items go to the programme's manager, not the
 * employee's own). approver_id = the assigned manager (Employee); manager_status
 * = that manager's decision. A claim advances to manager_approved only once every
 * item has a manager decision. HR's final per-item call still uses review_status.
 *
 * Defaults are 'approved'/null so existing (pre-deploy, local-only) rows keep
 * their meaning; submit() sets approver_id + manager_status='pending' going forward.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expense_claim_items', function (Blueprint $table) {
            $table->unsignedBigInteger('approver_id')->nullable()->after('review_status')->index();
            $table->enum('manager_status', ['pending', 'approved', 'rejected'])->default('approved')->after('approver_id');
            $table->string('manager_remarks', 500)->nullable()->after('manager_status');
        });
    }

    public function down(): void
    {
        Schema::table('expense_claim_items', function (Blueprint $table) {
            $table->dropColumn(['approver_id', 'manager_status', 'manager_remarks']);
        });
    }
};
