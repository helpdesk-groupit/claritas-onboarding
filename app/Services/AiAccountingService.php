<?php

namespace App\Services;

use App\Models\Accounting\AccountingSetting;
use App\Models\Accounting\AiChatMessage;
use App\Models\Accounting\AiChatSession;
use App\Models\Accounting\AiInvoiceScan;
use App\Models\Accounting\Bill;
use App\Models\Accounting\ChartOfAccount;
use App\Models\Accounting\Customer;
use App\Models\Accounting\SalesInvoice;
use App\Models\Accounting\Vendor;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AiAccountingService
{
    private ?string $apiKey;
    private string $model;
    private string $provider;

    public function __construct()
    {
        $settings = AccountingSetting::first();
        $this->provider = $settings->ai_provider ?? 'openai';
        $this->apiKey   = $settings->ai_api_key ?? config('services.openai.api_key');
        $this->model    = $settings->ai_model ?? 'gpt-4o';
    }

    /**
     * Extract invoice data from an uploaded image/PDF using AI vision.
     */
    public function extractInvoiceData(AiInvoiceScan $scan): array
    {
        $scan->update(['status' => 'processing']);

        try {
            $filePath = storage_path('app/' . $scan->file_path);

            if (!file_exists($filePath)) {
                throw new \RuntimeException('File not found: ' . $scan->file_path);
            }

            $base64 = base64_encode(file_get_contents($filePath));
            $mimeType = mime_content_type($filePath);

            $prompt = <<<EOT
Analyze this invoice/bill image and extract the following information in JSON format:
{
  "vendor_name": "string",
  "vendor_address": "string or null",
  "vendor_tax_id": "string or null",
  "invoice_number": "string",
  "date": "YYYY-MM-DD",
  "due_date": "YYYY-MM-DD or null",
  "currency": "3-letter code, default MYR",
  "items": [
    {
      "description": "string",
      "quantity": number,
      "unit_price": number,
      "tax_amount": number,
      "line_total": number
    }
  ],
  "subtotal": number,
  "tax_total": number,
  "total": number,
  "payment_terms": "string or null",
  "notes": "string or null"
}
Return ONLY valid JSON. If a field cannot be determined, use null for strings and 0 for numbers.
EOT;

            $response = Http::timeout(60)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type'  => 'application/json',
                ])
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model'    => $this->model,
                    'messages' => [
                        [
                            'role'    => 'user',
                            'content' => [
                                ['type' => 'text', 'text' => $prompt],
                                [
                                    'type'      => 'image_url',
                                    'image_url' => [
                                        'url' => "data:{$mimeType};base64,{$base64}",
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'max_tokens'  => 2000,
                    'temperature' => 0.1,
                ]);

            if (!$response->successful()) {
                throw new \RuntimeException('AI API error: ' . $response->body());
            }

            $content = $response->json('choices.0.message.content', '');
            $content = preg_replace('/```json\s*/', '', $content);
            $content = preg_replace('/```\s*/', '', $content);
            $extracted = json_decode(trim($content), true);

            if (!$extracted) {
                throw new \RuntimeException('Failed to parse AI response as JSON');
            }

            $scan->update([
                'status'           => 'completed',
                'extracted_data'   => $extracted,
                'confidence_score' => 85.00,
            ]);

            return $extracted;
        } catch (\Throwable $e) {
            Log::error('AI Invoice Scan failed', ['scan_id' => $scan->id, 'error' => $e->getMessage()]);

            $scan->update([
                'status'        => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Process an AI chatbot message and return a response.
     */
    public function chat(AiChatSession $session, string $userMessage): string
    {
        AiChatMessage::create([
            'session_id' => $session->id,
            'role'       => 'user',
            'content'    => $userMessage,
        ]);

        try {
            $context = $this->buildChatContext($session);
            $functionResult = $this->processLocalFunctions($userMessage, $session->company);

            if ($functionResult) {
                $assistantMessage = $functionResult;
            } else {
                $assistantMessage = $this->callChatApi($context, $userMessage, $session->company);
            }

            AiChatMessage::create([
                'session_id' => $session->id,
                'role'       => 'assistant',
                'content'    => $assistantMessage,
            ]);

            return $assistantMessage;
        } catch (\Throwable $e) {
            Log::error('AI Chat failed', ['session_id' => $session->id, 'error' => $e->getMessage()]);

            $errorMsg = 'I encountered an error processing your request. Please try again.';
            AiChatMessage::create([
                'session_id' => $session->id,
                'role'       => 'assistant',
                'content'    => $errorMsg,
            ]);

            return $errorMsg;
        }
    }

    /**
     * Try to handle common queries locally before calling the AI API.
     */
    private function processLocalFunctions(string $message, ?string $company): ?string
    {
        $lower = strtolower($message);
        $svc = new AccountingService();

        if (preg_match('/total revenue|total sales|sales this/', $lower)) {
            $start = now()->startOfMonth()->toDateString();
            $end = now()->toDateString();
            $pnl = $svc->getProfitAndLoss($company, $start, $end);
            return sprintf(
                "**Revenue this month:** RM %s\n**Expenses:** RM %s\n**Net Profit:** RM %s",
                number_format($pnl['revenue']['total'], 2),
                number_format($pnl['expenses']['total'], 2),
                number_format($pnl['net_profit'], 2)
            );
        }

        if (preg_match('/outstanding invoice|unpaid invoice|receivable/', $lower)) {
            $total = SalesInvoice::where('company', $company)->whereNotIn('status', ['paid', 'void'])->sum('balance_due');
            $count = SalesInvoice::where('company', $company)->whereNotIn('status', ['paid', 'void'])->count();
            return sprintf("**Outstanding Invoices:** %d invoices totalling RM %s", $count, number_format($total, 2));
        }

        if (preg_match('/outstanding bill|unpaid bill|payable/', $lower)) {
            $total = Bill::where('company', $company)->whereNotIn('status', ['paid', 'void'])->sum('balance_due');
            $count = Bill::where('company', $company)->whereNotIn('status', ['paid', 'void'])->count();
            return sprintf("**Outstanding Bills:** %d bills totalling RM %s", $count, number_format($total, 2));
        }

        if (preg_match('/cash balance|bank balance|cash position/', $lower)) {
            $accounts = \App\Models\Accounting\BankAccount::where('company', $company)->where('is_active', true)->get();
            $lines = [];
            $total = 0;
            foreach ($accounts as $acc) {
                $bal = $acc->current_balance;
                $total += $bal;
                $lines[] = sprintf("- %s: RM %s", $acc->account_name, number_format($bal, 2));
            }
            return "**Cash & Bank Balances:**\n" . implode("\n", $lines) . sprintf("\n\n**Total:** RM %s", number_format($total, 2));
        }

        if (preg_match('/how many customer/', $lower)) {
            $count = Customer::where('company', $company)->where('is_active', true)->count();
            return "You have **{$count} active customers**.";
        }

        if (preg_match('/how many vendor|how many supplier/', $lower)) {
            $count = Vendor::where('company', $company)->where('is_active', true)->count();
            return "You have **{$count} active vendors/suppliers**.";
        }

        if (preg_match('/trial balance/', $lower)) {
            $tb = $svc->getTrialBalance($company);
            $lines = ["**Trial Balance as of {$tb['as_of_date']}**\n"];
            $lines[] = "| Account | Debit | Credit |";
            $lines[] = "|---|---|---|";
            foreach (array_slice($tb['accounts'], 0, 20) as $acc) {
                $lines[] = sprintf("| %s - %s | %s | %s |",
                    $acc['account_code'], $acc['account_name'],
                    $acc['debit'] > 0 ? number_format($acc['debit'], 2) : '-',
                    $acc['credit'] > 0 ? number_format($acc['credit'], 2) : '-'
                );
            }
            $lines[] = sprintf("| **TOTAL** | **%s** | **%s** |",
                number_format($tb['total_debit'], 2),
                number_format($tb['total_credit'], 2)
            );
            return implode("\n", $lines);
        }

        if (preg_match('/profit.*loss|p\s*&\s*l|income statement/', $lower)) {
            $start = now()->startOfYear()->toDateString();
            $end = now()->toDateString();
            $pnl = $svc->getProfitAndLoss($company, $start, $end);
            $lines = ["**Profit & Loss (YTD {$start} to {$end})**\n"];
            $lines[] = "**Revenue:**";
            foreach ($pnl['revenue']['items'] as $item) {
                $lines[] = sprintf("- %s: RM %s", $item['account_name'], number_format($item['balance'], 2));
            }
            $lines[] = sprintf("**Total Revenue: RM %s**\n", number_format($pnl['revenue']['total'], 2));
            $lines[] = "**Expenses:**";
            foreach ($pnl['expenses']['items'] as $item) {
                $lines[] = sprintf("- %s: RM %s", $item['account_name'], number_format($item['balance'], 2));
            }
            $lines[] = sprintf("**Total Expenses: RM %s**\n", number_format($pnl['expenses']['total'], 2));
            $lines[] = sprintf("### Net Profit: RM %s", number_format($pnl['net_profit'], 2));
            return implode("\n", $lines);
        }

        if (preg_match('/top.*(customer|client)/', $lower)) {
            $top = SalesInvoice::where('company', $company)
                ->where('status', 'paid')
                ->selectRaw('customer_id, SUM(total) as total_revenue')
                ->groupBy('customer_id')
                ->orderByDesc('total_revenue')
                ->limit(5)
                ->with('customer')
                ->get();

            $lines = ["**Top 5 Customers by Revenue:**\n"];
            foreach ($top as $i => $row) {
                $lines[] = sprintf("%d. %s — RM %s", $i + 1, $row->customer->name ?? 'N/A', number_format($row->total_revenue, 2));
            }
            return implode("\n", $lines);
        }

        return null;
    }

    /**
     * Call the OpenAI chat API for complex queries.
     */
    private function callChatApi(array $context, string $userMessage, ?string $company): string
    {
        if (!$this->apiKey) {
            return $this->processLocalFunctions($userMessage, $company)
                ?? "I can answer questions about revenue, expenses, invoices, bills, cash balance, trial balance, and P&L. Try asking about one of these topics.";
        }

        $systemPrompt = "You are a financial AI assistant for a Malaysian company. "
            . "You help finance managers with accounting queries, financial analysis, and reporting. "
            . "Answer concisely using data provided in the context. Use RM (Malaysian Ringgit) for currency. "
            . "Format numbers with commas and 2 decimal places. Use markdown for formatting.";

        $messages = [['role' => 'system', 'content' => $systemPrompt]];

        foreach ($context as $msg) {
            $messages[] = $msg;
        }

        $messages[] = ['role' => 'user', 'content' => $userMessage];

        $response = Http::timeout(30)
            ->withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type'  => 'application/json',
            ])
            ->post('https://api.openai.com/v1/chat/completions', [
                'model'       => $this->model,
                'messages'    => $messages,
                'max_tokens'  => 1500,
                'temperature' => 0.3,
            ]);

        if (!$response->successful()) {
            return "I'm unable to reach the AI service right now. Please try a specific query like 'show revenue this month' or 'outstanding invoices'.";
        }

        return $response->json('choices.0.message.content', 'No response generated.');
    }

    /**
     * Build conversation context from recent messages.
     */
    private function buildChatContext(AiChatSession $session): array
    {
        $recent = $session->messages()->latest()->limit(10)->get()->reverse();

        return $recent->map(fn ($msg) => [
            'role'    => $msg->role,
            'content' => $msg->content,
        ])->values()->toArray();
    }
}
