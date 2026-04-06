<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\Accounting\{AccountingSetting, AccountingAuditTrail, BankAccount, Bill, BillItem, ChartOfAccount, PurchaseOrder, PurchaseOrderItem, TaxCode, Vendor, VendorPayment, VendorPaymentAllocation};
use App\Services\AccountingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class AccountsPayableController extends Controller
{
    private function authorizeView(): void  { if (!Auth::user()->canViewAccounting()) abort(403); }
    private function authorizeManage(): void { if (!Auth::user()->canManageAccounting()) abort(403); }

    // ── Vendors ───────────────────────────────────────────────────
    public function vendors(Request $request)
    {
        $this->authorizeView();
        $company = $request->get('company');
        $vendors = Vendor::when($company, fn($q) => $q->where('company', $company))
            ->withCount('bills')
            ->orderBy('name')
            ->paginate(25);
        $companies = \App\Models\Company::orderBy('name')->pluck('name', 'name');
        return view('accounting.payables.vendors', compact('vendors', 'company', 'companies'));
    }

    public function createVendor()
    {
        $this->authorizeManage();
        $companies = \App\Models\Company::orderBy('name')->pluck('name', 'name');
        return view('accounting.payables.vendor-form', compact('companies'));
    }

    public function storeVendor(Request $request)
    {
        $this->authorizeManage();
        $data = $request->validate([
            'company'             => 'nullable|string|max:255',
            'vendor_code'         => 'required|string|max:30',
            'name'                => 'required|string|max:255',
            'email'               => 'nullable|email|max:255',
            'phone'               => 'nullable|string|max:30',
            'address_line_1'      => 'nullable|string|max:255',
            'address_line_2'      => 'nullable|string|max:255',
            'city'                => 'nullable|string|max:100',
            'state'               => 'nullable|string|max:100',
            'postal_code'         => 'nullable|string|max:20',
            'tax_id'              => 'nullable|string|max:50',
            'payment_terms_days'  => 'nullable|integer|min:0|max:365',
            'bank_name'           => 'nullable|string|max:255',
            'bank_account_number' => 'nullable|string|max:50',
            'bank_swift'          => 'nullable|string|max:20',
        ]);

        $vendor = Vendor::create($data);
        AccountingAuditTrail::log('create', $vendor);

        return redirect()->route('accounting.vendors.index', ['company' => $data['company']])
            ->with('success', "Vendor {$vendor->name} created.");
    }

    public function editVendor(Vendor $vendor)
    {
        $this->authorizeManage();
        $companies = \App\Models\Company::orderBy('name')->pluck('name', 'name');
        return view('accounting.payables.vendor-form', compact('vendor', 'companies'));
    }

    public function updateVendor(Request $request, Vendor $vendor)
    {
        $this->authorizeManage();
        $data = $request->validate([
            'name'                => 'required|string|max:255',
            'email'               => 'nullable|email|max:255',
            'phone'               => 'nullable|string|max:30',
            'address_line_1'      => 'nullable|string|max:255',
            'address_line_2'      => 'nullable|string|max:255',
            'city'                => 'nullable|string|max:100',
            'state'               => 'nullable|string|max:100',
            'postal_code'         => 'nullable|string|max:20',
            'tax_id'              => 'nullable|string|max:50',
            'payment_terms_days'  => 'nullable|integer|min:0|max:365',
            'bank_name'           => 'nullable|string|max:255',
            'bank_account_number' => 'nullable|string|max:50',
            'is_active'           => 'boolean',
        ]);
        $data['is_active'] = $request->boolean('is_active', true);
        $vendor->update($data);
        return redirect()->route('accounting.vendors.index')->with('success', 'Vendor updated.');
    }

    // ── Bills ─────────────────────────────────────────────────────
    public function bills(Request $request)
    {
        $this->authorizeView();
        $company = $request->get('company');
        $status  = $request->get('status');

        $bills = Bill::when($company, fn($q) => $q->where('company', $company))
            ->when($status, fn($q) => $q->where('status', $status))
            ->with('vendor')
            ->latest('date')
            ->paginate(25);

        $companies = \App\Models\Company::orderBy('name')->pluck('name', 'name');
        return view('accounting.payables.bills', compact('bills', 'company', 'status', 'companies'));
    }

    public function createBill()
    {
        $this->authorizeManage();
        $vendors   = Vendor::where('is_active', true)->orderBy('name')->get();
        $accounts  = ChartOfAccount::where('is_active', true)->where('type', 'expense')->orderBy('account_code')->get();
        $taxCodes  = TaxCode::where('is_active', true)->orderBy('code')->get();
        $companies = \App\Models\Company::orderBy('name')->pluck('name', 'name');
        return view('accounting.payables.bill-form', compact('vendors', 'accounts', 'taxCodes', 'companies'));
    }

    public function storeBill(Request $request)
    {
        $this->authorizeManage();

        $data = $request->validate([
            'company'                => 'nullable|string|max:255',
            'vendor_id'              => 'required|exists:acc_vendors,id',
            'date'                   => 'required|date',
            'due_date'               => 'required|date|after_or_equal:date',
            'vendor_bill_number'     => 'nullable|string|max:255',
            'reference'              => 'nullable|string|max:255',
            'description'            => 'nullable|string|max:1000',
            'notes'                  => 'nullable|string|max:1000',
            'items'                  => 'required|array|min:1',
            'items.*.description'    => 'required|string|max:500',
            'items.*.quantity'       => 'required|numeric|min:0.0001',
            'items.*.unit_price'     => 'required|numeric|min:0',
            'items.*.account_id'     => 'nullable|exists:acc_chart_of_accounts,id',
            'items.*.tax_code_id'    => 'nullable|exists:acc_tax_codes,id',
        ]);

        return DB::transaction(function () use ($data) {
            $settings = AccountingSetting::where('company', $data['company'])->first();
            $billNumber = $settings
                ? $settings->getNextNumber('bill')
                : 'BILL-' . str_pad(Bill::count() + 1, 6, '0', STR_PAD_LEFT);

            $bill = Bill::create([
                'company'            => $data['company'],
                'vendor_id'          => $data['vendor_id'],
                'bill_number'        => $billNumber,
                'vendor_bill_number' => $data['vendor_bill_number'] ?? null,
                'date'               => $data['date'],
                'due_date'           => $data['due_date'],
                'reference'          => $data['reference'] ?? null,
                'description'        => $data['description'] ?? null,
                'notes'              => $data['notes'] ?? null,
                'status'             => 'draft',
                'created_by'         => Auth::id(),
            ]);

            foreach ($data['items'] as $i => $item) {
                $lineTotal = $item['quantity'] * $item['unit_price'];
                $taxCode = isset($item['tax_code_id']) ? TaxCode::find($item['tax_code_id']) : null;
                $taxAmount = $taxCode ? $lineTotal * ($taxCode->rate / 100) : 0;

                BillItem::create([
                    'bill_id'     => $bill->id,
                    'account_id'  => $item['account_id'] ?? null,
                    'description' => $item['description'],
                    'quantity'    => $item['quantity'],
                    'unit_price'  => $item['unit_price'],
                    'tax_code_id' => $item['tax_code_id'] ?? null,
                    'tax_amount'  => round($taxAmount, 2),
                    'line_total'  => round($lineTotal, 2),
                    'sort_order'  => $i,
                ]);
            }

            $bill->recalculateTotals();
            AccountingAuditTrail::log('create', $bill);

            return redirect()->route('accounting.bills.show', $bill)
                ->with('success', "Bill {$bill->bill_number} created.");
        });
    }

    public function showBill(Bill $bill)
    {
        $this->authorizeView();
        $bill->load('vendor', 'items.account', 'items.taxCode', 'createdByUser');
        return view('accounting.payables.bill-show', compact('bill'));
    }

    public function approveBill(Bill $bill)
    {
        if (!Auth::user()->canApproveTransactions()) abort(403);
        if ($bill->status !== 'draft') return back()->with('error', 'Only draft bills can be approved.');

        $bill->update([
            'status'      => 'received',
            'approved_by' => Auth::id(),
            'approved_at' => now(),
        ]);

        return back()->with('success', 'Bill approved.');
    }

    // ── Vendor Payments ───────────────────────────────────────────
    public function payments(Request $request)
    {
        $this->authorizeView();
        $company = $request->get('company');
        $payments = VendorPayment::when($company, fn($q) => $q->where('company', $company))
            ->with('vendor')
            ->latest('date')
            ->paginate(25);
        $companies = \App\Models\Company::orderBy('name')->pluck('name', 'name');
        return view('accounting.payables.payments', compact('payments', 'company', 'companies'));
    }

    public function storePayment(Request $request, AccountingService $svc)
    {
        $this->authorizeManage();

        $data = $request->validate([
            'company'         => 'nullable|string|max:255',
            'vendor_id'       => 'required|exists:acc_vendors,id',
            'date'            => 'required|date',
            'amount'          => 'required|numeric|min:0.01',
            'payment_method'  => 'required|in:cash,bank_transfer,cheque,credit_card,online,other',
            'reference'       => 'nullable|string|max:255',
            'bank_account_id' => 'nullable|exists:acc_bank_accounts,id',
            'allocations'     => 'nullable|array',
            'allocations.*.bill_id' => 'required|exists:acc_bills,id',
            'allocations.*.amount'  => 'required|numeric|min:0.01',
        ]);

        return DB::transaction(function () use ($data, $svc) {
            $settings = AccountingSetting::where('company', $data['company'])->first();
            $paymentNumber = $settings
                ? $settings->getNextNumber('payment')
                : 'PAY-' . str_pad(VendorPayment::count() + 1, 6, '0', STR_PAD_LEFT);

            $payment = VendorPayment::create([
                'company'         => $data['company'],
                'vendor_id'       => $data['vendor_id'],
                'payment_number'  => $paymentNumber,
                'date'            => $data['date'],
                'amount'          => $data['amount'],
                'payment_method'  => $data['payment_method'],
                'reference'       => $data['reference'] ?? null,
                'bank_account_id' => $data['bank_account_id'] ?? null,
                'created_by'      => Auth::id(),
            ]);

            if (!empty($data['allocations'])) {
                foreach ($data['allocations'] as $alloc) {
                    VendorPaymentAllocation::create([
                        'vendor_payment_id' => $payment->id,
                        'bill_id'           => $alloc['bill_id'],
                        'amount'            => $alloc['amount'],
                    ]);

                    $bill = Bill::find($alloc['bill_id']);
                    $bill->amount_paid += $alloc['amount'];
                    $bill->balance_due = $bill->total - $bill->amount_paid;
                    $bill->status = $bill->balance_due <= 0 ? 'paid' : 'partial';
                    $bill->save();
                }
            }

            if ($payment->bank_account_id) {
                $svc->recordBankTransaction($payment->bank_account_id, [
                    'date'        => $payment->date,
                    'description' => "Payment to " . ($payment->vendor->name ?? ''),
                    'reference'   => $payment->payment_number,
                    'debit'       => 0,
                    'credit'      => $payment->amount,
                    'source_type' => VendorPayment::class,
                    'source_id'   => $payment->id,
                ]);
            }

            return redirect()->route('accounting.vendor-payments.index')
                ->with('success', "Payment {$payment->payment_number} recorded.");
        });
    }

    // ── Purchase Orders ───────────────────────────────────────────
    public function purchaseOrders(Request $request)
    {
        $this->authorizeView();
        $company = $request->get('company');
        $orders = PurchaseOrder::when($company, fn($q) => $q->where('company', $company))
            ->with('vendor')
            ->latest('date')
            ->paginate(25);
        $companies = \App\Models\Company::orderBy('name')->pluck('name', 'name');
        return view('accounting.payables.purchase-orders', compact('orders', 'company', 'companies'));
    }
}
