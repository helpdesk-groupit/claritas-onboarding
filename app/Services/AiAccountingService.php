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
    private string $ollamaBaseUrl;
    private ?string $company;

    public function __construct(?string $company = null)
    {
        if (!$company) {
            $company = Auth::user()?->employee?->company;
        }
        $this->company = $company;
        $settings = AccountingSetting::resolveForAi($company);
        $this->provider      = $settings?->ai_provider ?? 'openai';
        $this->apiKey        = $settings?->ai_api_key ?? config('services.openai.api_key');
        $this->model         = $settings?->ai_model ?? 'gpt-4o';
        $this->ollamaBaseUrl = rtrim($settings?->ollama_base_url ?? 'http://localhost:11434', '/');
    }

    /**
     * Extract invoice data from an uploaded image/PDF using AI vision.
     */
    public function extractInvoiceData(AiInvoiceScan $scan): array
    {
        $scan->update(['status' => 'processing']);

        try {
            $filePath = \Illuminate\Support\Facades\Storage::disk('local')->path($scan->file_path);

            if (!file_exists($filePath)) {
                throw new \RuntimeException('File not found: ' . $scan->file_path);
            }

            $mimeType = mime_content_type($filePath);
            // Normalise: some systems misidentify PDFs
            if (str_ends_with(strtolower($filePath), '.pdf')) {
                $mimeType = 'application/pdf';
            }

            // PDFs must be converted to an image, or handled natively by the provider.
            // convertPdfToImage() tries Ghostscript → Imagick, catching policy errors.
            // If conversion is unavailable, fall back to a provider-native PDF path.
            if (str_contains($mimeType, 'pdf')) {
                $converted = $this->convertPdfToImage($filePath);
                if ($converted !== null) {
                    $filePath = $converted;
                    $mimeType = 'image/png';
                } elseif ($this->provider === 'anthropic') {
                    // Anthropic natively supports PDF via the 'document' content block
                    return $this->extractViaAnthropicPdf($scan, $filePath);
                } elseif ($this->provider === 'openai') {
                    // OpenAI: upload via Files API and reference by file_id
                    return $this->extractViaFilesApi($scan, $filePath);
                } else {
                    throw new \RuntimeException(
                        'PDF processing requires image conversion (Ghostscript/ImageMagick), '
                        . 'which is unavailable on this server. Please convert the PDF to an image and re-upload, '
                        . 'or switch to OpenAI or Anthropic provider which support PDFs natively.'
                    );
                }
            }

            $base64 = base64_encode(file_get_contents($filePath));

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

            $messages = [[
                'role'    => 'user',
                'content' => [
                    ['type' => 'text', 'text' => $prompt],
                    ['type' => 'image_url', 'image_url' => ['url' => "data:{$mimeType};base64,{$base64}"]],
                ],
            ]];
            $response = $this->callProviderApi($messages, 2000, 0.1);

            if (!$response->successful()) {
                throw new \RuntimeException('AI API error: ' . $response->body());
            }

            $content = $this->parseProviderResponse($response);
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

        $response = $this->callProviderApi($messages, 1500, 0.3, 'accounting_ai_chat');

        if (!$response->successful()) {
            return "I'm unable to reach the AI service right now. Please try a specific query like 'show revenue this month' or 'outstanding invoices'.";
        }

        return $this->parseProviderResponse($response) ?: 'No response generated.';
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

    /**
     * Route a chat-completions request to the correct provider API.
     * All providers expose an OpenAI-compatible messages array.
     */
    private function callProviderApi(array $messages, int $maxTokens = 1500, float $temperature = 0.3, string $feature = 'accounting_invoice_scan'): \Illuminate\Http\Client\Response
    {
        $headers = ['Content-Type' => 'application/json'];
        $body    = ['model' => $this->model, 'messages' => $messages, 'max_tokens' => $maxTokens, 'temperature' => $temperature];

        switch ($this->provider) {
            case 'anthropic':
                // Anthropic uses a different auth header and API structure
                $system   = collect($messages)->firstWhere('role', 'system');
                $filtered = collect($messages)->reject(fn($m) => $m['role'] === 'system')->values()->toArray();
                $payload  = array_merge($body, ['messages' => $filtered]);
                if ($system) $payload['system'] = $system['content'];
                unset($payload['max_tokens']);
                $payload['max_tokens'] = $maxTokens;
                $response = Http::timeout(60)
                    ->withHeaders(['x-api-key' => $this->apiKey, 'anthropic-version' => '2023-06-01', 'Content-Type' => 'application/json'])
                    ->post('https://api.anthropic.com/v1/messages', $payload);
                // Capture token spend for the Claude API page's usage report (fails open).
                ClaudeUsageRecorder::record($feature, $this->model, $response->json('usage'), $this->company);
                return $response;

            case 'gemini':
                // Gemini uses Google AI Studio endpoint (OpenAI-compatible via v1beta)
                return Http::timeout(60)
                    ->withHeaders($headers)
                    ->post("https://generativelanguage.googleapis.com/v1beta/openai/chat/completions?key={$this->apiKey}", $body);

            case 'deepseek':
                return Http::timeout(60)
                    ->withHeaders(array_merge($headers, ['Authorization' => 'Bearer ' . $this->apiKey]))
                    ->post('https://api.deepseek.com/v1/chat/completions', $body);

            case 'groq':
                return Http::timeout(60)
                    ->withHeaders(array_merge($headers, ['Authorization' => 'Bearer ' . $this->apiKey]))
                    ->post('https://api.groq.com/openai/v1/chat/completions', $body);

            case 'local':
                // Ollama OpenAI-compatible endpoint (configurable base URL)
                return Http::timeout(120)
                    ->withHeaders($headers)
                    ->post($this->ollamaBaseUrl . '/v1/chat/completions', $body);

            default: // openai
                return Http::timeout(60)
                    ->withHeaders(array_merge($headers, ['Authorization' => 'Bearer ' . $this->apiKey]))
                    ->post('https://api.openai.com/v1/chat/completions', $body);
        }
    }

    /**
     * Normalise a provider API response to extract the text content.
     * Anthropic returns choices differently from OpenAI-compatible providers.
     */
    private function parseProviderResponse(\Illuminate\Http\Client\Response $response): string
    {
        if ($this->provider === 'anthropic') {
            return $response->json('content.0.text', '');
        }
        return $response->json('choices.0.message.content', '');
    }

    /**
     * Convert a PDF's first page to a PNG image.
     * Returns the PNG path on success, or null if no converter is available/allowed.
     * Tries Ghostscript first (avoids ImageMagick policy restrictions on Synology),
     * then falls back to Imagick (catching policy errors gracefully).
     */
    private function convertPdfToImage(string $pdfPath): ?string
    {
        $outputPath = preg_replace('/\.pdf$/i', '', $pdfPath) . '_page1.png';

        // 1. Try Ghostscript CLI — works on most Linux/Synology without policy issues
        $gs = collect(['/usr/bin/gs', '/usr/local/bin/gs', '/opt/bin/gs', '/opt/local/bin/gs'])
            ->first(fn ($p) => is_executable($p));

        if ($gs) {
            $escaped = [escapeshellarg($outputPath), escapeshellarg($pdfPath)];
            exec("{$gs} -dBATCH -dNOPAUSE -dFirstPage=1 -dLastPage=1 -sDEVICE=png16m -r200 -sOutputFile={$escaped[0]} {$escaped[1]} 2>&1", $out, $code);
            if ($code === 0 && file_exists($outputPath)) {
                return $outputPath;
            }
            Log::warning('Ghostscript PDF conversion failed', ['exit' => $code, 'output' => implode("\n", $out)]);
        }

        // 2. Try Imagick — but catch ImageMagick policy errors (common on Synology)
        if (extension_loaded('imagick')) {
            try {
                $im = new \Imagick();
                $im->setResolution(200, 200);
                $im->readImage($pdfPath . '[0]');
                $im->setImageFormat('png');
                $im->writeImage($outputPath);
                $im->destroy();
                return $outputPath;
            } catch (\Throwable $e) {
                Log::warning('Imagick PDF conversion failed (policy or extension error): ' . $e->getMessage());
            }
        }

        // No converter succeeded
        Log::warning('PDF-to-image conversion unavailable. Will use provider-native PDF fallback.', ['path' => $pdfPath]);
        return null;
    }

    /**
     * Anthropic-native PDF fallback: sends the PDF as a base64 document block.
     * Anthropic Claude supports PDFs natively via the 'document' content type.
     */
    private function extractViaAnthropicPdf(AiInvoiceScan $scan, string $pdfPath): array
    {
        $prompt = <<<EOT
Analyze this invoice/bill PDF and extract the following information in JSON format:
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

        $base64Pdf = base64_encode(file_get_contents($pdfPath));

        $response = Http::timeout(60)
            ->withHeaders([
                'x-api-key'         => $this->apiKey,
                'anthropic-version' => '2023-06-01',
                'anthropic-beta'    => 'pdfs-2024-09-25',
                'Content-Type'      => 'application/json',
            ])
            ->post('https://api.anthropic.com/v1/messages', [
                'model'      => $this->model,
                'max_tokens' => 2000,
                'messages'   => [[
                    'role'    => 'user',
                    'content' => [
                        [
                            'type'   => 'document',
                            'source' => [
                                'type'       => 'base64',
                                'media_type' => 'application/pdf',
                                'data'       => $base64Pdf,
                            ],
                        ],
                        ['type' => 'text', 'text' => $prompt],
                    ],
                ]],
            ]);

        // Record before the success check — a partial/failed response still reports any
        // tokens Anthropic billed for, and this path throws on failure.
        ClaudeUsageRecorder::record('accounting_invoice_scan', $this->model, $response->json('usage'), $this->company);

        if (!$response->successful()) {
            throw new \RuntimeException('Anthropic PDF extraction failed: ' . $response->body());
        }

        $content = $response->json('content.0.text', '');
        $content = preg_replace('/```json\s*/', '', $content);
        $content = preg_replace('/```\s*/', '', $content);
        $extracted = json_decode(trim($content), true);

        if (!$extracted) {
            throw new \RuntimeException('Failed to parse Anthropic PDF response as JSON');
        }

        $scan->update([
            'status'           => 'completed',
            'extracted_data'   => $extracted,
            'confidence_score' => 85.00,
        ]);

        return $extracted;
    }

    /**
     * Fallback for when PDF→image conversion is unavailable (OpenAI only).
     * Uploads the PDF to OpenAI Files API and uses GPT-4o's native PDF support.
     */
    private function extractViaFilesApi(AiInvoiceScan $scan, string $pdfPath): array
    {
        // Upload the file to OpenAI
        $uploadResponse = Http::timeout(60)
            ->withHeaders(['Authorization' => 'Bearer ' . $this->apiKey])
            ->attach('file', file_get_contents($pdfPath), basename($pdfPath))
            ->post('https://api.openai.com/v1/files', ['purpose' => 'assistants']);

        if (!$uploadResponse->successful()) {
            throw new \RuntimeException('OpenAI file upload failed: ' . $uploadResponse->body());
        }

        $fileId = $uploadResponse->json('id');

        $prompt = <<<EOT
Analyze this invoice/bill PDF and extract the following information in JSON format:
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
                            ['type' => 'image_url', 'image_url' => ['url' => "https://api.openai.com/v1/files/{$fileId}/content"]],
                        ],
                    ],
                ],
                'max_tokens'  => 2000,
                'temperature' => 0.1,
            ]);

        // Clean up the uploaded file
        Http::withHeaders(['Authorization' => 'Bearer ' . $this->apiKey])
            ->delete("https://api.openai.com/v1/files/{$fileId}");

        if (!$response->successful()) {
            throw new \RuntimeException('AI API error (Files API): ' . $response->body());
        }

        $content = $this->parseProviderResponse($response);
        $content = preg_replace('/```json\s*/', '', $content);
        $content = preg_replace('/```\s*/', '', $content);
        $extracted = json_decode(trim($content), true);

        if (!$extracted) {
            throw new \RuntimeException('Failed to parse AI response as JSON (Files API path)');
        }

        $scan->update([
            'status'           => 'completed',
            'extracted_data'   => $extracted,
            'confidence_score' => 85.00,
        ]);

        return $extracted;
    }
}
