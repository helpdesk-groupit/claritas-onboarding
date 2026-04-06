<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\Accounting\{AccountingSetting, AccountingAuditTrail, ChartOfAccount, Customer, CustomerPayment, CustomerPaymentAllocation, CreditNote, CreditNoteItem, SalesInvoice, SalesInvoiceItem, TaxCode, BankAccount};
use App\Services\AccountingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class AccountsReceivableController extends Controller
{
    private function authorizeView(): void  { if (!Auth::user()->canViewAccounting()) abort(403); }
    private function authorizeManage(): void { if (!Auth::user()->canManageAccounting()) abort(403); }

    // ── Customers ─────────────────────────────────────────────────
    public function customers(Request $request)
    {
        $this->authorizeView();
        $company = $request->get('company');
        $customers = Customer::when($company, fn($q) => $q->where('company', $company))
            ->withCount('invoices')
            ->orderBy('name')
            ->paginate(25);
        $companies = \App\Models\Company::orderBy('name')->pluck('name', 'name');
        return view('accounting.receivables.customers', compact('customers', 'company', 'companies'));
    }

    public function createCustomer()
    {
        $this->authorizeManage();
        $companies = \App\Models\Company::orderBy('name')->pluck('name', 'name');
        return view('accounting.receivables.customer-form', compact('companies'));
    }

    public function storeCustomer(Request $request)
    {
        $this->authorizeManage();
        $data = $request->validate([
            'company'            => 'nullable|string|max:255',
            'customer_code'      => 'required|string|max:30',
            'name'               => 'required|string|max:255',
            'email'              => 'nullable|email|max:255',
            'phone'              => 'nullable|string|max:30',
            'address_line_1'     => 'nullable|string|max:255',
            'address_line_2'     => 'nullable|string|max:255',
            'city'               => 'nullable|string|max:100',
            'state'              => 'nullable|string|max:100',
            'postal_code'        => 'nullable|string|max:20',
            'country'            => 'nullable|string|max:100',
            'tax_id'             => 'nullable|string|max:50',
            'credit_limit'       => 'nullable|numeric|min:0',
            'payment_terms_days' => 'nullable|integer|min:0|max:365',
        ]);

        $customer = Customer::create($data);
        AccountingAuditTrail::log('create', $customer);

        return redirect()->route('accounting.customers.index', ['company' => $data['company']])
            ->with('success', "Customer {$customer->name} created.");
    }

    public function editCustomer(Customer $customer)
    {
        $this->authorizeManage();
        $companies = \App\Models\Company::orderBy('name')->pluck('name', 'name');
        return view('accounting.receivables.customer-form', compact('customer', 'companies'));
    }

    public function updateCustomer(Request $request, Customer $customer)
    {
        $this->authorizeManage();
        $data = $request->validate([
            'name'               => 'required|string|max:255',
            'email'              => 'nullable|email|max:255',
            'phone'              => 'nullable|string|max:30',
            'address_line_1'     => 'nullable|string|max:255',
            'address_line_2'     => 'nullable|string|max:255',
            'city'               => 'nullable|string|max:100',
            'state'              => 'nullable|string|max:100',
            'postal_code'        => 'nullable|string|max:20',
            'tax_id'             => 'nullable|string|max:50',
            'credit_limit'       => 'nullable|numeric|min:0',
            'payment_terms_days' => 'nullable|integer|min:0|max:365',
            'is_active'          => 'boolean',
        ]);
        $data['is_active'] = $request->boolean('is_active', true);
        $customer->update($data);
        return redirect()->route('accounting.customers.index')->with('success', 'Customer updated.');
    }

    // ── Invoices ──────────────────────────────────────────────────
    public function invoices(Request $request)
    {
        $this->authorizeView();
        $company = $request->get('company');
        $status  = $request->get('status');

        $invoices = SalesInvoice::when($company, fn($q) => $q->where('company', $company))
            ->when($status, fn($q) => $q->where('status', $status))
            ->with('customer')
            ->latest('date')
            ->paginate(25);

        $companies = \App\Models\Company::orderBy('name')->pluck('name', 'name');
        return view('accounting.receivables.invoices', compact('invoices', 'company', 'status', 'companies'));
    }

    public function createInvoice()
    {
        $this->authorizeManage();
        $customers = Customer::where('is_active', true)->orderBy('name')->get();
        $accounts  = ChartOfAccount::where('is_active', true)->where('type', 'revenue')->orderBy('account_code')->get();
        $taxCodes  = TaxCode::where('is_active', true)->orderBy('code')->get();
        $companies = \App\Models\Company::orderBy('name')->pluck('name', 'name');
        return view('accounting.receivables.invoice-form', compact('customers', 'accounts', 'taxCodes', 'companies'));
    }

    public function storeInvoice(Request $request)
    {
        $this->authorizeManage();

        $data = $request->validate([
            'company'                  => 'nullable|string|max:255',
            'customer_id'              => 'required|exists:acc_customers,id',
            'date'                     => 'required|date',
            'due_date'                 => 'required|date|after_or_equal:date',
            'reference'                => 'nullable|string|max:255',
            'description'              => 'nullable|string|max:1000',
            'notes'                    => 'nullable|string|max:1000',
            'terms'                    => 'nullable|string|max:1000',
            'items'                    => 'required|array|min:1',
            'items.*.description'      => 'required|string|max:500',
            'items.*.quantity'         => 'required|numeric|min:0.0001',
            'items.*.unit_price'       => 'required|numeric|min:0',
            'items.*.account_id'       => 'nullable|exists:acc_chart_of_accounts,id',
            'items.*.tax_code_id'      => 'nullable|exists:acc_tax_codes,id',
            'items.*.discount_percent' => 'nullable|numeric|min:0|max:100',
        ]);

        return DB::transaction(function () use ($data) {
            $settings = AccountingSetting::where('company', $data['company'])->first();
            $invoiceNumber = $settings
                ? $settings->getNextNumber('invoice')
                : 'INV-' . str_pad(SalesInvoice::count() + 1, 6, '0', STR_PAD_LEFT);

            $invoice = SalesInvoice::create([
                'company'        => $data['company'],
                'customer_id'    => $data['customer_id'],
                'invoice_number' => $invoiceNumber,
                'date'           => $data['date'],
                'due_date'       => $data['due_date'],
                'reference'      => $data['reference'] ?? null,
                'description'    => $data['description'] ?? null,
                'notes'          => $data['notes'] ?? null,
                'terms'          => $data['terms'] ?? null,
                'status'         => 'draft',
                'created_by'     => Auth::id(),
            ]);

            foreach ($data['items'] as $i => $item) {
                $discount = ($item['discount_percent'] ?? 0) / 100;
                $lineSubtotal = $item['quantity'] * $item['unit_price'] * (1 - $discount);
                $taxCode = isset($item['tax_code_id']) ? TaxCode::find($item['tax_code_id']) : null;
                $taxAmount = $taxCode ? $lineSubtotal * ($taxCode->rate / 100) : 0;

                SalesInvoiceItem::create([
                    'sales_invoice_id' => $invoice->id,
                    'account_id'       => $item['account_id'] ?? null,
                    'description'      => $item['description'],
                    'quantity'         => $item['quantity'],
                    'unit_price'       => $item['unit_price'],
                    'discount_percent' => $item['discount_percent'] ?? 0,
                    'tax_code_id'      => $item['tax_code_id'] ?? null,
                    'tax_amount'       => round($taxAmount, 2),
                    'line_total'       => round($lineSubtotal, 2),
                    'sort_order'       => $i,
                ]);
            }

            $invoice->recalculateTotals();
            AccountingAuditTrail::log('create', $invoice);

            return redirect()->route('accounting.invoices.show', $invoice)
                ->with('success', "Invoice {$invoice->invoice_number} created.");
        });
    }

    public function showInvoice(SalesInvoice $invoice)
    {
        $this->authorizeView();
        $invoice->load('customer', 'items.account', 'items.taxCode', 'createdByUser', 'approvedByUser');
        return view('accounting.receivables.invoice-show', compact('invoice'));
    }

    public function sendInvoice(SalesInvoice $invoice)
    {
        $this->authorizeManage();
        if ($invoice->status === 'draft') {
            $invoice->update(['status' => 'sent']);
        }
        return back()->with('success', 'Invoice marked as sent.');
    }

    // ── Customer Payments (Receipts) ──────────────────────────────
    public function payments(Request $request)
    {
        $this->authorizeView();
        $company = $request->get('company');
        $payments = CustomerPayment::when($company, fn($q) => $q->where('company', $company))
            ->with('customer')
            ->latest('date')
            ->paginate(25);
        $companies = \App\Models\Company::orderBy('name')->pluck('name', 'name');
        return view('accounting.receivables.payments', compact('payments', 'company', 'companies'));
    }

    public function createPayment()
    {
        $this->authorizeManage();
        $customers    = Customer::where('is_active', true)->orderBy('name')->get();
        $bankAccounts = BankAccount::where('is_active', true)->orderBy('account_name')->get();
        $companies    = \App\Models\Company::orderBy('name')->pluck('name', 'name');
        return view('accounting.receivables.payment-form', compact('customers', 'bankAccounts', 'companies'));
    }

    public function storePayment(Request $request, AccountingService $svc)
    {
        $this->authorizeManage();

        $data = $request->validate([
            'company'         => 'nullable|string|max:255',
            'customer_id'     => 'required|exists:acc_customers,id',
            'date'            => 'required|date',
            'amount'          => 'required|numeric|min:0.01',
            'payment_method'  => 'required|in:cash,bank_transfer,cheque,credit_card,online,other',
            'reference'       => 'nullable|string|max:255',
            'bank_account_id' => 'nullable|exists:acc_bank_accounts,id',
            'notes'           => 'nullable|string|max:1000',
            'allocations'     => 'nullable|array',
            'allocations.*.invoice_id' => 'required|exists:acc_sales_invoices,id',
            'allocations.*.amount'     => 'required|numeric|min:0.01',
        ]);

        return DB::transaction(function () use ($data, $svc) {
            $settings = AccountingSetting::where('company', $data['company'])->first();
            $paymentNumber = $settings
                ? $settings->getNextNumber('receipt')
                : 'REC-' . str_pad(CustomerPayment::count() + 1, 6, '0', STR_PAD_LEFT);

            $payment = CustomerPayment::create([
                'company'         => $data['company'],
                'customer_id'     => $data['customer_id'],
                'payment_number'  => $paymentNumber,
                'date'            => $data['date'],
                'amount'          => $data['amount'],
                'payment_method'  => $data['payment_method'],
                'reference'       => $data['reference'] ?? null,
                'bank_account_id' => $data['bank_account_id'] ?? null,
                'notes'           => $data['notes'] ?? null,
                'created_by'      => Auth::id(),
            ]);

            // Allocate to invoices
            if (!empty($data['allocations'])) {
                foreach ($data['allocations'] as $alloc) {
                    CustomerPaymentAllocation::create([
                        'customer_payment_id' => $payment->id,
                        'invoice_id'          => $alloc['invoice_id'],
                        'amount'              => $alloc['amount'],
                    ]);

                    $invoice = SalesInvoice::find($alloc['invoice_id']);
                    $invoice->amount_paid += $alloc['amount'];
                    $invoice->balance_due = $invoice->total - $invoice->amount_paid;
                    $invoice->status = $invoice->balance_due <= 0 ? 'paid' : 'partial';
                    $invoice->save();
                }
            }

            // Record bank transaction
            if ($payment->bank_account_id) {
                $svc->recordBankTransaction($payment->bank_account_id, [
                    'date'        => $payment->date,
                    'description' => "Receipt from " . ($payment->customer->name ?? ''),
                    'reference'   => $payment->payment_number,
                    'debit'       => $payment->amount,
                    'credit'      => 0,
                    'source_type' => CustomerPayment::class,
                    'source_id'   => $payment->id,
                ]);
            }

            AccountingAuditTrail::log('create', $payment);

            return redirect()->route('accounting.customer-payments.index')
                ->with('success', "Payment {$payment->payment_number} recorded.");
        });
    }

    // ── Credit Notes ──────────────────────────────────────────────
    public function creditNotes(Request $request)
    {
        $this->authorizeView();
        $company = $request->get('company');
        $notes = CreditNote::when($company, fn($q) => $q->where('company', $company))
            ->with('customer')
            ->latest('date')
            ->paginate(25);
        $companies = \App\Models\Company::orderBy('name')->pluck('name', 'name');
        return view('accounting.receivables.credit-notes', compact('notes', 'company', 'companies'));
    }
}
