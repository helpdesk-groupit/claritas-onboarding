<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The HR Claims index and the manager/HR stat counts filter by `status` (WHERE status IN …).
     * Without this index that filter is a full table scan; it grows linearly as claims accumulate.
     */
    public function up(): void
    {
        Schema::table('expense_claims', function (Blueprint $table) {
            $table->index('status', 'expense_claims_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('expense_claims', function (Blueprint $table) {
            $table->dropIndex('expense_claims_status_index');
        });
    }
};
