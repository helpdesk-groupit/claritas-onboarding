<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('onboardings', function (Blueprint $table) {
            $table->string('invite_token')->nullable()->after('status');
            $table->string('invite_email')->nullable()->after('invite_token');
            $table->timestamp('invite_expires_at')->nullable()->after('invite_email');
            $table->boolean('invite_submitted')->default(false)->after('invite_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('onboardings', function (Blueprint $table) {
            $table->dropColumn(['invite_token', 'invite_email', 'invite_expires_at', 'invite_submitted']);
        });
    }
};