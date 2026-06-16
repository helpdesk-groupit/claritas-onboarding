<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * expense_claims.submitted_at was a DATE column, so the submission TIME was
 * truncated to 00:00 on save. Widen it to DATETIME so new submissions keep the
 * real timestamp (consistent with manager_approved_at / hr_approved_at).
 * Existing rows keep their date at 00:00 — that time was never stored, so it
 * cannot be recovered, but historical claims still display correctly by date.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expense_claims', function (Blueprint $table) {
            $table->dateTime('submitted_at')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('expense_claims', function (Blueprint $table) {
            $table->date('submitted_at')->nullable()->change();
        });
    }
};
