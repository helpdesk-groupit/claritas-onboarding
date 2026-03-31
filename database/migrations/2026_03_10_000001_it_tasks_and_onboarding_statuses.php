<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Add IT task tracking columns to onboardings
        Schema::table('onboardings', function (Blueprint $table) {
            $table->string('assigned_pic_id')->nullable()->after('welcome_email_sent')
                  ->comment('User ID of assigned IT staff member');
            $table->enum('asset_preparation_status', ['pending','in_progress','done'])
                  ->default('pending')->after('assigned_pic_id');
            $table->enum('work_email_status', ['pending','in_progress','done'])
                  ->default('pending')->after('asset_preparation_status');
            $table->unsignedBigInteger('assigned_pic_user_id')->nullable()->after('work_email_status');
        });

        // 2. Create it_tasks table
        Schema::create('it_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('onboarding_id')->constrained('onboardings')->onDelete('cascade');
            $table->foreignId('assigned_to')->constrained('users')->onDelete('cascade');
            $table->foreignId('assigned_by')->constrained('users')->onDelete('cascade');
            $table->string('task_type'); // 'asset_preparation' | 'work_email' | 'other'
            $table->string('title');
            $table->text('description')->nullable();
            $table->enum('status', ['pending','in_progress','done'])->default('pending');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('it_tasks');
        Schema::table('onboardings', function (Blueprint $table) {
            $table->dropColumn([
                'assigned_pic_id',
                'asset_preparation_status',
                'work_email_status',
                'assigned_pic_user_id',
            ]);
        });
    }
};
