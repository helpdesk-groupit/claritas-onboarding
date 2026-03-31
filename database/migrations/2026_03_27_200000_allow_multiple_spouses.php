<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_spouse_details', function (Blueprint $table) {
            // Must drop the foreign key first — MySQL won't drop a unique index
            // that is also referenced by a foreign key constraint
            $table->dropForeign(['employee_id']);
        });

        Schema::table('employee_spouse_details', function (Blueprint $table) {
            // Now safe to drop the unique constraint
            $table->dropUnique(['employee_id']);
        });

        Schema::table('employee_spouse_details', function (Blueprint $table) {
            // Re-add the foreign key (without unique — allows multiple rows per employee)
            $table->foreign('employee_id')
                  ->references('id')
                  ->on('employees')
                  ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::table('employee_spouse_details', function (Blueprint $table) {
            $table->dropForeign(['employee_id']);
        });

        Schema::table('employee_spouse_details', function (Blueprint $table) {
            $table->unique('employee_id');
        });

        Schema::table('employee_spouse_details', function (Blueprint $table) {
            $table->foreign('employee_id')
                  ->references('id')
                  ->on('employees')
                  ->onDelete('cascade');
        });
    }
};