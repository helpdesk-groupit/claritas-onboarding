<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            // Set when a PIC is assigned (TicketController::assignPic) and cleared
            // when a PIC is removed. Lets resolution-time analytics measure how long
            // the PIC actually had the ticket, not the full lifecycle from creation.
            // Pre-existing rows get NULL — analytics fall back to created_at via
            // COALESCE so historical data isn't artificially shifted.
            $table->timestamp('assigned_at')->nullable()->after('assigned_to');
            $table->index('assigned_at');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropIndex(['assigned_at']);
            $table->dropColumn('assigned_at');
        });
    }
};
