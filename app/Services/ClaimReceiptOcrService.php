<?php

namespace App\Services;

use App\Models\Accounting\AccountingSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Reads a claim receipt with AI vision and returns {amount, date, vendor} to
 * pre-fill the claim form. Reuses the accounting module's AI provider/key
 * (AccountingSetting::resolveForAi), with config/env fallbacks.
 *
 * Config-gated via claims.ocr.enabled and fails OPEN: any missing key, provider
 * error, or unparseable response returns null, and the user just types details
 * in manually. Never blocks a claim.
 */
class ClaimReceiptOcrService
{
    protected static function settings(?string $company): ?AccountingSetting
    {
        try {
            return AccountingSetting::resolveForAi($company);
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected static function apiKey(?string $company): ?string
    {
        return config('claims.ocr.api_key')
            ?: (self::settings($company)?->ai_api_key)
            ?: config('services.openai.api_key');
    }

    public static function enabled(?string $company = null): bool
    {
        if (! config('claims.ocr.enabled')) {
            return false;
        }
        // Ollama runs locally and needs no API key; every other provider does.
        $provider = config('claims.ocr.provider') ?: (self::settings($company)?->ai_provider ?? 'openai');
        if ($provider === 'ollama') {
            return true;
        }

        return (bool) self::apiKey($company);
    }

    /**
     * Extract {amount, date, vendor, category} from a receipt file; null on any failure.
     * $categories (optional) is a list of ['code' => ..., 'name' => ...] to let the model
     * pick the best-fitting expense category — its returned code is validated against the list.
     */
    public static function extract(string $absolutePath, string $mimeType, ?string $company = null, array $categories = []): ?array
    {
        if (! self::enabled($company) || ! is_file($absolutePath)) {
            return null;
        }

        $settings = self::settings($company);
        // A claims-specific provider override lets OCR use a free provider
        // (e.g. Gemini) regardless of what the accounting module is set to.
        $override = config('claims.ocr.provider');
        $provider = $override ?: ($settings?->ai_provider ?? 'openai');
        $key = self::apiKey($company);

        $defaultModel = match ($provider) {
            'gemini' => 'gemini-2.5-flash',
            'anthropic' => 'claude-3-5-sonnet-20241022',
            'ollama' => 'llama3.2-vision',
            'groq' => 'meta-llama/llama-4-scout-17b-16e-instruct',
            default => 'gpt-4o',
        };
        // Don't borrow the accounting model when the provider was overridden
        // (it would belong to a different provider).
        $model = config('claims.ocr.model')
            ?: ($override ? $defaultModel : ($settings?->ai_model ?: $defaultModel));

        // Build the optional category-classification clause from the allowed list.
        $categoryClause = '';
        $validCodes = [];
        if (! empty($categories)) {
            $lines = [];
            foreach ($categories as $c) {
                if (empty($c['code'])) {
                    continue;
                }
                $validCodes[strtoupper($c['code'])] = $c['code'];
                $lines[] = '- '.$c['code'].': '.($c['name'] ?? $c['code']);
            }
            if ($lines) {
                $categoryClause = ', '
                    .'"category" (the single best-fitting category CODE from this list, or null if none clearly fits — '
                    .'use the merchant/items to decide, e.g. a petrol station maps to the fuel category): '
                    .implode('; ', $lines);
            }
        }

        $prompt = 'Read this expense receipt and return ONLY strict JSON with keys: '
            .'"amount" (the total paid as a number, no currency symbol, or null), '
            .'"date" (the receipt date as YYYY-MM-DD, or null), '
            .'"vendor" (the merchant/shop name, or null)'
            .$categoryClause
            .'. No commentary, JSON only.';

        try {
            $base64 = base64_encode(file_get_contents($absolutePath));

            if ($provider === 'anthropic') {
                $resp = Http::timeout(45)
                    ->withHeaders(['x-api-key' => $key, 'anthropic-version' => '2023-06-01'])
                    ->post('https://api.anthropic.com/v1/messages', [
                        'model' => $model,
                        'max_tokens' => 300,
                        'messages' => [[
                            'role' => 'user',
                            'content' => [
                                ['type' => 'text', 'text' => $prompt],
                                ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $mimeType, 'data' => $base64]],
                            ],
                        ]],
                    ]);
                $content = $resp->json('content.0.text');
            } else {
                $ollamaBase = rtrim(config('claims.ocr.ollama_url') ?: ($settings?->ollama_base_url ?: 'http://localhost:11434'), '/');
                $endpoint = match ($provider) {
                    'gemini' => 'https://generativelanguage.googleapis.com/v1beta/openai/chat/completions',
                    'groq' => 'https://api.groq.com/openai/v1/chat/completions',
                    'ollama' => "{$ollamaBase}/v1/chat/completions",
                    default => 'https://api.openai.com/v1/chat/completions',
                };

                // Gemini's OpenAI-compatible endpoint takes the key as a Bearer token
                // (NOT a ?key= URL param — that returns 400 "Missing Authorization header").
                // Ollama is local/unauthenticated, so it's the only no-token path.
                // Retry rides out transient 5xx "model overloaded" blips, which usually
                // clear immediately. NOT 429 (quota): that needs a ~20s wait, so a fast
                // retry only burns more quota — fail open to manual entry instead.
                $req = Http::timeout($provider === 'ollama' ? 120 : 30)
                    ->retry(2, 500, fn ($e, $r) => $r && in_array($r->status(), [500, 502, 503], true), throw: false);
                if ($provider !== 'ollama') {
                    $req = $req->withToken($key);
                }
                $resp = $req->post($endpoint, [
                    'model' => $model,
                    'max_tokens' => 300,
                    'temperature' => 0.1,
                    'messages' => [[
                        'role' => 'user',
                        'content' => [
                            ['type' => 'text', 'text' => $prompt],
                            ['type' => 'image_url', 'image_url' => ['url' => "data:{$mimeType};base64,{$base64}"]],
                        ],
                    ]],
                ]);
                $content = $resp->json('choices.0.message.content');
            }

            if (! $resp->successful() || ! $content) {
                return null;
            }

            $content = trim(preg_replace('/```(?:json)?|```/', '', $content));
            $json = json_decode($content, true);
            if (! is_array($json)) {
                return null;
            }

            // Validate the model's category against the allowed codes (case-insensitive);
            // discard anything it invented so the form never selects a bogus category.
            $category = null;
            if (! empty($validCodes) && isset($json['category']) && is_string($json['category'])) {
                $category = $validCodes[strtoupper(trim($json['category']))] ?? null;
            }

            return [
                'amount' => isset($json['amount']) && is_numeric($json['amount']) ? round((float) $json['amount'], 2) : null,
                'date' => (isset($json['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $json['date'])) ? $json['date'] : null,
                'vendor' => isset($json['vendor']) && is_string($json['vendor']) ? mb_substr(trim($json['vendor']), 0, 120) : null,
                'category' => $category,
            ];
        } catch (\Throwable $e) {
            Log::warning('Claim receipt OCR failed', ['error' => $e->getMessage()]);

            return null;
        }
    }
}
