<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('resource', 100); // e.g. 'onboarding', 'onboarding.personal_details'
            $table->enum('access_level', ['full', 'view', 'edit', 'none']);
            $table->timestamps();
            $table->unique(['user_id', 'resource']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_permissions');
    }
};
