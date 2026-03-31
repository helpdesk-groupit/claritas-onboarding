<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('employees', 'remarks')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->text('remarks')->nullable()->after('resignation_reason');
            });
        }
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('remarks');
        });
    }
};
