<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operational vendor master for the Asset Decommissioning module.
 *
 * Intentionally SEPARATE from the finance `acc_vendors` (Accounting\Vendor),
 * which is AP-only and has no PIC/type. Both decommissioning flows read the
 * vendor + PIC from here (replacing free-text vendor strings). Cross-linkable
 * to acc_vendors later if finance ever needs it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendors', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // JSON array of any of: rental | repair | ewaste (a vendor may hold several)
            $table->json('vendor_types')->nullable();
            $table->string('pic_name')->nullable();
            $table->string('pic_email')->nullable();
            $table->string('pic_phone')->nullable();
            $table->string('company_registration_no')->nullable();
            $table->text('address')->nullable();
            // The single vendor that receives the quarterly e-waste RFQ.
            $table->boolean('is_primary_ewaste')->default(false);
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('is_active');
            $table->index('is_primary_ewaste');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendors');
    }
};
