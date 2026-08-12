<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bank details + TIN on the vendor master.
 *
 * The registration form already captured who the vendor IS (registration no., SST) and who
 * to talk to, but not how to pay them or how they are identified to LHDN — so a payment
 * instruction lived in whatever invoice PDF happened to be to hand, and e-Invoice needs the
 * counterparty's TIN.
 *
 * `bank_name` / `bank_account_number` / `bank_swift` deliberately reuse the column names the
 * finance AP ledger (`acc_vendors`) already uses for the same three facts. Nothing posts
 * between the two tables, but they describe the same real-world vendor, so keeping the names
 * identical is what makes a future reconciliation between them obvious rather than a mapping
 * exercise. `bank_account_name` and `bank_branch` have no acc_vendors twin and are added
 * because a payment instruction needs them: the beneficiary name on the account is regularly
 * NOT the trading name we file the vendor under, and a transfer to a mismatched name is
 * rejected by the bank.
 *
 * `tin_number` matches `companies.tin_number` (string 100) — same fact, our side and theirs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            // Tax identity, next to the SST columns it belongs with.
            $table->string('tin_number', 100)->nullable()->after('sst_category');

            // How we pay them.
            $table->string('bank_name')->nullable()->after('tin_number');
            $table->string('bank_account_name')->nullable()->after('bank_name');
            $table->string('bank_account_number', 50)->nullable()->after('bank_account_name');
            $table->string('bank_branch')->nullable()->after('bank_account_number');
            $table->string('bank_swift', 20)->nullable()->after('bank_branch');
        });
    }

    public function down(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->dropColumn([
                'tin_number',
                'bank_name', 'bank_account_name', 'bank_account_number', 'bank_branch', 'bank_swift',
            ]);
        });
    }
};
