<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AccountingSeeder extends Seeder
{
    public function run(): void
    {
        // ── Chart of Accounts (Malaysian Standard) ─────────────────────

        $accounts = [
            // Assets (1xxxx)
            ['account_code' => '10000', 'name' => 'Cash & Bank',           'type' => 'asset', 'sub_type' => 'current_asset', 'is_header' => true],
            ['account_code' => '10100', 'name' => 'Cash in Hand',          'type' => 'asset', 'sub_type' => 'current_asset', 'parent_code' => '10000'],
            ['account_code' => '10200', 'name' => 'Bank — Current Account','type' => 'asset', 'sub_type' => 'current_asset', 'parent_code' => '10000'],
            ['account_code' => '10300', 'name' => 'Bank — Savings Account','type' => 'asset', 'sub_type' => 'current_asset', 'parent_code' => '10000'],
            ['account_code' => '11000', 'name' => 'Accounts Receivable',   'type' => 'asset', 'sub_type' => 'current_asset'],
            ['account_code' => '11100', 'name' => 'Trade Receivables',     'type' => 'asset', 'sub_type' => 'current_asset', 'parent_code' => '11000'],
            ['account_code' => '11200', 'name' => 'Other Receivables',     'type' => 'asset', 'sub_type' => 'current_asset', 'parent_code' => '11000'],
            ['account_code' => '12000', 'name' => 'Inventory',             'type' => 'asset', 'sub_type' => 'current_asset'],
            ['account_code' => '13000', 'name' => 'Prepaid Expenses',      'type' => 'asset', 'sub_type' => 'current_asset'],
            ['account_code' => '14000', 'name' => 'Input Tax (SST)',       'type' => 'asset', 'sub_type' => 'current_asset'],
            ['account_code' => '15000', 'name' => 'Fixed Assets',          'type' => 'asset', 'sub_type' => 'fixed_asset', 'is_header' => true],
            ['account_code' => '15100', 'name' => 'Furniture & Fittings',  'type' => 'asset', 'sub_type' => 'fixed_asset', 'parent_code' => '15000'],
            ['account_code' => '15200', 'name' => 'Office Equipment',      'type' => 'asset', 'sub_type' => 'fixed_asset', 'parent_code' => '15000'],
            ['account_code' => '15300', 'name' => 'Computer Equipment',    'type' => 'asset', 'sub_type' => 'fixed_asset', 'parent_code' => '15000'],
            ['account_code' => '15400', 'name' => 'Motor Vehicles',        'type' => 'asset', 'sub_type' => 'fixed_asset', 'parent_code' => '15000'],
            ['account_code' => '15500', 'name' => 'Leasehold Improvements','type' => 'asset', 'sub_type' => 'fixed_asset', 'parent_code' => '15000'],
            ['account_code' => '16000', 'name' => 'Accumulated Depreciation','type' => 'asset', 'sub_type' => 'fixed_asset', 'is_header' => true],
            ['account_code' => '16100', 'name' => 'Accum Depr — Furniture','type' => 'asset', 'sub_type' => 'fixed_asset', 'parent_code' => '16000'],
            ['account_code' => '16200', 'name' => 'Accum Depr — Office Equip','type' => 'asset', 'sub_type' => 'fixed_asset', 'parent_code' => '16000'],
            ['account_code' => '16300', 'name' => 'Accum Depr — Computer', 'type' => 'asset', 'sub_type' => 'fixed_asset', 'parent_code' => '16000'],
            ['account_code' => '16400', 'name' => 'Accum Depr — Vehicles', 'type' => 'asset', 'sub_type' => 'fixed_asset', 'parent_code' => '16000'],

            // Liabilities (2xxxx)
            ['account_code' => '20000', 'name' => 'Accounts Payable',      'type' => 'liability', 'sub_type' => 'current_liability'],
            ['account_code' => '20100', 'name' => 'Trade Payables',        'type' => 'liability', 'sub_type' => 'current_liability', 'parent_code' => '20000'],
            ['account_code' => '20200', 'name' => 'Other Payables',        'type' => 'liability', 'sub_type' => 'current_liability', 'parent_code' => '20000'],
            ['account_code' => '21000', 'name' => 'Accrued Expenses',      'type' => 'liability', 'sub_type' => 'current_liability'],
            ['account_code' => '22000', 'name' => 'Output Tax (SST)',      'type' => 'liability', 'sub_type' => 'current_liability'],
            ['account_code' => '23000', 'name' => 'EPF Payable',           'type' => 'liability', 'sub_type' => 'current_liability'],
            ['account_code' => '23100', 'name' => 'SOCSO Payable',         'type' => 'liability', 'sub_type' => 'current_liability'],
            ['account_code' => '23200', 'name' => 'EIS Payable',           'type' => 'liability', 'sub_type' => 'current_liability'],
            ['account_code' => '23300', 'name' => 'PCB (Tax) Payable',     'type' => 'liability', 'sub_type' => 'current_liability'],
            ['account_code' => '24000', 'name' => 'Unearned Revenue',      'type' => 'liability', 'sub_type' => 'current_liability'],
            ['account_code' => '25000', 'name' => 'Long-term Loans',       'type' => 'liability', 'sub_type' => 'long_term_liability'],
            ['account_code' => '25100', 'name' => 'Hire Purchase Payable', 'type' => 'liability', 'sub_type' => 'long_term_liability'],

            // Equity (3xxxx)
            ['account_code' => '30000', 'name' => 'Share Capital',         'type' => 'equity', 'sub_type' => 'equity'],
            ['account_code' => '31000', 'name' => 'Retained Earnings',     'type' => 'equity', 'sub_type' => 'equity'],
            ['account_code' => '32000', 'name' => 'Current Year Earnings', 'type' => 'equity', 'sub_type' => 'equity'],
            ['account_code' => '33000', 'name' => 'Dividends',             'type' => 'equity', 'sub_type' => 'equity'],
            ['account_code' => '34000', 'name' => 'Owner Drawings',        'type' => 'equity', 'sub_type' => 'equity'],

            // Revenue (4xxxx)
            ['account_code' => '40000', 'name' => 'Sales Revenue',         'type' => 'revenue', 'sub_type' => 'operating_revenue'],
            ['account_code' => '40100', 'name' => 'Service Revenue',       'type' => 'revenue', 'sub_type' => 'operating_revenue'],
            ['account_code' => '41000', 'name' => 'Other Income',          'type' => 'revenue', 'sub_type' => 'other_revenue'],
            ['account_code' => '41100', 'name' => 'Interest Income',       'type' => 'revenue', 'sub_type' => 'other_revenue'],
            ['account_code' => '41200', 'name' => 'Gain on Disposal',      'type' => 'revenue', 'sub_type' => 'other_revenue'],
            ['account_code' => '42000', 'name' => 'Discount Received',     'type' => 'revenue', 'sub_type' => 'other_revenue'],

            // Cost of Goods Sold (5xxxx)
            ['account_code' => '50000', 'name' => 'Cost of Goods Sold',    'type' => 'expense', 'sub_type' => 'cost_of_sales'],
            ['account_code' => '50100', 'name' => 'Direct Materials',      'type' => 'expense', 'sub_type' => 'cost_of_sales'],
            ['account_code' => '50200', 'name' => 'Direct Labour',         'type' => 'expense', 'sub_type' => 'cost_of_sales'],
            ['account_code' => '50300', 'name' => 'Subcontractor Costs',   'type' => 'expense', 'sub_type' => 'cost_of_sales'],

            // Operating Expenses (6xxxx)
            ['account_code' => '60000', 'name' => 'Salary & Wages',        'type' => 'expense', 'sub_type' => 'operating_expense'],
            ['account_code' => '60100', 'name' => 'EPF — Employer',        'type' => 'expense', 'sub_type' => 'operating_expense'],
            ['account_code' => '60200', 'name' => 'SOCSO — Employer',      'type' => 'expense', 'sub_type' => 'operating_expense'],
            ['account_code' => '60300', 'name' => 'EIS — Employer',        'type' => 'expense', 'sub_type' => 'operating_expense'],
            ['account_code' => '61000', 'name' => 'Rental Expense',        'type' => 'expense', 'sub_type' => 'operating_expense'],
            ['account_code' => '62000', 'name' => 'Utilities',             'type' => 'expense', 'sub_type' => 'operating_expense'],
            ['account_code' => '62100', 'name' => 'Electricity',           'type' => 'expense', 'sub_type' => 'operating_expense', 'parent_code' => '62000'],
            ['account_code' => '62200', 'name' => 'Water',                 'type' => 'expense', 'sub_type' => 'operating_expense', 'parent_code' => '62000'],
            ['account_code' => '62300', 'name' => 'Internet & Telephone',  'type' => 'expense', 'sub_type' => 'operating_expense', 'parent_code' => '62000'],
            ['account_code' => '63000', 'name' => 'Office Supplies',       'type' => 'expense', 'sub_type' => 'operating_expense'],
            ['account_code' => '64000', 'name' => 'Depreciation Expense',  'type' => 'expense', 'sub_type' => 'operating_expense'],
            ['account_code' => '65000', 'name' => 'Insurance',             'type' => 'expense', 'sub_type' => 'operating_expense'],
            ['account_code' => '66000', 'name' => 'Professional Fees',     'type' => 'expense', 'sub_type' => 'operating_expense'],
            ['account_code' => '66100', 'name' => 'Accounting & Audit',    'type' => 'expense', 'sub_type' => 'operating_expense', 'parent_code' => '66000'],
            ['account_code' => '66200', 'name' => 'Legal Fees',            'type' => 'expense', 'sub_type' => 'operating_expense', 'parent_code' => '66000'],
            ['account_code' => '67000', 'name' => 'Travel & Entertainment','type' => 'expense', 'sub_type' => 'operating_expense'],
            ['account_code' => '68000', 'name' => 'Marketing & Advertising','type' => 'expense', 'sub_type' => 'operating_expense'],
            ['account_code' => '69000', 'name' => 'Bank Charges',          'type' => 'expense', 'sub_type' => 'operating_expense'],
            ['account_code' => '69100', 'name' => 'Interest Expense',      'type' => 'expense', 'sub_type' => 'operating_expense'],
            ['account_code' => '69200', 'name' => 'Bad Debt Expense',      'type' => 'expense', 'sub_type' => 'operating_expense'],
            ['account_code' => '69300', 'name' => 'Discount Given',        'type' => 'expense', 'sub_type' => 'operating_expense'],
            ['account_code' => '69500', 'name' => 'Withholding Tax Expense','type' => 'expense', 'sub_type' => 'operating_expense'],
            ['account_code' => '69900', 'name' => 'Miscellaneous Expense', 'type' => 'expense', 'sub_type' => 'operating_expense'],
        ];

        // Insert accounts and resolve parent IDs
        $idMap = [];
        foreach ($accounts as $acc) {
            $parentCode = $acc['parent_code'] ?? null;
            $isHeader = $acc['is_header'] ?? false;
            unset($acc['parent_code'], $acc['is_header']);

            $acc['parent_id'] = $parentCode ? ($idMap[$parentCode] ?? null) : null;
            $acc['is_active'] = true;
            $acc['is_system'] = false;
            $acc['allow_direct_posting'] = !$isHeader;
            $acc['opening_balance'] = 0;
            // Set normal balance based on account type
            $acc['normal_balance'] = in_array($acc['type'], ['asset', 'expense']) ? 'debit' : 'credit';
            $acc['created_at'] = now();
            $acc['updated_at'] = now();

            $id = DB::table('acc_chart_of_accounts')->insertGetId($acc);
            $idMap[$acc['account_code']] = $id;
        }

        // ── Tax Codes (Malaysian SST + WHT) ────────────────────────────

        $taxCodes = [
            ['code' => 'SST-S10', 'name' => 'SST Sales Tax 10%',       'rate' => 10.00, 'type' => 'sst_sales'],
            ['code' => 'SST-SV8', 'name' => 'SST Service Tax 8%',      'rate' => 8.00,  'type' => 'sst_service'],
            ['code' => 'SST-S5',  'name' => 'SST Sales Tax 5%',        'rate' => 5.00,  'type' => 'sst_sales'],
            ['code' => 'ZR',      'name' => 'Zero-Rated',              'rate' => 0.00,  'type' => 'zero_rated'],
            ['code' => 'ES',      'name' => 'Exempt Supply',           'rate' => 0.00,  'type' => 'exempt'],
            ['code' => 'OS',      'name' => 'Out of Scope',            'rate' => 0.00,  'type' => 'out_of_scope'],
            ['code' => 'WHT-10',  'name' => 'Withholding Tax 10%',     'rate' => 10.00, 'type' => 'wht'],
            ['code' => 'WHT-15',  'name' => 'Withholding Tax 15%',     'rate' => 15.00, 'type' => 'wht'],
            ['code' => 'WHT-10S', 'name' => 'WHT 10% (Contract)',      'rate' => 10.00, 'type' => 'wht'],
        ];

        foreach ($taxCodes as $tc) {
            $tc['is_active'] = true;
            $tc['created_at'] = now();
            $tc['updated_at'] = now();
            DB::table('acc_tax_codes')->insert($tc);
        }

        // ── Currencies ─────────────────────────────────────────────────

        DB::table('acc_currencies')->insert([
            ['code' => 'MYR', 'name' => 'Malaysian Ringgit', 'symbol' => 'RM',  'exchange_rate' => 1.000000, 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'USD', 'name' => 'US Dollar',         'symbol' => '$',   'exchange_rate' => 0.213000, 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'SGD', 'name' => 'Singapore Dollar',  'symbol' => 'S$',  'exchange_rate' => 0.286000, 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'GBP', 'name' => 'British Pound',     'symbol' => '£',   'exchange_rate' => 0.169000, 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'EUR', 'name' => 'Euro',              'symbol' => '€',   'exchange_rate' => 0.197000, 'created_at' => now(), 'updated_at' => now()],
        ]);

        // ── Default Settings ───────────────────────────────────────────

        DB::table('acc_settings')->insert([
            'company' => 'default',
            'fiscal_year_start_month' => 1,
            'base_currency' => 'MYR',
            'invoice_prefix' => 'INV-',
            'bill_prefix' => 'BILL-',
            'journal_prefix' => 'JE-',
            'payment_prefix' => 'PAY-',
            'credit_note_prefix' => 'CN-',
            'po_prefix' => 'PO-',
            'receipt_prefix' => 'REC-',
            'ai_provider' => 'openai',
            'ai_model' => 'gpt-4o',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // ── Fixed Asset Categories ─────────────────────────────────────

        $assetCategories = [
            ['name' => 'Furniture & Fittings',  'depreciation_method' => 'straight_line', 'asset_account_id' => $idMap['15100'] ?? null, 'depreciation_expense_account_id' => $idMap['16100'] ?? null],
            ['name' => 'Office Equipment',      'depreciation_method' => 'straight_line', 'asset_account_id' => $idMap['15200'] ?? null, 'depreciation_expense_account_id' => $idMap['16200'] ?? null],
            ['name' => 'Computer Equipment',    'depreciation_method' => 'straight_line', 'asset_account_id' => $idMap['15300'] ?? null, 'depreciation_expense_account_id' => $idMap['16300'] ?? null],
            ['name' => 'Motor Vehicles',        'depreciation_method' => 'straight_line', 'asset_account_id' => $idMap['15400'] ?? null, 'depreciation_expense_account_id' => $idMap['16400'] ?? null],
            ['name' => 'Leasehold Improvements','depreciation_method' => 'straight_line', 'asset_account_id' => $idMap['15500'] ?? null, 'depreciation_expense_account_id' => null],
        ];

        foreach ($assetCategories as $cat) {
            $cat['created_at'] = now();
            $cat['updated_at'] = now();
            DB::table('acc_fixed_asset_categories')->insert($cat);
        }
    }
}
