<?php

namespace App\Services;

use App\Models\Accounting\AccountingSetting;
use App\Models\ClaudeApiSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Reads a claim receipt with AI vision and returns {amount, date, vendor, …} to
 * pre-fill the claim form. Reuses the accounting module's AI provider/key
 * (AccountingSetting::resolveForAi), with config/env fallbacks.
 *
 * Two entry points:
 *  - extract()      — single receipt OR map screenshot → one flat object (used by
 *                     the reviewer verification in verifyItem).
 *  - scanDocument() — one image that may hold MANY receipts (or a statement/history
 *                     of dated rows), OR a map screenshot → {map, items[]} for the
 *                     My-Claims scan + multi-item review table.
 *
 * Config-gated via claims.ocr.enabled and fails OPEN: any missing key, provider
 * error, or unparseable response returns null/empty, and the user just types
 * details in manually. Never blocks a claim.
 */
class ClaimReceiptOcrService
{
    /** Per-request memo of the Claude API setting (avoids re-querying on every call). */
    protected static ?ClaudeApiSetting $claudeMemo = null;

    protected static bool $claudeMemoLoaded = false;

    /**
     * The superadmin "Claude API" setting when it is ACTIVE (switched on + key set),
     * else null. When active it is the source of truth for OCR and overrides the
     * env-based CLAIMS_OCR_* config. Fails safe: if the table is missing (pre-migration)
     * or any error occurs, returns null and OCR falls back to the env config.
     */
    protected static function claude(): ?ClaudeApiSetting
    {
        if (! self::$claudeMemoLoaded) {
            self::$claudeMemoLoaded = true;
            try {
                $s = ClaudeApiSetting::current();
                self::$claudeMemo = $s->isActive() ? $s : null;
            } catch (\Throwable $e) {
                self::$claudeMemo = null;
            }
        }

        return self::$claudeMemo;
    }

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
        // Claude API page wins when active.
        if ($c = self::claude()) {
            return $c->getRawKey();
        }

        return config('claims.ocr.api_key')
            ?: (self::settings($company)?->ai_api_key)
            ?: config('services.openai.api_key');
    }

    public static function enabled(?string $company = null): bool
    {
        // The Claude API page is a self-contained on-switch: an active key means OCR is on,
        // regardless of the env CLAIMS_OCR_ENABLED flag.
        if (self::claude()) {
            return true;
        }
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
     * Live check for the "Claude API" settings page: does this key + model actually
     * work against Anthropic? Returns ['ok' => bool, 'message' => string]. A cheap
     * text-only call — enough to catch a bad key, a model the key can't access, or
     * no billing/credit — without spending on a vision request.
     */
    public static function testAnthropicKey(string $key, string $model): array
    {
        try {
            $resp = Http::timeout(20)
                ->withHeaders(['x-api-key' => $key, 'anthropic-version' => '2023-06-01'])
                ->post('https://api.anthropic.com/v1/messages', [
                    'model' => $model,
                    'max_tokens' => 8,
                    'messages' => [['role' => 'user', 'content' => 'ping']],
                ]);

            if ($resp->successful()) {
                // Tiny, but it IS a billed call — leaving it out would make the report
                // disagree with the Anthropic invoice.
                ClaudeUsageRecorder::record('api_key_test', $model, $resp->json('usage'));

                return ['ok' => true, 'message' => 'Key works — Claude responded. OCR is ready.'];
            }

            $type = $resp->json('error.type');
            $err = $resp->json('error.message') ?: ('HTTP '.$resp->status());

            return match (true) {
                $resp->status() === 401 || $type === 'authentication_error' => ['ok' => false, 'message' => 'Invalid API key — authentication failed.'],
                $resp->status() === 404 || $type === 'not_found_error' => ['ok' => false, 'message' => 'This key can\'t use the selected model: '.$err],
                $resp->status() === 429 => ['ok' => false, 'message' => 'Rate-limited or out of credit — check your Anthropic billing.'],
                default => ['ok' => false, 'message' => $err],
            };
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Could not reach Anthropic: '.$e->getMessage()];
        }
    }

    /**
     * Extract {amount, date, vendor, category, …map fields} from a single receipt or
     * map screenshot; null on any failure. $categories (optional) is a list of
     * ['code' => ..., 'name' => ...] to let the model pick the best-fitting expense
     * category — its returned code is validated against the list.
     */
    public static function extract(string $absolutePath, string $mimeType, ?string $company = null, array $categories = []): ?array
    {
        if (! self::enabled($company) || ! is_file($absolutePath)) {
            return null;
        }

        $validCodes = [];
        $categoryClause = self::categoryClause($categories, $validCodes);

        $prompt = 'You are reading an expense document that is EITHER a receipt OR a Google Maps / '
            .'navigation route screenshot. Return ONLY strict JSON with keys: '
            .'"amount" (receipt total paid as a number, no currency symbol, or null), '
            .'"date" (receipt date as YYYY-MM-DD, or null), '
            .self::dateRule()
            .self::taxRule()
            .self::vendorRule()
            .self::itemDescRule()
            .self::paidByRule()
            .self::distanceRule()
            .self::routeRule()
            .$categoryClause
            .'. No commentary, JSON only.';

        // extract() serves the REVIEWER verification path — bill it to that feature,
        // not to the employee-facing scan (scanDocument takes callVision's default).
        $json = self::callVision($prompt, $absolutePath, $mimeType, $company, 300, 'claim_item_verify');
        if (! is_array($json)) {
            return null;
        }

        return array_merge(
            self::normalizeReceipt($json, $validCodes),
            self::normalizeMap($json),
        );
    }

    /**
     * Scan one image that may contain MULTIPLE receipts (or a statement/history of
     * dated transactions), OR a single map screenshot. Returns:
     *   ['map' => {distance_km, route_from, route_to, route_stops} | null,
     *    'items' => [ {amount, date, vendor, item_description, paid_by, category}, … ]]
     * Returns null only on a hard failure (fail open → caller falls back to manual).
     */
    public static function scanDocument(string $absolutePath, string $mimeType, ?string $company = null, array $categories = []): ?array
    {
        if (! self::enabled($company) || ! is_file($absolutePath)) {
            return null;
        }

        $validCodes = [];
        $categoryClause = self::categoryClause($categories, $validCodes);
        $max = max(1, (int) config('claims.ocr.max_items', 40));

        $prompt = 'You are reading an expense document. It is EITHER (a) a PURE Google Maps / navigation '
            .'directions screenshot used to claim SELF-DRIVEN mileage — a route/directions view with NO fare, '
            .'total or payment shown — OR (b) ONE OR MORE receipts: several separate receipts photographed '
            .'together, a bank/card statement or parking history with MANY dated rows, or RIDE-HAILING / delivery '
            .'receipts. Return ONLY strict JSON with these keys: '
            .'"map" — set this ONLY for case (a): a pure navigation/directions screenshot with NO fare/total/'
            .'payment. CRITICAL: a RIDE-HAILING RECEIPT (Grab, JustGrab, MyCar, taxi) that shows a Total paid / '
            .'fare / GrabPay / payment method is a RECEIPT, NOT a map — even though it displays a small route '
            .'map. Put such a ride in "items" (never in "map") and place its pick-up/drop-off into '
            .'pickup_location / dropoff_location. When set, "map" is an object with '
            .self::distanceRule()
            .self::routeRule()
            .'; otherwise "map" must be null. '
            .'"account_holder" — a statement normally prints the ACCOUNT HOLDER / Registered Name / Cardholder '
            .'ONCE at the TOP (the person these transactions belong to); return that name here, or null if it is '
            .'not a statement or no name is shown. '
            .'"issuer" — the card brand / bank / statement provider shown in the header (e.g. "Touch n Go", a bank '
            .'name); or null. '
            .'"items" — an ARRAY with ONE object per DISTINCT receipt or transaction (use an EMPTY array when the '
            .'image is a map). A SINGLE receipt that lists several product / fee LINE-ITEMS — e.g. a clinic bill '
            .'with consultation + several medicines, or a restaurant bill with several dishes — is ONE '
            .'transaction: return exactly ONE item carrying the receipt TOTAL, NOT one item per line. Only return '
            .'multiple items when the image actually holds MULTIPLE SEPARATE receipts, or a bank/card statement '
            .'or history listing many dated transactions. '
            .'For a statement / transaction history: emit one item ONLY for each row that has its OWN amount. '
            .'Many statements group rows under DATE HEADERS — a standalone date line with NO amount next to it, '
            .'e.g. "Wednesday, 1 April 2026" or "Thursday, 9 April 2026". These headers are SEPARATORS, NOT '
            .'transactions: NEVER create an item for a header and NEVER let one inflate the count. The number of '
            .'items you return MUST equal the number of amount lines visible in the image — no more, no fewer. '
            .'When a row carries its OWN date label such as "Posting Date: 2 April 2026" or "Date: …", use THAT '
            .'labelled date for the item — do NOT borrow the group-header date shown above the row. '
            .'Do not merge, sum, split or duplicate rows. '
            .'List items in reading order (top-to-bottom, then left-to-right). Return AT MOST '.$max.' items. '
            .'Each item object has these keys: '
            .'"amount" (the amount paid for THAT transaction as a number, no currency symbol, or null), '
            .'"date" (that transaction date as YYYY-MM-DD, or null), '
            .self::dateRule()
            .self::taxRule()
            .self::vendorRule()
            .self::itemDescRule()
            .self::paidByRule()
            .self::tollLocationRule()
            .self::rideLocationRule()
            .self::emailSubjectRule()
            .self::payerFallbackRule()
            .self::highlightRule()
            .self::txnTypeRule()
            .$categoryClause
            .'. No commentary, JSON only.';

        // Token budget scales with how many itemised objects we might return.
        $maxTokens = max(1500, (int) config('claims.ocr.max_tokens', 4096));
        $json = self::callVision($prompt, $absolutePath, $mimeType, $company, $maxTokens);
        if (! is_array($json)) {
            return null;
        }

        $map = self::normalizeMap($json['map'] ?? []);
        // Keep "map" only if it actually carries route info; an empty {} is not a map.
        $isMap = $map['distance_km'] !== null || $map['route_from'] || $map['route_to'] || ! empty($map['route_stops']);

        // Statement header (read once at the top) — inherited deterministically below so
        // every row carries the company + payer even when the model reads them per-row null.
        $accountHolder = self::clip($json['account_holder'] ?? null, 120);
        $issuer = self::clip($json['issuer'] ?? null, 120);

        $items = [];
        foreach ((array) ($json['items'] ?? []) as $it) {
            if (is_array($it)) {
                $row = self::normalizeReceipt($it, $validCodes);
                // Fill blanks from the shared statement header (Registered Name → who paid,
                // card brand / issuer → company) so they aren't left empty per row.
                if (empty($row['paid_by']) && $accountHolder) {
                    $row['paid_by'] = $accountHolder;
                }
                if (empty($row['vendor']) && $issuer) {
                    $row['vendor'] = $issuer;
                }
                $items[] = $row;
            }
        }

        // Flag (don't hide) when a long statement hit the ceiling, so the UI can warn
        // the user rather than silently dropping rows past the cap.
        $truncated = count($items) >= $max;

        return [
            'map' => $isMap ? $map : null,
            'items' => array_slice($items, 0, $max),
            'truncated' => $truncated,
        ];
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Shared internals
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Send a prompt + image to the configured vision provider and return the decoded
     * JSON (associative array) or null. Encapsulates provider/model/key resolution so
     * extract() and scanDocument() share one transport.
     */
    protected static function callVision(string $prompt, string $absolutePath, string $mimeType, ?string $company, int $maxTokens = 300, string $feature = 'claim_receipt_scan'): ?array
    {
        $settings = self::settings($company);
        if ($claude = self::claude()) {
            // Claude API page is active — it is the source of truth for OCR.
            $provider = 'anthropic';
            $model = $claude->model ?: 'claude-haiku-4-5';
            $key = $claude->getRawKey();
        } else {
            // A claims-specific provider override lets OCR use a free provider (e.g. Gemini)
            // regardless of what the accounting module is set to.
            $override = config('claims.ocr.provider');
            $provider = $override ?: ($settings?->ai_provider ?? 'openai');
            $key = self::apiKey($company);

            $defaultModel = match ($provider) {
                'gemini' => 'gemini-2.5-flash',
                'anthropic' => 'claude-haiku-4-5',
                'ollama' => 'llama3.2-vision',
                'groq' => 'meta-llama/llama-4-scout-17b-16e-instruct',
                default => 'gpt-4o',
            };
            // Don't borrow the accounting model when the provider was overridden
            // (it would belong to a different provider).
            $model = config('claims.ocr.model')
                ?: ($override ? $defaultModel : ($settings?->ai_model ?: $defaultModel));
        }

        try {
            $base64 = base64_encode(file_get_contents($absolutePath));

            if ($provider === 'anthropic') {
                $resp = Http::timeout(45)
                    ->withHeaders(['x-api-key' => $key, 'anthropic-version' => '2023-06-01'])
                    ->post('https://api.anthropic.com/v1/messages', [
                        'model' => $model,
                        'max_tokens' => $maxTokens,
                        'messages' => [[
                            'role' => 'user',
                            'content' => [
                                ['type' => 'text', 'text' => $prompt],
                                ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $mimeType, 'data' => $base64]],
                            ],
                        ]],
                    ]);
                $content = $resp->json('content.0.text');
                // Anthropic reports token usage in the same body — record it for the
                // Claude API page's spend report. Never throws (see the recorder).
                ClaudeUsageRecorder::record($feature, $model, $resp->json('usage'), $company);
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
                $req = Http::timeout($provider === 'ollama' ? 120 : 45)
                    ->retry(3, 1000, function (\Throwable $e) {
                        // Retry transient network blips + 5xx "model overloaded"; NOT 429 (quota
                        // needs a long wait, so fail open). NB: the retry callback's 2nd arg is the
                        // PendingRequest — read the status from the EXCEPTION, never call ->status()
                        // on the request (that threw "PendingRequest::status does not exist" and
                        // turned every retryable blip into a hard failure).
                        if ($e instanceof \Illuminate\Http\Client\ConnectionException) {
                            return true;
                        }

                        return $e instanceof \Illuminate\Http\Client\RequestException
                            && in_array($e->response?->status(), [500, 502, 503], true);
                    }, throw: false);
                if ($provider !== 'ollama') {
                    $req = $req->withToken($key);
                }
                $resp = $req->post($endpoint, [
                    'model' => $model,
                    'max_tokens' => $maxTokens,
                    'temperature' => 0.1,
                    'messages' => [[
                        'role' => 'user',
                        'content' => [
                            ['type' => 'text', 'text' => $prompt],
                            // detail:high asks the model to read the image at full fidelity instead
                            // of a downscaled thumbnail — materially better at faint, partial-cell
                            // highlights and small statement text (standard OpenAI vision field;
                            // Groq/OpenAI honour it, others ignore it harmlessly).
                            ['type' => 'image_url', 'image_url' => ['url' => "data:{$mimeType};base64,{$base64}", 'detail' => 'high']],
                        ],
                    ]],
                ]);
                $content = $resp->json('choices.0.message.content');
            }

            if (! $resp->successful()) {
                // Surface the real reason (esp. 429 rate-limit vs 5xx) so long multi-page scans
                // that drop pages can be diagnosed instead of failing silently.
                Log::warning('Claim receipt OCR non-2xx', [
                    'provider' => $provider,
                    'status' => $resp->status(),
                    'body' => mb_substr((string) $resp->body(), 0, 300),
                ]);

                return null;
            }
            if (! $content) {
                return null;
            }

            $content = trim(preg_replace('/```(?:json)?|```/', '', $content));
            $json = json_decode($content, true);

            return is_array($json) ? $json : null;
        } catch (\Throwable $e) {
            Log::warning('Claim receipt OCR failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Build the optional ", category" classification clause from the allowed list and
     * fill $validCodes (UPPER => original) for post-validation. Empty string when no
     * categories are supplied.
     */
    protected static function categoryClause(array $categories, array &$validCodes): string
    {
        $validCodes = [];
        $lines = [];
        foreach ($categories as $c) {
            if (empty($c['code'])) {
                continue;
            }
            $validCodes[strtoupper($c['code'])] = $c['code'];
            // Include the category's description so the model classifies with full context
            // (e.g. "Computer big items: server, desktop…" vs "Computer small items: ram, cables…").
            $desc = ! empty($c['description']) ? ' — '.$c['description'] : '';
            $lines[] = '- '.$c['code'].': '.($c['name'] ?? $c['code']).$desc;
        }
        if (! $lines) {
            return '';
        }

        return ', '
            .'"category" (the single best-fitting category CODE from this list, or null if none clearly fits — '
            .'use the merchant/items to decide, e.g. a petrol station maps to the fuel category. '
            .'Prefer the MOST SPECIFIC category: a software, app, app-store or digital-tool purchase '
            .'(e.g. Google Play, Apple App Store, CapCut, Adobe, Canva, Microsoft, Figma, Notion) maps to a '
            .'Computer/Software category, NOT a generic "Subscription" category, even when billed monthly; '
            .'use a Subscription category only for non-software recurring services. '
            .'A DENTAL or OPTICAL expense — a dentist, dental clinic ("klinik pergigian"), orthodontist, '
            .'optometrist, optician, glasses/spectacles/contact lenses, or an eye/teeth treatment — maps to the '
            .'Optical & Dental category, NOT a general Medical category, even though a dental clinic is a clinic. '
            .'A MONTHLY or SEASON car-park pass — anything saying "season parking", "CAR PARK SEASON", '
            .'"Season Holder", "season floating bay", a monthly parking subsidy, or a season-pass invoice from a '
            .'car-park operator (e.g. TETAP TIARA, WSI / Jaya One) — maps to the office SEASON-PARKING subsidy '
            .'category (a fixed monthly office-parking allowance), NOT the per-trip toll/parking category. Only '
            .'casual per-entry or hourly parking maps to the toll/parking category. '
            .'A RIDE / e-hailing / taxi trip (Grab, JustGrab, MyCar, inDrive, AirAsia ride) maps to a local '
            .'Travelling / Transport category, NOT a toll category — even when the fare breakdown lists a toll '
            .'line. Classify by the MAIN good or service purchased, NOT by a secondary delivery / shipping / '
            .'postage line: a FOOD or bakery order (cake, cupcakes, catering, refreshments) that lists a '
            .'"Delivery Charge" is a FOOD / refreshment expense, NOT transportation; only a standalone courier '
            .'app receipt (Lalamove / GrabExpress / J&T, where the delivery itself IS the product) maps to a '
            .'transport / delivery category. '
            .'A REPAIR, SERVICING or MAINTENANCE job on the OFFICE / WAREHOUSE / premises — an air-conditioner '
            .'(aircon / aircond) service, repair or installation, electrical / wiring work, plumbing, general '
            .'handyman repairs, cleaning / cleaner service, pest control, or an office renovation / upkeep — maps '
            .'to the Upkeep of Office & Warehouse category, NOT a generic office-supplies, equipment or '
            .'subscription category. '
            .'A MINI-MARKET / CONVENIENCE-STORE / GROCERY / SUNDRY purchase — 99 Speedmart, KK Mart / KK Super '
            .'Mart, MyNews, 7-Eleven, Family Mart, or any provision shop — of FOOD, drinks, milk, snacks, '
            .'biscuits, bread, or pantry / refreshment items maps to the Office Food & Refreshment category, '
            .'NEVER to Printing & Stationery (the "out-of-pocket" note on the stationery category is for '
            .'printing / stationery out-of-pockets only, not for groceries or food)): '
            .implode('; ', $lines);
    }

    /**
     * How to READ a date off the document, as opposed to how to format it back.
     *
     * These are Malaysian documents, so a numeric date is DAY-FIRST (DD/MM/YYYY). Without saying
     * so the model falls back to the US MM/DD/YYYY reading and silently shifts the date — e.g. a
     * Jaya One season-parking invoice dated 06/04/2026 (6 April) came back as 4 June, which then
     * tripped the "receipt is dated June but this is an April claim" guard on a valid receipt.
     */
    protected static function dateRule(): string
    {
        return 'READING DATES: these are MALAYSIAN documents, so a numeric date is DAY-FIRST '
            .'(DD/MM/YYYY or DD-MM-YYYY) — "06/04/2026" is 6 April 2026, NOT 4 June. Never assume the '
            .'US month-first order. A component greater than 12 settles the order on its own '
            .'("30/04/2026" can only be 30 April). A month written in words is already unambiguous — '
            .'read "2 April 2026" as-is. When the document also shows a validity or billing RANGE such '
            .'as "1/04/2026 - 30/04/2026", that range tells you the month the document belongs to — a '
            .'single date on the same document must not land outside it. ';
    }

    /** Reusable per-receipt field-rule fragments (shared by extract + scanDocument). */
    protected static function vendorRule(): string
    {
        return '"vendor" (the ACTUAL company / merchant / developer the purchase was made FROM — '
            .'e.g. on a "Your subscription from Bytedance Pte. Ltd. on Google Play has renewed" '
            .'receipt the vendor is "Bytedance Pte. Ltd.", NOT "Google Play". IGNORE the payment '
            .'gateway / app store / processor (Google Play, Apple App Store, PayPal, Stripe, iPay88, '
            .'Billplz, GrabPay) and return the seller behind it; or null), ';
    }

    protected static function itemDescRule(): string
    {
        return '"item_description" (a SHORT summary of WHAT was bought / paid for — e.g. "JustGrab ride", '
            .'"CapCut Pro 1-month", "2x Latte, 1x Sandwich". For a statement / transaction-history row that '
            .'only shows a merchant or location name and no separate line-item, describe the NATURE of the '
            .'charge instead — e.g. "Parking", "Car park", "Toll" — and do NOT leave it null for a real '
            .'transaction; use null only when nothing at all describes the charge), ';
    }

    protected static function paidByRule(): string
    {
        return '"paid_by" (the NAME of the person or company who paid — a cardholder name, "Bill to" name, '
            .'or account owner. For a LALAMOVE / Grab / courier / delivery receipt: the DELIVERY DRIVER / RIDER '
            .'is NEVER the payer — the driver is the person shown with a STAR RATING (a number like "4.78" next '
            .'to a star icon), a vehicle (e.g. Motorcycle) and/or a round profile avatar, usually near the '
            .'BOTTOM of the receipt; IGNORE that name entirely. The payer is the FIRST "Recipient" name — the '
            .'one beside the PICK-UP / origin address (the TOP address, marked with a circle "○" icon), i.e. the '
            .'sender who booked and paid for the delivery — use THAT first Recipient name. On a clinic / medical / personal '
            .'receipt issued to a named individual — e.g. a "Name:" beside an NRIC/IC number, or a "Patient" / '
            .'"Customer" name — use that person\'s name as the payer (a cardholder or "Bill to" name still takes '
            .'priority; do NOT use the clinic / merchant / staff "Received by" name). If no payer NAME is shown, fall '
            .'back to the account owner — the "Your account: …" / "Account:" email or the "Bill to" name (e.g. on '
            .'a Google Play / app subscription, use the account email). '
            .'IMPORTANT: a PAYMENT METHOD is NOT a payer — NEVER return "Cash", "Card", "Credit Card", "Debit", '
            .'"Visa"/"Mastercard ****1234", "GrabPay", "Touch n Go"/"TnG", "QR", "DuitNow", "FPX", "e-wallet", '
            .'"Cash Received" etc. as the payer. If only a payment method is shown and there is no payer name, '
            .'return null)';
    }

    /** Multi-item only: which rows the user visually marked to single out for claiming. */
    protected static function highlightRule(): string
    {
        return ', "highlighted" (whether the user has visually MARKED this specific row to single it out for '
            .'claiming. Inspect the BACKGROUND COLOUR of each row\'s cells, one row at a time. A row is '
            .'highlighted when ANY of its cells has a coloured marker background — most often a pale YELLOW, but '
            .'also green, pink, blue or orange — or the row is circled, boxed, underlined, ticked/✓ or arrowed. '
            .'The marker very often covers only PART of a row (e.g. just the date/time cell, or just the amount '
            .'cell), so even ONE shaded cell means highlighted=true for that whole row. There are usually '
            .'SEVERAL highlighted rows together — find and flag EVERY one of them, not just the first. Be exact '
            .'about WHICH rows are coloured: NEVER assume the top/first row is the marked one, and do NOT count '
            .'uniform alternating zebra-striping or a shaded header/total row as a highlight. If you cannot '
            .'clearly see a coloured cell on a row, return false for that row — a highlight on the WRONG row is '
            .'worse than none)';
    }

    /** The SST / GST / service-tax amount printed on a receipt (used to split the claim total). */
    protected static function taxRule(): string
    {
        return '"tax_amount" (the SST / GST / service-tax AMOUNT shown on the receipt as a number with no '
            .'currency symbol — e.g. for a line "SST (Inclusive 6%): 10.75" return 10.75, for "GST 6%: 3.00" '
            .'return 3.00. Read the tax AMOUNT, never the percentage. Return null when no tax line is shown or '
            .'the tax is 0. Note whether it is inclusive or exclusive does not matter — just return the amount), ';
    }

    /** Multi-item only: classify a statement row so non-claimable top-ups/fees can default off. */
    protected static function txnTypeRule(): string
    {
        return ', "transaction_type" (a short lowercase tag for what the row is: "season_parking" for a MONTHLY '
            .'or SEASON car-park pass — anything saying "Season Holder", "CAR PARK SEASON", "season parking", '
            .'"season pass", a monthly/season car-park invoice that shows a VALIDITY or EXPIRED DATE RANGE like '
            .'"1/04/2026 - 30/04/2026" (a whole-month billing), or a season-pass invoice from a car-park operator '
            .'(e.g. WSI / Jaya One, TETAP TIARA) — this is the office season-parking subsidy, NOT a per-trip '
            .'parking; "toll" for a ROAD TOLL — an entry plaza to an exit plaza on a highway; "parking" for a '
            .'casual per-entry PARKING charge — a single car-park / location with an entry and exit TIME and '
            .'often a duration like "8 hours" (hourly parking, e.g. "SEKSYEN 14 PETALING JAYA"); "reload" for a '
            .'top-up / reload / "internet reload" / card top-up; "fee" for a service or admin charge or "other '
            .'charges"; otherwise "other"; null if unclear)';
    }

    /** Multi-item only: a toll statement row's entry/exit plazas, used to label the Item as a route. */
    protected static function tollLocationRule(): string
    {
        return ', "entry_location" (for a TOLL transaction row, the value of the "Entry Location" column — the '
            .'entry toll PLAZA NAME exactly as written, e.g. "DUKE - BATU", "AKLEH LANE", "TP 3 AMPANG", "SILK - '
            .'SUNGAI BALAK". Read the "Entry Location" column, NOT the separate "Entry SP" code column (codes look '
            .'like "82_DUKE", "07_AKLEH", "SU_SUKE", "16_SILK"). For a PARKING row put the car-park / location '
            .'name here instead (e.g. "SEKSYEN 14 PETALING JAYA"). null for non-toll, non-parking rows), '
            .'"exit_location" (the value of the "Exit Location" column on the SAME row — the exit toll PLAZA NAME, '
            .'again the "Exit Location" column and NOT the "Exit SP" code. For a parking row leave this null. '
            .'null otherwise)';
    }

    /** Multi-item only: a ride/e-hailing receipt's pick-up & drop-off, used to label the route. */
    protected static function rideLocationRule(): string
    {
        return ', "pickup_location" (for a RIDE / e-hailing / taxi receipt — Grab, JustGrab, MyCar, AirAsia '
            .'ride, inDrive, etc. — the PICK-UP point / origin as a SHORT landmark name, e.g. "Ryan & Miho", '
            .'"Sunway Pyramid"; null for non-ride rows), '
            .'"dropoff_location" (the DROP-OFF / destination landmark on the same ride; null otherwise)';
    }

    /** Multi-item only: the Subject line of a forwarded e-receipt email, used to label the Item. */
    protected static function emailSubjectRule(): string
    {
        return ', "email_subject" (if this document is an EMAIL or a forwarded e-receipt showing a "Subject:" '
            .'line, return that subject text exactly, e.g. "Your Grab E-Receipt"; null when it is not an email)';
    }

    /** Multi-item only: payer fall-backs used when no explicit payer name is shown. */
    protected static function payerFallbackRule(): string
    {
        return ', "bill_to" (the "Bill to" / "Billed to" / "Bill To" name on an invoice or receipt, e.g. '
            .'"Darshni", "Enlinea Sdn Bhd"; null if none is shown), '
            .'"account_email" (the account owner\'s email — "Your account: …", "Contact: …@…", or any account '
            .'email on the document; null if none)';
    }

    protected static function distanceRule(): string
    {
        return '"distance_km" (ONLY if this is a map/route screenshot AND a clear total trip-distance '
            .'label like "34.3 km" or "18 km" is actually printed — return that number. If the '
            .'distance is hidden, blurred, cropped, or not clearly shown, return null. Do NOT guess '
            .'or infer it, and do NOT read it from road numbers (E37, AH2), exit/junction numbers, '
            .'durations ("1 hr 2 min"), or any other figure on the map), ';
    }

    protected static function routeRule(): string
    {
        return '"route_from" (the ORIGIN — on a Google Maps directions screenshot this is the TOP/FIRST '
            .'address field, marked with a circle "○" icon, where the trip STARTS; or null), '
            .'"route_to" (the DESTINATION — the BOTTOM/LAST address field, marked with a map-pin "📍" '
            .'icon, where the trip ENDS; or null), '
            .'"route_stops" (a map/route screenshot can list MORE THAN TWO stops — return an ARRAY of '
            .'ALL the stop addresses in order, top to bottom from the directions panel, including '
            .'every intermediate waypoint and the final destination even if it repeats the origin; '
            .'e.g. ["Motherhood Jaya One", "IOI City Mall", "Motherhood Jaya One"] for a there-and-back '
            .'trip. Use short landmark names. null when there are only two stops or it is a receipt). '
            .'distance_km MUST be the TOTAL distance of the whole multi-stop route as shown on the map. '
            .'For route_from, route_to and route_stops return the SHORT, recognisable landmark name only — the '
            .'building, mall, or area name that a map search would find (e.g. "Suria KLCC", '
            .'"Mid Valley Megamall", "Sunway Pyramid") — NOT the full printed street address with '
            .'lot / level / unit / floor numbers. '
            .'Do NOT swap origin and destination — the circle is always the start and the pin is always the end';
    }

    /** Normalise one receipt object's fields to the public shape. */
    protected static function normalizeReceipt(array $json, array $validCodes): array
    {
        // Validate the model's category against the allowed codes (case-insensitive);
        // discard anything it invented so the form never selects a bogus category.
        $category = null;
        if (! empty($validCodes) && isset($json['category']) && is_string($json['category'])) {
            $category = $validCodes[strtoupper(trim($json['category']))] ?? null;
        }

        return [
            // Always positive: statements print a charge as "- MYR 5.50" (a debit), but a
            // claim amount is the magnitude — abs() strips the sign so it passes min:0.
            'amount' => isset($json['amount']) && is_numeric($json['amount']) ? round(abs((float) $json['amount']), 2) : null,
            'tax_amount' => isset($json['tax_amount']) && is_numeric($json['tax_amount']) ? round(abs((float) $json['tax_amount']), 2) : null,
            'date' => (isset($json['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $json['date'])) ? $json['date'] : null,
            'vendor' => self::clip($json['vendor'] ?? null, 120),
            'item_description' => self::clip($json['item_description'] ?? null, 255),
            'paid_by' => self::payerOrNull(self::clip($json['paid_by'] ?? null, 120)),
            'category' => $category,
            'highlighted' => isset($json['highlighted']) ? filter_var($json['highlighted'], FILTER_VALIDATE_BOOLEAN) : false,
            'transaction_type' => is_string($json['transaction_type'] ?? null) ? strtolower(trim($json['transaction_type'])) : null,
            'entry_location' => self::clip($json['entry_location'] ?? null, 120),
            'exit_location' => self::clip($json['exit_location'] ?? null, 120),
            'pickup_location' => self::clip($json['pickup_location'] ?? null, 120),
            'dropoff_location' => self::clip($json['dropoff_location'] ?? null, 120),
            'email_subject' => self::clip($json['email_subject'] ?? null, 255),
            'bill_to' => self::clip($json['bill_to'] ?? null, 120),
            'account_email' => self::clip($json['account_email'] ?? null, 120),
        ];
    }

    /** Normalise the map/route fields from a (possibly partial) source array. */
    protected static function normalizeMap($src): array
    {
        $src = is_array($src) ? $src : [];

        return [
            'distance_km' => isset($src['distance_km']) && is_numeric($src['distance_km']) ? round((float) $src['distance_km'], 1) : null,
            'route_from' => self::clip($src['route_from'] ?? null, 120),
            'route_to' => self::clip($src['route_to'] ?? null, 120),
            'route_stops' => self::clipList($src['route_stops'] ?? null),
        ];
    }

    protected static function clip($v, int $len): ?string
    {
        return is_string($v) ? mb_substr(trim($v), 0, $len) : null;
    }

    /**
     * A payment METHOD is not a payer. Drop values that are ONLY a method (e.g. a cash receipt
     * with no name) so the "Who paid" field stays empty instead of saying "Cash". A real name
     * that merely contains such a word (e.g. "Cash Converters Sdn Bhd") is kept — the whole
     * string must BE the method.
     */
    protected static function payerOrNull(?string $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $t = trim($v);
        if ($t === '') {
            return null;
        }
        $methods = '(cash(\s*received)?|card|credit\s*card|debit(\s*card)?|visa|master\s*card|mastercard|amex|'
            .'grab\s*pay|grabpay|tng|touch\s*[\'’n]*\s*go|e[\s-]*wallet|qr(\s*pay)?|duitnow|fpx|boost|'
            .'shopee\s*pay|online\s*banking|bank\s*transfer|nets|paywave|contactless)';
        // The whole value is just a method (optionally trailed by an amount / card digits / masking).
        if (preg_match('/^'.$methods.'[\s:*x#\d.,()rm$-]*$/i', $t)) {
            return null;
        }

        return $t;
    }

    protected static function clipList($v): ?array
    {
        if (! is_array($v)) {
            return null;
        }
        $out = array_values(array_filter(array_map(fn ($s) => is_string($s) ? mb_substr(trim($s), 0, 120) : null, $v)));

        return $out ?: null;
    }
}
