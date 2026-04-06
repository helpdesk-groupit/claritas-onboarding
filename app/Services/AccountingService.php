<?php

namespace App\Services;

use App\Models\Accounting\AccountingSetting;
use App\Models\Accounting\AccountingAuditTrail;
use App\Models\Accounting\BankAccount;
use App\Models\Accounting\BankTransaction;
use App\Models\Accounting\ChartOfAccount;
use App\Models\Accounting\JournalEntry;
use App\Models\Accounting\JournalEntryLine;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class AccountingService
{
    /**
     * Create and optionally post a journal entry with balanced debit/credit lines.
     */
    public function createJournalEntry(array $header, array $lines, bool $autoPost = false): JournalEntry
    {
        return DB::transaction(function () use ($header, $lines, $autoPost) {
            $settings = AccountingSetting::where('company', $header['company'] ?? null)->first();
            $entryNumber = $settings
                ? $settings->getNextNumber('journal')
                : 'JE-' . str_pad(JournalEntry::count() + 1, 6, '0', STR_PAD_LEFT);

            $entry = JournalEntry::create([
                'company'      => $header['company'] ?? null,
                'entry_number' => $entryNumber,
                'date'         => $header['date'],
                'reference'    => $header['reference'] ?? null,
                'description'  => $header['description'] ?? null,
                'source_type'  => $header['source_type'] ?? null,
                'source_id'    => $header['source_id'] ?? null,
                'status'       => 'draft',
                'created_by'   => Auth::id(),
            ]);

            $totalDebit = 0;
            $totalCredit = 0;

            foreach ($lines as $line) {
                JournalEntryLine::create([
                    'journal_entry_id' => $entry->id,
                    'account_id'       => $line['account_id'],
                    'description'      => $line['description'] ?? null,
                    'debit'            => $line['debit'] ?? 0,
                    'credit'           => $line['credit'] ?? 0,
                    'tax_code_id'      => $line['tax_code_id'] ?? null,
                    'tax_amount'       => $line['tax_amount'] ?? 0,
                ]);

                $totalDebit  += $line['debit'] ?? 0;
                $totalCredit += $line['credit'] ?? 0;
            }

            if (abs($totalDebit - $totalCredit) > 0.01) {
                throw new \RuntimeException("Journal entry is not balanced: Debit=$totalDebit, Credit=$totalCredit");
            }

            if ($autoPost) {
                $this->postJournalEntry($entry);
            }

            AccountingAuditTrail::log('create', $entry);

            return $entry;
        });
    }

    /**
     * Post a draft journal entry.
     */
    public function postJournalEntry(JournalEntry $entry): void
    {
        if ($entry->status !== 'draft') {
            throw new \RuntimeException('Only draft entries can be posted.');
        }

        if (!$entry->isBalanced()) {
            throw new \RuntimeException('Cannot post an unbalanced journal entry.');
        }

        $entry->update([
            'status'    => 'posted',
            'posted_by' => Auth::id(),
            'posted_at' => now(),
        ]);

        AccountingAuditTrail::log('post', $entry);
    }

    /**
     * Void a posted journal entry by creating a reversing entry.
     */
    public function voidJournalEntry(JournalEntry $entry): JournalEntry
    {
        if ($entry->status !== 'posted') {
            throw new \RuntimeException('Only posted entries can be voided.');
        }

        return DB::transaction(function () use ($entry) {
            $reversingLines = [];
            foreach ($entry->lines as $line) {
                $reversingLines[] = [
                    'account_id'  => $line->account_id,
                    'description' => 'Reversal: ' . ($line->description ?? ''),
                    'debit'       => $line->credit,
                    'credit'      => $line->debit,
                ];
            }

            $reversal = $this->createJournalEntry([
                'company'     => $entry->company,
                'date'        => now()->toDateString(),
                'reference'   => 'REV-' . $entry->entry_number,
                'description' => 'Reversal of ' . $entry->entry_number,
                'source_type' => 'reversal',
                'source_id'   => $entry->id,
            ], $reversingLines, true);

            $entry->update([
                'status'              => 'void',
                'reversed_by_entry_id' => $reversal->id,
            ]);

            AccountingAuditTrail::log('void', $entry);

            return $reversal;
        });
    }

    /**
     * Get trial balance as of a given date.
     */
    public function getTrialBalance(?string $company, ?string $asOfDate = null): array
    {
        $asOfDate = $asOfDate ?: now()->toDateString();

        $accounts = ChartOfAccount::where('company', $company)
            ->where('is_active', true)
            ->orderBy('account_code')
            ->get();

        $result = [];
        $totalDebit = 0;
        $totalCredit = 0;

        foreach ($accounts as $account) {
            $query = JournalEntryLine::where('account_id', $account->id)
                ->whereHas('journalEntry', function ($q) use ($asOfDate) {
                    $q->where('status', 'posted')->where('date', '<=', $asOfDate);
                });

            $debits  = (float) $query->sum('debit');
            $credits = (float) $query->sum('credit');
            $balance = $account->normal_balance === 'debit'
                ? ($debits - $credits + $account->opening_balance)
                : ($credits - $debits + $account->opening_balance);

            if (abs($balance) < 0.01) continue;

            $debitBal  = $balance > 0 && $account->normal_balance === 'debit' ? $balance : ($balance < 0 && $account->normal_balance === 'credit' ? abs($balance) : 0);
            $creditBal = $balance > 0 && $account->normal_balance === 'credit' ? $balance : ($balance < 0 && $account->normal_balance === 'debit' ? abs($balance) : 0);

            $result[] = [
                'account_code' => $account->account_code,
                'account_name' => $account->name,
                'type'         => $account->type,
                'debit'        => $debitBal,
                'credit'       => $creditBal,
            ];

            $totalDebit  += $debitBal;
            $totalCredit += $creditBal;
        }

        return [
            'accounts'     => $result,
            'total_debit'  => $totalDebit,
            'total_credit' => $totalCredit,
            'is_balanced'  => abs($totalDebit - $totalCredit) < 0.01,
            'as_of_date'   => $asOfDate,
        ];
    }

    /**
     * Get Profit & Loss for a date range.
     */
    public function getProfitAndLoss(?string $company, string $startDate, string $endDate): array
    {
        $revenue  = $this->sumByType($company, 'revenue', $startDate, $endDate);
        $expenses = $this->sumByType($company, 'expense', $startDate, $endDate);

        return [
            'revenue'    => $revenue,
            'expenses'   => $expenses,
            'net_profit' => $revenue['total'] - $expenses['total'],
            'period'     => ['start' => $startDate, 'end' => $endDate],
        ];
    }

    /**
     * Get Balance Sheet as of a date.
     */
    public function getBalanceSheet(?string $company, string $asOfDate): array
    {
        return [
            'assets'      => $this->sumByType($company, 'asset', null, $asOfDate),
            'liabilities' => $this->sumByType($company, 'liability', null, $asOfDate),
            'equity'      => $this->sumByType($company, 'equity', null, $asOfDate),
            'as_of_date'  => $asOfDate,
        ];
    }

    /**
     * Get Cash Flow summary.
     */
    public function getCashFlow(?string $company, string $startDate, string $endDate): array
    {
        $bankAccounts = BankAccount::where('company', $company)->where('is_active', true)->get();
        $totalIn = 0;
        $totalOut = 0;
        $details = [];

        foreach ($bankAccounts as $ba) {
            $inflow = BankTransaction::where('bank_account_id', $ba->id)
                ->whereBetween('date', [$startDate, $endDate])
                ->sum('debit');
            $outflow = BankTransaction::where('bank_account_id', $ba->id)
                ->whereBetween('date', [$startDate, $endDate])
                ->sum('credit');

            $totalIn += $inflow;
            $totalOut += $outflow;

            $details[] = [
                'account'  => $ba->account_name,
                'inflow'   => (float) $inflow,
                'outflow'  => (float) $outflow,
                'net'      => (float) ($inflow - $outflow),
            ];
        }

        return [
            'total_inflow'  => $totalIn,
            'total_outflow' => $totalOut,
            'net_cash_flow' => $totalIn - $totalOut,
            'details'       => $details,
        ];
    }

    /**
     * Get aged receivables/payables.
     */
    public function getAgedReceivables(?string $company): array
    {
        return $this->getAgedReport($company, 'receivable');
    }

    public function getAgedPayables(?string $company): array
    {
        return $this->getAgedReport($company, 'payable');
    }

    private function getAgedReport(?string $company, string $type): array
    {
        $today = now()->toDateString();
        $model = $type === 'receivable'
            ? \App\Models\Accounting\SalesInvoice::class
            : \App\Models\Accounting\Bill::class;
        $partyRelation = $type === 'receivable' ? 'customer' : 'vendor';

        $items = $model::where('company', $company)
            ->whereNotIn('status', ['paid', 'void'])
            ->where('balance_due', '>', 0)
            ->with($partyRelation)
            ->get();

        $buckets = ['current' => 0, '1_30' => 0, '31_60' => 0, '61_90' => 0, 'over_90' => 0];
        $details = [];

        foreach ($items as $item) {
            $daysOverdue = now()->diffInDays($item->due_date, false);
            $bucket = match (true) {
                $daysOverdue >= 0   => 'current',
                $daysOverdue >= -30 => '1_30',
                $daysOverdue >= -60 => '31_60',
                $daysOverdue >= -90 => '61_90',
                default             => 'over_90',
            };

            $buckets[$bucket] += (float) $item->balance_due;
            $details[] = [
                'number'       => $type === 'receivable' ? $item->invoice_number : $item->bill_number,
                'party'        => $item->{$partyRelation}->name ?? 'N/A',
                'due_date'     => $item->due_date->format('Y-m-d'),
                'balance'      => (float) $item->balance_due,
                'days_overdue' => abs($daysOverdue),
                'bucket'       => $bucket,
            ];
        }

        return [
            'buckets' => $buckets,
            'total'   => array_sum($buckets),
            'details' => $details,
        ];
    }

    /**
     * Record a bank transaction when a payment is made/received.
     */
    public function recordBankTransaction(int $bankAccountId, array $data): BankTransaction
    {
        $lastBalance = BankTransaction::where('bank_account_id', $bankAccountId)
            ->latest('id')
            ->value('running_balance');

        if ($lastBalance === null) {
            $lastBalance = BankAccount::find($bankAccountId)->opening_balance ?? 0;
        }

        $runningBalance = $lastBalance + ($data['debit'] ?? 0) - ($data['credit'] ?? 0);

        return BankTransaction::create([
            'bank_account_id' => $bankAccountId,
            'date'            => $data['date'],
            'description'     => $data['description'] ?? null,
            'reference'       => $data['reference'] ?? null,
            'debit'           => $data['debit'] ?? 0,
            'credit'          => $data['credit'] ?? 0,
            'running_balance' => $runningBalance,
            'source_type'     => $data['source_type'] ?? null,
            'source_id'       => $data['source_id'] ?? null,
        ]);
    }

    /**
     * Sum account balances by type for reporting.
     */
    private function sumByType(?string $company, string $type, ?string $startDate, string $endDate): array
    {
        $accounts = ChartOfAccount::where('company', $company)
            ->where('type', $type)
            ->where('is_active', true)
            ->orderBy('account_code')
            ->get();

        $items = [];
        $total = 0;

        foreach ($accounts as $account) {
            $query = JournalEntryLine::where('account_id', $account->id)
                ->whereHas('journalEntry', function ($q) use ($startDate, $endDate) {
                    $q->where('status', 'posted')->where('date', '<=', $endDate);
                    if ($startDate) {
                        $q->where('date', '>=', $startDate);
                    }
                });

            $debits  = (float) $query->sum('debit');
            $credits = (float) $query->sum('credit');

            $balance = $account->normal_balance === 'debit'
                ? $debits - $credits
                : $credits - $debits;

            if (!$startDate) {
                $balance += $account->opening_balance;
            }

            if (abs($balance) < 0.01) continue;

            $items[] = [
                'account_code' => $account->account_code,
                'account_name' => $account->name,
                'sub_type'     => $account->sub_type,
                'balance'      => $balance,
            ];
            $total += $balance;
        }

        return ['items' => $items, 'total' => $total];
    }
}
