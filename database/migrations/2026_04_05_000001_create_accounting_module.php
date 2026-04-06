<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Full Accounting Module Schema
 *
 * Creates 38 tables covering:
 *   - Chart of Accounts & General Ledger (double-entry bookkeeping)
 *   - Accounts Receivable (customers, invoices, credit notes, receipts)
 *   - Accounts Payable (vendors, bills, purchase orders, payments)
 *   - Banking & Cash Management (accounts, transactions, reconciliation, transfers)
 *   - Tax Management (SST, withholding tax, income tax)
 *   - Fixed Assets & Depreciation
 *   - Budgeting & Forecasting
 *   - AI Invoice OCR & Chatbot
 *   - Multi-currency support
 *   - Audit trail
 *
 * Also expands users.role enum to include finance_manager, finance_executive.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ══════════════════════════════════════════════════════════════════
        // 0. EXPAND USER ROLES
        // ══════════════════════════════════════════════════════════════════
        DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM(
            'hr_manager','hr_executive','hr_intern',
            'it_manager','it_executive','it_intern',
            'superadmin','system_admin',
            'employee',
            'finance_manager','finance_executive'
        ) DEFAULT 'employee'");

        // ══════════════════════════════════════════════════════════════════
        // 1. CORE ACCOUNTING SETUP
        // ══════════════════════════════════════════════════════════════════

        // ── Currencies ──────────────────────────────────────────────────
        if (!Schema::hasTable('acc_currencies')) Schema::create('acc_currencies', function (Blueprint $table) {
            $table->id();
            $table->string('code', 3)->unique();
            $table->string('name', 100);
            $table->string('symbol', 10);
            $table->decimal('exchange_rate', 14, 6)->default(1.000000);
            $table->boolean('is_base')->default(false);
            $table->timestamps();
        });

        // ── Fiscal Years ────────────────────────────────────────────────
        if (!Schema::hasTable('acc_fiscal_years')) Schema::create('acc_fiscal_years', function (Blueprint $table) {
            $table->id();
            $table->string('company')->nullable();
            $table->string('name', 100);
            $table->date('start_date');
            $table->date('end_date');
            $table->enum('status', ['open', 'closed'])->default('open');
            $table->timestamps();
        });

        // ── Fiscal Periods ──────────────────────────────────────────────
        if (!Schema::hasTable('acc_fiscal_periods')) Schema::create('acc_fiscal_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fiscal_year_id')->constrained('acc_fiscal_years')->cascadeOnDelete();
            $table->unsignedTinyInteger('period_number');
            $table->string('name', 50);
            $table->date('start_date');
            $table->date('end_date');
            $table->enum('status', ['open', 'closed'])->default('open');
            $table->timestamps();
        });

        // ── Chart of Accounts ───────────────────────────────────────────
        if (!Schema::hasTable('acc_chart_of_accounts')) Schema::create('acc_chart_of_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('company')->nullable();
            $table->string('account_code', 20);
            $table->string('name');
            $table->enum('type', ['asset', 'liability', 'equity', 'revenue', 'expense']);
            $table->string('sub_type', 80)->nullable();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->text('description')->nullable();
            $table->enum('normal_balance', ['debit', 'credit']);
            $table->decimal('opening_balance', 14, 2)->default(0);
            $table->date('opening_balance_date')->nullable();
            $table->string('currency', 3)->default('MYR');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_system')->default(false);
            $table->boolean('allow_direct_posting')->default(true);
            $table->timestamps();

            $table->unique(['company', 'account_code']);
            $table->foreign('parent_id')->references('id')->on('acc_chart_of_accounts')->nullOnDelete();
            $table->index(['company', 'type']);
        });

        // ── Tax Codes ───────────────────────────────────────────────────
        if (!Schema::hasTable('acc_tax_codes')) Schema::create('acc_tax_codes', function (Blueprint $table) {
            $table->id();
            $table->string('company')->nullable();
            $table->string('code', 20);
            $table->string('name');
            $table->decimal('rate', 6, 3)->default(0);
            $table->enum('type', ['sst_sales', 'sst_service', 'gst', 'wht', 'income_tax', 'exempt', 'zero_rated', 'out_of_scope']);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('purchase_account_id')->nullable();
            $table->unsignedBigInteger('sales_account_id')->nullable();
            $table->timestamps();

            $table->unique(['company', 'code']);
            $table->foreign('purchase_account_id')->references('id')->on('acc_chart_of_accounts')->nullOnDelete();
            $table->foreign('sales_account_id')->references('id')->on('acc_chart_of_accounts')->nullOnDelete();
        });

        // ── Accounting Settings ─────────────────────────────────────────
        if (!Schema::hasTable('acc_settings')) Schema::create('acc_settings', function (Blueprint $table) {
            $table->id();
            $table->string('company')->unique();
            $table->unsignedTinyInteger('fiscal_year_start_month')->default(1);
            $table->string('base_currency', 3)->default('MYR');
            $table->string('tax_registration_number')->nullable();
            $table->enum('tax_type', ['sst', 'gst'])->default('sst');
            $table->string('invoice_prefix', 20)->default('INV-');
            $table->string('credit_note_prefix', 20)->default('CN-');
            $table->string('bill_prefix', 20)->default('BILL-');
            $table->string('po_prefix', 20)->default('PO-');
            $table->string('journal_prefix', 20)->default('JE-');
            $table->string('payment_prefix', 20)->default('PAY-');
            $table->string('receipt_prefix', 20)->default('REC-');
            $table->unsignedBigInteger('next_invoice_number')->default(1);
            $table->unsignedBigInteger('next_credit_note_number')->default(1);
            $table->unsignedBigInteger('next_bill_number')->default(1);
            $table->unsignedBigInteger('next_po_number')->default(1);
            $table->unsignedBigInteger('next_journal_number')->default(1);
            $table->unsignedBigInteger('next_payment_number')->default(1);
            $table->unsignedBigInteger('next_receipt_number')->default(1);
            $table->unsignedSmallInteger('default_payment_terms_days')->default(30);
            $table->unsignedBigInteger('default_tax_code_id')->nullable();
            $table->unsignedBigInteger('default_sales_account_id')->nullable();
            $table->unsignedBigInteger('default_purchase_account_id')->nullable();
            $table->unsignedBigInteger('retained_earnings_account_id')->nullable();
            $table->boolean('enable_multi_currency')->default(false);
            $table->string('ai_provider', 30)->default('openai');
            $table->text('ai_api_key')->nullable();
            $table->string('ai_model', 50)->default('gpt-4o');
            $table->timestamps();

            $table->foreign('default_tax_code_id')->references('id')->on('acc_tax_codes')->nullOnDelete();
            $table->foreign('default_sales_account_id')->references('id')->on('acc_chart_of_accounts')->nullOnDelete();
            $table->foreign('default_purchase_account_id')->references('id')->on('acc_chart_of_accounts')->nullOnDelete();
            $table->foreign('retained_earnings_account_id')->references('id')->on('acc_chart_of_accounts')->nullOnDelete();
        });

        // ══════════════════════════════════════════════════════════════════
        // 2. GENERAL LEDGER
        // ══════════════════════════════════════════════════════════════════

        if (!Schema::hasTable('acc_journal_entries')) Schema::create('acc_journal_entries', function (Blueprint $table) {
            $table->id();
            $table->string('company')->nullable();
            $table->string('entry_number', 30);
            $table->date('date');
            $table->string('reference')->nullable();
            $table->text('description')->nullable();
            $table->enum('status', ['draft', 'posted', 'void'])->default('draft');
            $table->string('source_type', 50)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->unsignedBigInteger('posted_by')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->unsignedBigInteger('reversed_by_entry_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->unique(['company', 'entry_number']);
            $table->foreign('posted_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['company', 'date']);
            $table->index(['source_type', 'source_id']);
        });

        if (!Schema::hasTable('acc_journal_entry_lines')) Schema::create('acc_journal_entry_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_entry_id')->constrained('acc_journal_entries')->cascadeOnDelete();
            $table->unsignedBigInteger('account_id');
            $table->text('description')->nullable();
            $table->decimal('debit', 14, 2)->default(0);
            $table->decimal('credit', 14, 2)->default(0);
            $table->unsignedBigInteger('tax_code_id')->nullable();
            $table->decimal('tax_amount', 14, 2)->default(0);
            $table->string('currency', 3)->default('MYR');
            $table->decimal('exchange_rate', 14, 6)->default(1);
            $table->timestamps();

            $table->foreign('account_id')->references('id')->on('acc_chart_of_accounts')->restrictOnDelete();
            $table->foreign('tax_code_id')->references('id')->on('acc_tax_codes')->nullOnDelete();
            $table->index('account_id');
        });

        // ══════════════════════════════════════════════════════════════════
        // 3. ACCOUNTS RECEIVABLE
        // ══════════════════════════════════════════════════════════════════

        if (!Schema::hasTable('acc_customers')) Schema::create('acc_customers', function (Blueprint $table) {
            $table->id();
            $table->string('company')->nullable();
            $table->string('customer_code', 30);
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('address_line_1')->nullable();
            $table->string('address_line_2')->nullable();
            $table->string('city', 100)->nullable();
            $table->string('state', 100)->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->string('country', 100)->default('Malaysia');
            $table->string('tax_id', 50)->nullable();
            $table->decimal('credit_limit', 14, 2)->default(0);
            $table->unsignedSmallInteger('payment_terms_days')->default(30);
            $table->string('currency', 3)->default('MYR');
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company', 'customer_code']);
        });

        if (!Schema::hasTable('acc_sales_invoices')) Schema::create('acc_sales_invoices', function (Blueprint $table) {
            $table->id();
            $table->string('company')->nullable();
            $table->foreignId('customer_id')->constrained('acc_customers')->restrictOnDelete();
            $table->string('invoice_number', 30);
            $table->date('date');
            $table->date('due_date');
            $table->string('reference')->nullable();
            $table->text('description')->nullable();
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('tax_total', 14, 2)->default(0);
            $table->decimal('discount_total', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->decimal('amount_paid', 14, 2)->default(0);
            $table->decimal('balance_due', 14, 2)->default(0);
            $table->string('currency', 3)->default('MYR');
            $table->decimal('exchange_rate', 14, 6)->default(1);
            $table->enum('status', ['draft', 'sent', 'partial', 'paid', 'overdue', 'void'])->default('draft');
            $table->text('notes')->nullable();
            $table->text('terms')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->unique(['company', 'invoice_number']);
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('approved_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['company', 'status']);
            $table->index(['customer_id', 'status']);
        });

        if (!Schema::hasTable('acc_sales_invoice_items')) Schema::create('acc_sales_invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_invoice_id')->constrained('acc_sales_invoices')->cascadeOnDelete();
            $table->unsignedBigInteger('account_id')->nullable();
            $table->text('description');
            $table->decimal('quantity', 12, 4)->default(1);
            $table->decimal('unit_price', 14, 2)->default(0);
            $table->decimal('discount_percent', 6, 2)->default(0);
            $table->unsignedBigInteger('tax_code_id')->nullable();
            $table->decimal('tax_amount', 14, 2)->default(0);
            $table->decimal('line_total', 14, 2)->default(0);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->foreign('account_id')->references('id')->on('acc_chart_of_accounts')->nullOnDelete();
            $table->foreign('tax_code_id')->references('id')->on('acc_tax_codes')->nullOnDelete();
        });

        if (!Schema::hasTable('acc_credit_notes')) Schema::create('acc_credit_notes', function (Blueprint $table) {
            $table->id();
            $table->string('company')->nullable();
            $table->foreignId('customer_id')->constrained('acc_customers')->restrictOnDelete();
            $table->unsignedBigInteger('invoice_id')->nullable();
            $table->string('credit_note_number', 30);
            $table->date('date');
            $table->string('reference')->nullable();
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('tax_total', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->enum('status', ['draft', 'applied', 'void'])->default('draft');
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->unique(['company', 'credit_note_number']);
            $table->foreign('invoice_id')->references('id')->on('acc_sales_invoices')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });

        if (!Schema::hasTable('acc_credit_note_items')) Schema::create('acc_credit_note_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('credit_note_id')->constrained('acc_credit_notes')->cascadeOnDelete();
            $table->unsignedBigInteger('account_id')->nullable();
            $table->text('description');
            $table->decimal('quantity', 12, 4)->default(1);
            $table->decimal('unit_price', 14, 2)->default(0);
            $table->unsignedBigInteger('tax_code_id')->nullable();
            $table->decimal('tax_amount', 14, 2)->default(0);
            $table->decimal('line_total', 14, 2)->default(0);
            $table->timestamps();

            $table->foreign('account_id')->references('id')->on('acc_chart_of_accounts')->nullOnDelete();
            $table->foreign('tax_code_id')->references('id')->on('acc_tax_codes')->nullOnDelete();
        });

        if (!Schema::hasTable('acc_customer_payments')) Schema::create('acc_customer_payments', function (Blueprint $table) {
            $table->id();
            $table->string('company')->nullable();
            $table->foreignId('customer_id')->constrained('acc_customers')->restrictOnDelete();
            $table->string('payment_number', 30);
            $table->date('date');
            $table->decimal('amount', 14, 2);
            $table->enum('payment_method', ['cash', 'bank_transfer', 'cheque', 'credit_card', 'online', 'other'])->default('bank_transfer');
            $table->string('reference')->nullable();
            $table->unsignedBigInteger('bank_account_id')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('journal_entry_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->unique(['company', 'payment_number']);
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('journal_entry_id')->references('id')->on('acc_journal_entries')->nullOnDelete();
        });

        if (!Schema::hasTable('acc_customer_payment_allocations')) Schema::create('acc_customer_payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_payment_id')->constrained('acc_customer_payments')->cascadeOnDelete();
            $table->unsignedBigInteger('invoice_id');
            $table->decimal('amount', 14, 2);
            $table->timestamps();

            $table->foreign('invoice_id')->references('id')->on('acc_sales_invoices')->restrictOnDelete();
        });

        // ══════════════════════════════════════════════════════════════════
        // 4. ACCOUNTS PAYABLE
        // ══════════════════════════════════════════════════════════════════

        if (!Schema::hasTable('acc_vendors')) Schema::create('acc_vendors', function (Blueprint $table) {
            $table->id();
            $table->string('company')->nullable();
            $table->string('vendor_code', 30);
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('address_line_1')->nullable();
            $table->string('address_line_2')->nullable();
            $table->string('city', 100)->nullable();
            $table->string('state', 100)->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->string('country', 100)->default('Malaysia');
            $table->string('tax_id', 50)->nullable();
            $table->unsignedSmallInteger('payment_terms_days')->default(30);
            $table->string('currency', 3)->default('MYR');
            $table->string('bank_name')->nullable();
            $table->string('bank_account_number', 50)->nullable();
            $table->string('bank_swift', 20)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company', 'vendor_code']);
        });

        if (!Schema::hasTable('acc_bills')) Schema::create('acc_bills', function (Blueprint $table) {
            $table->id();
            $table->string('company')->nullable();
            $table->foreignId('vendor_id')->constrained('acc_vendors')->restrictOnDelete();
            $table->string('bill_number', 30);
            $table->string('vendor_bill_number')->nullable();
            $table->date('date');
            $table->date('due_date');
            $table->string('reference')->nullable();
            $table->text('description')->nullable();
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('tax_total', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->decimal('amount_paid', 14, 2)->default(0);
            $table->decimal('balance_due', 14, 2)->default(0);
            $table->string('currency', 3)->default('MYR');
            $table->decimal('exchange_rate', 14, 6)->default(1);
            $table->enum('status', ['draft', 'received', 'partial', 'paid', 'overdue', 'void'])->default('draft');
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->unique(['company', 'bill_number']);
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('approved_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['company', 'status']);
        });

        if (!Schema::hasTable('acc_bill_items')) Schema::create('acc_bill_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bill_id')->constrained('acc_bills')->cascadeOnDelete();
            $table->unsignedBigInteger('account_id')->nullable();
            $table->text('description');
            $table->decimal('quantity', 12, 4)->default(1);
            $table->decimal('unit_price', 14, 2)->default(0);
            $table->unsignedBigInteger('tax_code_id')->nullable();
            $table->decimal('tax_amount', 14, 2)->default(0);
            $table->decimal('line_total', 14, 2)->default(0);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->foreign('account_id')->references('id')->on('acc_chart_of_accounts')->nullOnDelete();
            $table->foreign('tax_code_id')->references('id')->on('acc_tax_codes')->nullOnDelete();
        });

        if (!Schema::hasTable('acc_purchase_orders')) Schema::create('acc_purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->string('company')->nullable();
            $table->foreignId('vendor_id')->constrained('acc_vendors')->restrictOnDelete();
            $table->string('po_number', 30);
            $table->date('date');
            $table->date('expected_date')->nullable();
            $table->string('reference')->nullable();
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('tax_total', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->enum('status', ['draft', 'sent', 'partial', 'received', 'closed', 'cancelled'])->default('draft');
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamps();

            $table->unique(['company', 'po_number']);
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('approved_by')->references('id')->on('users')->nullOnDelete();
        });

        if (!Schema::hasTable('acc_purchase_order_items')) Schema::create('acc_purchase_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained('acc_purchase_orders')->cascadeOnDelete();
            $table->unsignedBigInteger('account_id')->nullable();
            $table->text('description');
            $table->decimal('quantity', 12, 4)->default(1);
            $table->decimal('unit_price', 14, 2)->default(0);
            $table->unsignedBigInteger('tax_code_id')->nullable();
            $table->decimal('tax_amount', 14, 2)->default(0);
            $table->decimal('line_total', 14, 2)->default(0);
            $table->decimal('received_quantity', 12, 4)->default(0);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->foreign('account_id')->references('id')->on('acc_chart_of_accounts')->nullOnDelete();
            $table->foreign('tax_code_id')->references('id')->on('acc_tax_codes')->nullOnDelete();
        });

        if (!Schema::hasTable('acc_vendor_payments')) Schema::create('acc_vendor_payments', function (Blueprint $table) {
            $table->id();
            $table->string('company')->nullable();
            $table->foreignId('vendor_id')->constrained('acc_vendors')->restrictOnDelete();
            $table->string('payment_number', 30);
            $table->date('date');
            $table->decimal('amount', 14, 2);
            $table->enum('payment_method', ['cash', 'bank_transfer', 'cheque', 'credit_card', 'online', 'other'])->default('bank_transfer');
            $table->string('reference')->nullable();
            $table->unsignedBigInteger('bank_account_id')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('journal_entry_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->unique(['company', 'payment_number']);
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('journal_entry_id')->references('id')->on('acc_journal_entries')->nullOnDelete();
        });

        if (!Schema::hasTable('acc_vendor_payment_allocations')) Schema::create('acc_vendor_payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_payment_id')->constrained('acc_vendor_payments')->cascadeOnDelete();
            $table->unsignedBigInteger('bill_id');
            $table->decimal('amount', 14, 2);
            $table->timestamps();

            $table->foreign('bill_id')->references('id')->on('acc_bills')->restrictOnDelete();
        });

        // ══════════════════════════════════════════════════════════════════
        // 5. BANKING & CASH MANAGEMENT
        // ══════════════════════════════════════════════════════════════════

        if (!Schema::hasTable('acc_bank_accounts')) Schema::create('acc_bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('company')->nullable();
            $table->string('account_name');
            $table->string('account_number', 50)->nullable();
            $table->string('bank_name')->nullable();
            $table->string('bank_branch')->nullable();
            $table->string('swift_code', 20)->nullable();
            $table->string('currency', 3)->default('MYR');
            $table->decimal('opening_balance', 14, 2)->default(0);
            $table->date('opening_balance_date')->nullable();
            $table->unsignedBigInteger('chart_of_account_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('chart_of_account_id')->references('id')->on('acc_chart_of_accounts')->nullOnDelete();
        });

        // Add FK for bank_account_id on payments now that bank_accounts exists
        try {
            Schema::table('acc_customer_payments', function (Blueprint $table) {
                $table->foreign('bank_account_id')->references('id')->on('acc_bank_accounts')->nullOnDelete();
            });
        } catch (\Exception $e) { /* FK already exists */ }
        try {
            Schema::table('acc_vendor_payments', function (Blueprint $table) {
                $table->foreign('bank_account_id')->references('id')->on('acc_bank_accounts')->nullOnDelete();
            });
        } catch (\Exception $e) { /* FK already exists */ }

        if (!Schema::hasTable('acc_bank_transactions')) Schema::create('acc_bank_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_account_id')->constrained('acc_bank_accounts')->cascadeOnDelete();
            $table->date('date');
            $table->text('description')->nullable();
            $table->string('reference')->nullable();
            $table->decimal('debit', 14, 2)->default(0);
            $table->decimal('credit', 14, 2)->default(0);
            $table->decimal('running_balance', 14, 2)->default(0);
            $table->boolean('is_reconciled')->default(false);
            $table->unsignedBigInteger('reconciliation_id')->nullable();
            $table->string('source_type', 50)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->timestamps();

            $table->index(['bank_account_id', 'date']);
            $table->index(['source_type', 'source_id']);
        });

        if (!Schema::hasTable('acc_bank_reconciliations')) Schema::create('acc_bank_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_account_id')->constrained('acc_bank_accounts')->cascadeOnDelete();
            $table->date('statement_date');
            $table->decimal('statement_balance', 14, 2)->default(0);
            $table->decimal('reconciled_balance', 14, 2)->default(0);
            $table->decimal('difference', 14, 2)->default(0);
            $table->enum('status', ['in_progress', 'completed'])->default('in_progress');
            $table->unsignedBigInteger('completed_by')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->foreign('completed_by')->references('id')->on('users')->nullOnDelete();
        });

        // FK for reconciliation_id now that reconciliations table exists
        try {
            Schema::table('acc_bank_transactions', function (Blueprint $table) {
                $table->foreign('reconciliation_id')->references('id')->on('acc_bank_reconciliations')->nullOnDelete();
            });
        } catch (\Exception $e) { /* FK already exists */ }

        if (!Schema::hasTable('acc_bank_transfers')) Schema::create('acc_bank_transfers', function (Blueprint $table) {
            $table->id();
            $table->string('company')->nullable();
            $table->unsignedBigInteger('from_bank_account_id');
            $table->unsignedBigInteger('to_bank_account_id');
            $table->decimal('amount', 14, 2);
            $table->date('date');
            $table->string('reference')->nullable();
            $table->text('description')->nullable();
            $table->unsignedBigInteger('journal_entry_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->foreign('from_bank_account_id')->references('id')->on('acc_bank_accounts')->restrictOnDelete();
            $table->foreign('to_bank_account_id')->references('id')->on('acc_bank_accounts')->restrictOnDelete();
            $table->foreign('journal_entry_id')->references('id')->on('acc_journal_entries')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });

        // ══════════════════════════════════════════════════════════════════
        // 6. TAX RETURNS
        // ══════════════════════════════════════════════════════════════════

        if (!Schema::hasTable('acc_tax_returns')) Schema::create('acc_tax_returns', function (Blueprint $table) {
            $table->id();
            $table->string('company')->nullable();
            $table->enum('return_type', ['sst02', 'cp204', 'cp207', 'wht', 'pcb_monthly']);
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('total_output_tax', 14, 2)->default(0);
            $table->decimal('total_input_tax', 14, 2)->default(0);
            $table->decimal('net_tax_payable', 14, 2)->default(0);
            $table->enum('status', ['draft', 'filed', 'paid'])->default('draft');
            $table->date('filed_date')->nullable();
            $table->date('due_date')->nullable();
            $table->date('payment_date')->nullable();
            $table->string('reference')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['company', 'return_type', 'period_start']);
        });

        if (!Schema::hasTable('acc_tax_return_lines')) Schema::create('acc_tax_return_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tax_return_id')->constrained('acc_tax_returns')->cascadeOnDelete();
            $table->unsignedBigInteger('tax_code_id')->nullable();
            $table->text('description')->nullable();
            $table->decimal('taxable_amount', 14, 2)->default(0);
            $table->decimal('tax_amount', 14, 2)->default(0);
            $table->timestamps();

            $table->foreign('tax_code_id')->references('id')->on('acc_tax_codes')->nullOnDelete();
        });

        // ══════════════════════════════════════════════════════════════════
        // 7. FIXED ASSETS & DEPRECIATION
        // ══════════════════════════════════════════════════════════════════

        if (!Schema::hasTable('acc_fixed_asset_categories')) Schema::create('acc_fixed_asset_categories', function (Blueprint $table) {
            $table->id();
            $table->string('company')->nullable();
            $table->string('name');
            $table->enum('depreciation_method', ['straight_line', 'declining_balance', 'sum_of_years'])->default('straight_line');
            $table->unsignedSmallInteger('useful_life_years')->default(5);
            $table->unsignedBigInteger('asset_account_id')->nullable();
            $table->unsignedBigInteger('depreciation_expense_account_id')->nullable();
            $table->unsignedBigInteger('accumulated_depreciation_account_id')->nullable();
            $table->timestamps();

            $table->foreign('asset_account_id')->references('id')->on('acc_chart_of_accounts')->nullOnDelete();
            $table->foreign('depreciation_expense_account_id')->references('id')->on('acc_chart_of_accounts')->nullOnDelete();
            $table->foreign('accumulated_depreciation_account_id')->references('id')->on('acc_chart_of_accounts')->nullOnDelete();
        });

        if (!Schema::hasTable('acc_fixed_assets')) Schema::create('acc_fixed_assets', function (Blueprint $table) {
            $table->id();
            $table->string('company')->nullable();
            $table->foreignId('category_id')->constrained('acc_fixed_asset_categories')->restrictOnDelete();
            $table->string('asset_code', 30);
            $table->string('name');
            $table->text('description')->nullable();
            $table->date('purchase_date');
            $table->decimal('purchase_cost', 14, 2);
            $table->decimal('residual_value', 14, 2)->default(0);
            $table->unsignedSmallInteger('useful_life_months');
            $table->enum('depreciation_method', ['straight_line', 'declining_balance', 'sum_of_years'])->default('straight_line');
            $table->enum('status', ['active', 'disposed', 'fully_depreciated'])->default('active');
            $table->date('disposal_date')->nullable();
            $table->decimal('disposal_amount', 14, 2)->default(0);
            $table->decimal('accumulated_depreciation', 14, 2)->default(0);
            $table->decimal('net_book_value', 14, 2)->default(0);
            $table->unsignedBigInteger('vendor_id')->nullable();
            $table->string('serial_number')->nullable();
            $table->string('location')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['company', 'asset_code']);
            $table->foreign('vendor_id')->references('id')->on('acc_vendors')->nullOnDelete();
        });

        if (!Schema::hasTable('acc_asset_depreciation_entries')) Schema::create('acc_asset_depreciation_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fixed_asset_id')->constrained('acc_fixed_assets')->cascadeOnDelete();
            $table->date('period_date');
            $table->decimal('depreciation_amount', 14, 2);
            $table->decimal('accumulated_depreciation', 14, 2);
            $table->decimal('net_book_value', 14, 2);
            $table->unsignedBigInteger('journal_entry_id')->nullable();
            $table->timestamps();

            $table->foreign('journal_entry_id')->references('id')->on('acc_journal_entries')->nullOnDelete();
        });

        // ══════════════════════════════════════════════════════════════════
        // 8. BUDGETS
        // ══════════════════════════════════════════════════════════════════

        if (!Schema::hasTable('acc_budgets')) Schema::create('acc_budgets', function (Blueprint $table) {
            $table->id();
            $table->string('company')->nullable();
            $table->string('name');
            $table->foreignId('fiscal_year_id')->constrained('acc_fiscal_years')->cascadeOnDelete();
            $table->enum('status', ['draft', 'active', 'closed'])->default('draft');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamps();

            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('approved_by')->references('id')->on('users')->nullOnDelete();
        });

        if (!Schema::hasTable('acc_budget_lines')) Schema::create('acc_budget_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('budget_id')->constrained('acc_budgets')->cascadeOnDelete();
            $table->unsignedBigInteger('account_id');
            $table->foreignId('fiscal_period_id')->constrained('acc_fiscal_periods')->cascadeOnDelete();
            $table->decimal('amount', 14, 2)->default(0);
            $table->timestamps();

            $table->foreign('account_id')->references('id')->on('acc_chart_of_accounts')->restrictOnDelete();
            $table->unique(['budget_id', 'account_id', 'fiscal_period_id']);
        });

        // ══════════════════════════════════════════════════════════════════
        // 9. RECURRING TEMPLATES
        // ══════════════════════════════════════════════════════════════════

        if (!Schema::hasTable('acc_recurring_templates')) Schema::create('acc_recurring_templates', function (Blueprint $table) {
            $table->id();
            $table->string('company')->nullable();
            $table->string('name');
            $table->enum('type', ['invoice', 'bill', 'journal']);
            $table->enum('frequency', ['daily', 'weekly', 'monthly', 'quarterly', 'yearly']);
            $table->date('next_run_date');
            $table->date('end_date')->nullable();
            $table->json('template_data');
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_run_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });

        // ══════════════════════════════════════════════════════════════════
        // 10. AI FEATURES
        // ══════════════════════════════════════════════════════════════════

        if (!Schema::hasTable('acc_ai_invoice_scans')) Schema::create('acc_ai_invoice_scans', function (Blueprint $table) {
            $table->id();
            $table->string('company')->nullable();
            $table->string('file_path');
            $table->string('file_name');
            $table->string('file_type', 30);
            $table->enum('status', ['pending', 'processing', 'completed', 'failed', 'reviewed'])->default('pending');
            $table->json('extracted_data')->nullable();
            $table->decimal('confidence_score', 5, 2)->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedBigInteger('bill_id')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->foreign('bill_id')->references('id')->on('acc_bills')->nullOnDelete();
            $table->foreign('reviewed_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });

        if (!Schema::hasTable('acc_ai_chat_sessions')) Schema::create('acc_ai_chat_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('company')->nullable();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('title')->default('New Conversation');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        if (!Schema::hasTable('acc_ai_chat_messages')) Schema::create('acc_ai_chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('acc_ai_chat_sessions')->cascadeOnDelete();
            $table->enum('role', ['user', 'assistant', 'system']);
            $table->text('content');
            $table->json('metadata')->nullable();
            $table->unsignedInteger('tokens_used')->default(0);
            $table->timestamps();
        });

        // ══════════════════════════════════════════════════════════════════
        // 11. AUDIT TRAIL
        // ══════════════════════════════════════════════════════════════════

        if (!Schema::hasTable('acc_audit_trail')) Schema::create('acc_audit_trail', function (Blueprint $table) {
            $table->id();
            $table->string('company')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->enum('action', ['create', 'update', 'delete', 'post', 'void', 'approve', 'print', 'export']);
            $table->string('auditable_type');
            $table->unsignedBigInteger('auditable_id');
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['auditable_type', 'auditable_id']);
            $table->index(['company', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acc_audit_trail');
        Schema::dropIfExists('acc_ai_chat_messages');
        Schema::dropIfExists('acc_ai_chat_sessions');
        Schema::dropIfExists('acc_ai_invoice_scans');
        Schema::dropIfExists('acc_recurring_templates');
        Schema::dropIfExists('acc_budget_lines');
        Schema::dropIfExists('acc_budgets');
        Schema::dropIfExists('acc_asset_depreciation_entries');
        Schema::dropIfExists('acc_fixed_assets');
        Schema::dropIfExists('acc_fixed_asset_categories');
        Schema::dropIfExists('acc_tax_return_lines');
        Schema::dropIfExists('acc_tax_returns');
        Schema::dropIfExists('acc_bank_transfers');
        Schema::dropIfExists('acc_bank_reconciliations');
        Schema::dropIfExists('acc_bank_transactions');

        // Drop FKs before dropping bank_accounts
        Schema::table('acc_customer_payments', function (Blueprint $table) {
            $table->dropForeign(['bank_account_id']);
        });
        Schema::table('acc_vendor_payments', function (Blueprint $table) {
            $table->dropForeign(['bank_account_id']);
        });

        Schema::dropIfExists('acc_bank_accounts');
        Schema::dropIfExists('acc_vendor_payment_allocations');
        Schema::dropIfExists('acc_vendor_payments');
        Schema::dropIfExists('acc_purchase_order_items');
        Schema::dropIfExists('acc_purchase_orders');
        Schema::dropIfExists('acc_bill_items');
        Schema::dropIfExists('acc_bills');
        Schema::dropIfExists('acc_vendors');
        Schema::dropIfExists('acc_customer_payment_allocations');
        Schema::dropIfExists('acc_customer_payments');
        Schema::dropIfExists('acc_credit_note_items');
        Schema::dropIfExists('acc_credit_notes');
        Schema::dropIfExists('acc_sales_invoice_items');
        Schema::dropIfExists('acc_sales_invoices');
        Schema::dropIfExists('acc_customers');
        Schema::dropIfExists('acc_journal_entry_lines');
        Schema::dropIfExists('acc_journal_entries');
        Schema::dropIfExists('acc_settings');
        Schema::dropIfExists('acc_tax_codes');
        Schema::dropIfExists('acc_chart_of_accounts');
        Schema::dropIfExists('acc_fiscal_periods');
        Schema::dropIfExists('acc_fiscal_years');
        Schema::dropIfExists('acc_currencies');

        // Revert role enum
        DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM(
            'hr_manager','hr_executive','hr_intern',
            'it_manager','it_executive','it_intern',
            'superadmin','system_admin',
            'employee'
        ) DEFAULT 'employee'");
    }
};
