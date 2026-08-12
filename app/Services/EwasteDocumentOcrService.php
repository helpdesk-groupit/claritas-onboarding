<?php

namespace App\Services;

/**
 * Reads the RM figure off an uploaded e-waste quotation or payment receipt so the amount
 * can be pre-filled instead of re-keyed.
 *
 * Extends ClaimReceiptOcrService purely to reuse its `callVision()` transport — provider /
 * model / key resolution, the Claude-API-page override, retry policy and usage recording
 * all already live there, and duplicating that is how the two drift apart.
 *
 * Like the claims OCR this **fails OPEN**: any missing key, unreadable document, unsupported
 * provider or malformed reply returns null and the amount is simply typed by hand. OCR must
 * never block a quotation or receipt upload — those gate the whole disposal cycle.
 */
class EwasteDocumentOcrService extends ClaimReceiptOcrService
{
    /** A quote/receipt above this is far likelier to be a misread than a real scrap-metal offer. */
    private const SANITY_CEILING = 1000000.0;

    /**
     * Can the configured provider read a PDF at all?
     *
     * Only Anthropic can here: it takes a native `document` block, whereas every other
     * provider goes through the OpenAI-compatible chat/completions shape whose only binary
     * part is `image_url` (a data:application/pdf URI is rejected). Rasterising the PDF to
     * an image instead is not an option on this host — no imagick, no ghostscript.
     */
    public static function pdfCapable(?string $company = null): bool
    {
        if (static::claude()) {
            return true;
        }

        $provider = config('claims.ocr.provider') ?: (static::settings($company)?->ai_provider ?? 'openai');

        return $provider === 'anthropic';
    }

    /**
     * The document's total as a float, or null when it can't be read confidently.
     *
     * @param  string  $kind  'quotation' (the vendor's offer) or 'receipt' (proof of payment)
     */
    public static function readAmount(string $absolutePath, string $mimeType, string $kind, ?string $company = null): ?float
    {
        if (! static::enabled($company) || ! is_readable($absolutePath)) {
            return null;
        }

        if ($mimeType === 'application/pdf' && ! static::pdfCapable($company)) {
            \Illuminate\Support\Facades\Log::info('E-waste OCR skipped: configured provider cannot read PDFs', [
                'kind' => $kind,
            ]);

            return null;
        }

        $subject = $kind === 'receipt'
            ? 'a PAYMENT RECEIPT for scrap/e-waste that a recycling vendor paid US'
            : 'a QUOTATION from a scrap/e-waste recycling vendor stating what they will PAY US';

        $prompt = 'You are reading '.$subject.'. Return STRICT JSON only, no prose, no code fence: '
            .'{"amount": number|null, "currency": string|null}. '
            .'"amount" is the single FINAL total payable — the grand total INCLUDING tax/SST, not a '
            .'subtotal and not an individual line item. Read digits exactly as printed; do not round '
            .'or recompute. Strip thousands separators and any currency symbol. If the document shows '
            .'more than one candidate total, take the largest clearly-labelled grand/net total. '
            .'"currency" is the ISO code if shown (e.g. "MYR" for RM), else null. '
            .'If you cannot find a total with confidence, return {"amount": null, "currency": null} — '
            .'a null is far better than a guess, because this figure is used for financial reporting.';

        // 2048, not ~200: on Claude Sonnet 5 (selectable on the Claude API page, and the only
        // model there that thinks by default) adaptive thinking runs when `thinking` is
        // omitted and max_tokens caps thinking + text together, so a ceiling sized to this tiny
        // JSON gets spent thinking and returns no text at all. Per-token billing makes the
        // headroom free on models that don't think.
        $json = static::callVision($prompt, $absolutePath, $mimeType, $company, 2048, 'ewaste_'.$kind.'_scan');

        return static::sanitizeAmount($json['amount'] ?? null);
    }

    /**
     * Coerce the model's `amount` to a usable float, or null.
     *
     * Rejects non-numerics, zero/negative (the vendor pays us — a zero offer is a misread, and
     * storing 0.00 would report "we were paid nothing" as fact) and absurd magnitudes, which is
     * the classic decimal-point or thousands-separator misread.
     */
    protected static function sanitizeAmount($value): ?float
    {
        if (is_string($value)) {
            $value = preg_replace('/[^0-9.\-]/', '', $value);
        }

        if (! is_numeric($value)) {
            return null;
        }

        $amount = round((float) $value, 2);

        return $amount > 0 && $amount <= self::SANITY_CEILING ? $amount : null;
    }
}
