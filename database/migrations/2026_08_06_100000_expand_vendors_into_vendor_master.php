<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Widens `vendors` from the decommissioning-only vendor list (rental / repair / e-waste)
 * into the company-wide vendor master.
 *
 * Every existing column is left exactly as it is — `name`, `pic_email`, `vendor_types`,
 * `is_primary_ewaste` and `is_active` are load-bearing for the e-waste RFQ and the
 * vendor-return flow, so this migration only ADDS.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            // Company-level contact, distinct from the PIC's own line.
            $table->string('contact_number')->nullable()->after('address');
            $table->string('email')->nullable()->after('contact_number');
            $table->string('website')->nullable()->after('email');

            // The engineer/support contact — who IT calls when the thing breaks. Kept
            // separate from the PIC, who is the commercial contact.
            $table->string('technical_person_name')->nullable()->after('pic_phone');
            $table->string('technical_person_phone')->nullable()->after('technical_person_name');
            $table->string('technical_person_email')->nullable()->after('technical_person_phone');

            // Tax identity. `sst_category` is the taxable-service group the vendor is
            // registered under; compared against our own to decide the B2B exemption.
            $table->string('sst_number', 60)->nullable()->after('company_registration_no');
            $table->string('sst_category', 60)->nullable()->after('sst_number');

            $table->string('industry', 60)->nullable()->after('vendor_types');

            $table->index('sst_category');
            $table->index('industry');
        });
    }

    public function down(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->dropIndex(['sst_category']);
            $table->dropIndex(['industry']);
            $table->dropColumn([
                'contact_number', 'email', 'website',
                'technical_person_name', 'technical_person_phone', 'technical_person_email',
                'sst_number', 'sst_category', 'industry',
            ]);
        });
    }
};
