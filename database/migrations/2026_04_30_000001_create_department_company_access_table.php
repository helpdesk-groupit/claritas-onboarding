<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('department_company_access', function (Blueprint $table) {
            $table->id();
            $table->string('department', 50);
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
            $table->timestamps();

            // Each (department, company) pair is unique
            $table->unique(['department', 'company_id']);
            $table->index('department');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('department_company_access');
    }
};
