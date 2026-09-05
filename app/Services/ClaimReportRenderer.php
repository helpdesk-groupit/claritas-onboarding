<?php

namespace App\Services;

use App\Models\ExpenseClaim;
use App\Support\ClaimPdfPreview;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\CrossReference\CrossReferenceException;
use setasign\Fpdi\PdfParser\StreamReader;

/**
 * Renders a claim's report PDF and appends the ACTUAL uploaded PDF receipts/supporting
 * documents as real pages, the same way DecommissionReportRenderer does for the e-waste
 * report.
 *
 * Why: dompdf can rasterise a JPG/PNG receipt inline (report-pdf.blade.php already does
 * this via base64 <img>) but it can never embed another PDF document — and a PDF receipt
 * or invoice is a perfectly normal upload here (validated jpg,jpeg,png,pdf). Before this,
 * the form printed only "not embeddable in this PDF" for those and moved on, so the
 * approver's signed copy of record carried no evidence at all for that line. The merge
 * has to happen AFTER dompdf renders the form, so it cannot live in the Blade.
 */
class ClaimReportRenderer
{
    /** Millimetre margin around an appended page. */
    private const MARGIN = 8;

    /**
     * The claim report as raw PDF bytes, with uploaded PDF attachments appended.
     *
     * $company/$items are passed through unchanged to the existing view — this only adds
     * the resolved $appendix (so the placeholder text can say a document is reproduced
     * below) and the post-dompdf merge.
     */
    public static function render(ExpenseClaim $claim, $company, $items): string
    {
        $appendix = self::appendix($items);

        $body = Pdf::loadView('user.claims.report-pdf', [
            'claim' => $claim,
            'company' => $company,
            'items' => $items,
            'appendix' => $appendix,
        ])->setPaper('a4')->output();

        $documents = array_filter($appendix, fn ($d) => $d['appendable']);

        if ($documents === []) {
            return $body;
        }

        try {
            return self::merge($body, $documents, $claim);
        } catch (\Throwable $e) {
            // inspect() already opened each file with FPDI, so this is close to
            // unreachable — but a broken merge must never cost the whole download.
            Log::warning('Claim report: appending PDF attachments failed', [
                'claim' => $claim->claim_number,
                'error' => $e->getMessage(),
            ]);

            return $body;
        }
    }

    /**
     * Every non-image attachment across the claim's items, keyed by storage PATH — the
     * same path report-pdf.blade.php already resolves per row, so the view can look an
     * entry up with no extra bookkeeping. Deduped: a shared receipt (one file split across
     * several line items) must only be reproduced once. Image attachments are excluded —
     * they already render inline via dompdf and don't belong in the appendix.
     *
     * @return array<string, array{label:?string, path:string, filename:string, kind:?string, pages:int, appendable:bool, reason:?string, items:string[]}>
     */
    public static function appendix($items): array
    {
        $imageExt = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        $out = [];

        foreach ($items as $item) {
            $paths = array_unique(array_merge($item->attachmentPaths(), $item->supportingPaths()));
            foreach ($paths as $path) {
                $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                if (in_array($ext, $imageExt, true)) {
                    continue;
                }

                if (! isset($out[$path])) {
                    $out[$path] = self::inspect($path) + ['items' => []];
                }
                $out[$path]['items'][] = $item->description ?: ('Item #'.$item->id);
            }
        }

        return $out;
    }

    /**
     * How many pages of this PDF are worth rasterising in the browser.
     *
     * A PDF the parser can open needs images only as row decoration, so the row cap is enough.
     * One it CANNOT open needs every page, because those images are the only copy that will
     * reach the downloaded report. Deciding here keeps the expensive case rare: without it we
     * would either store 20 images for every receipt or truncate the ones that matter.
     *
     * Memoised per request — a claim page renders the same attachment in more than one place,
     * and probing the file once per appearance would parse it repeatedly for one answer.
     */
    public static function rasterBudgetFor(string $path): int
    {
        static $memo = [];

        if (! ClaimPdfPreview::isPdf($path)) {
            return 0;
        }

        if (! array_key_exists($path, $memo)) {
            $memo[$path] = (self::inspect($path)['kind'] ?? null) === 'pdf'
                ? ClaimPdfPreview::maxPages()
                : ClaimPdfPreview::storeLimit();
        }

        return $memo[$path];
    }

    /**
     * Resolve one uploaded file: what is it, and can we reproduce it?
     *
     * Uploads here are validated as jpg,jpeg,png,pdf — image extensions never reach this
     * (appendix() filters them out), so anything arriving is expected to be a PDF. Still
     * verified by MIME rather than assumed from the extension, same caution as the
     * decommission renderer.
     */
    private static function inspect(string $path): array
    {
        $entry = [
            'label' => null,
            'path' => $path,
            'filename' => basename($path),
            'kind' => null,
            'pages' => 0,
            'appendable' => false,
            'reason' => null,
        ];

        try {
            if (! Storage::disk('local')->exists($path)) {
                return array_merge($entry, ['reason' => 'the file is no longer on record']);
            }

            $mime = (string) Storage::disk('local')->mimeType($path);

            if ($mime !== 'application/pdf') {
                return array_merge($entry, ['reason' => 'the uploaded file is not a PDF']);
            }

            // Distinguish "this server is missing the PDF library" from "this PDF is
            // unreadable" — same reasoning as DecommissionReportRenderer::inspect().
            if (! class_exists(Fpdi::class)) {
                Log::error('Claim report: setasign/fpdi is not installed — run composer install');

                return array_merge($entry, ['reason' => 'the PDF support library is not installed on this server (contact IT)']);
            }

            $probe = new Fpdi;
            $pages = $probe->setSourceFile(StreamReader::createByString(Storage::disk('local')->get($path)));

            return array_merge($entry, ['kind' => 'pdf', 'pages' => $pages, 'appendable' => $pages > 0]);
        } catch (\Throwable $e) {
            // This branch used to swallow the exception and print one guess — "it may be
            // encrypted or password-protected" — for every possible cause. Measured against
            // all 119 PDF receipts on production, the 4 that fail are ALL
            // CrossReferenceException::COMPRESSED_XREF and NONE is encrypted, so the guess was
            // wrong every time it was shown and there was nothing in the log to correct it.
            Log::warning('Claim report: a PDF attachment could not be parsed by FPDI', [
                'path' => $path,
                'exception' => $e::class,
                'code' => $e->getCode(),
                'message' => $e->getMessage(),
            ]);

            return self::rasterFallback($path, $entry, self::unreadableReason($e));
        }
    }

    /**
     * Name the actual cause rather than guessing at it.
     *
     * The distinction is not cosmetic: "compressed" is a server limitation the employee can do
     * nothing about and IT can fix, while "encrypted" asks them to re-save the file. Telling
     * someone to remove a password from a document that has none wastes their time and hides
     * the real defect.
     */
    private static function unreadableReason(\Throwable $e): string
    {
        if ($e instanceof CrossReferenceException) {
            if ($e->getCode() === CrossReferenceException::COMPRESSED_XREF) {
                return 'this PDF uses a compression format the server cannot open directly';
            }

            if ($e->getCode() === CrossReferenceException::ENCRYPTED) {
                return 'the PDF is encrypted or password-protected';
            }
        }

        return 'the PDF could not be read';
    }

    /**
     * When FPDI cannot open the PDF, fall back to the pages pdf.js rasterised in the browser.
     *
     * This host has no Ghostscript, Imagick or Poppler, and the free FPDI parser cannot read a
     * compressed cross-reference stream — so without this the receipt reaches the downloaded
     * report as a sentence and nothing else, which is what an approver reads as the evidence
     * having been dropped. The images are a FAITHFUL RASTER, not the original vector document,
     * and the caption on every appended sheet says so; anyone needing the original still has
     * it on the claim record.
     *
     * Falls through to the plain reason when no previews exist yet, so a report downloaded
     * before anyone has opened the claim reads exactly as it did before rather than claiming a
     * picture is missing.
     */
    private static function rasterFallback(string $path, array $entry, string $reason): array
    {
        $images = ClaimPdfPreview::existing($path);

        if ($images === []) {
            return array_merge($entry, ['reason' => $reason]);
        }

        $total = ClaimPdfPreview::total($path);

        return array_merge($entry, [
            'kind' => 'pdf-raster',
            'images' => $images,
            'pages' => count($images),
            // Only the pages we actually hold are claimed as reproduced. A source longer than
            // what was captured must not be described as complete.
            'sourcePages' => $total,
            'appendable' => true,
            'reason' => null,
            'rasterReason' => $reason,
        ]);
    }

    /** Stitch the dompdf body and each source document into one file. */
    private static function merge(string $body, array $documents, ExpenseClaim $claim): string
    {
        $pdf = new Fpdi;
        $pdf->SetAutoPageBreak(false);
        $pdf->SetCreator('Claritas eClaim');

        self::copyBodyPages($pdf, $body);

        foreach ($documents as $doc) {
            $heading = ($claim->claim_number ?: 'Claim').' — Attachment for: '.implode(', ', array_unique($doc['items']));

            if (($doc['kind'] ?? null) === 'pdf-raster') {
                self::appendRaster($pdf, $doc, $heading);

                continue;
            }

            self::appendPdf($pdf, $doc, $heading, 'Reproduced in full from the uploaded file '.$doc['filename']);
        }

        return $pdf->Output('S');
    }

    /**
     * A PDF the parser could not open, reproduced from its rasterised page images.
     *
     * The provenance line states BOTH that this is a page image and why the original could not
     * be embedded. That matters more here than anywhere else in the report: this sheet carries
     * the evidence for an approved expense, and a reader has to be able to tell a faithful
     * raster from the source document — otherwise the caption would imply a fidelity the page
     * does not have.
     */
    private static function appendRaster(Fpdi $pdf, array $doc, string $heading): void
    {
        $held = count($doc['images']);
        $total = $doc['sourcePages'] ?: $held;

        // Never claim more coverage than we hold. If the browser captured 20 pages of a 30-page
        // statement, the caption says 20 of 30 and the shortfall is stated on the last sheet.
        $provenance = 'Page image of the uploaded file '.$doc['filename']
            .' — reproduced this way because '.($doc['rasterReason'] ?? 'the PDF could not be read');

        foreach ($doc['images'] as $i => $imagePath) {
            self::appendImage($pdf, $imagePath, $doc['filename'], $heading, $provenance, $i + 1, $total);
        }

        if ($total > $held) {
            // Its own sheet, because FPDF's Image() does not advance the cursor — writing this
            // after the last page would land the notice on top of the receipt it is about.
            [$pageW] = self::startPage($pdf, false);
            $top = self::caption($pdf, $pageW, $heading, $provenance, $doc['filename'], $held, $total);
            $pdf->SetXY(self::MARGIN, $top + 4);
            $pdf->SetFont('Helvetica', '', 9);
            $pdf->SetTextColor(153, 27, 27);
            $pdf->MultiCell($pageW - (2 * self::MARGIN), 4.5, self::latin(
                'Only '.$held.' of '.$total.' pages of this document could be reproduced here. '
                .'The complete file remains attached to the claim record and can be downloaded '
                .'from the system.'
            ), 0, 'L');
            $pdf->SetTextColor(0, 0, 0);
        }
    }

    /**
     * One rasterised page on its own captioned sheet.
     *
     * The bytes go through GD before FPDF sees them. That is NOT cosmetic: FPDF hand-parses
     * image data and, on a malformed file, can attempt an allocation large enough to kill the
     * process with a memory-exhaustion FATAL that no try/catch can trap — which would take out
     * the whole download. These images arrive from a browser, so they are exactly the input
     * that rule exists for. Same reasoning as DecommissionReportRenderer::appendImage().
     */
    private static function appendImage(Fpdi $pdf, string $imagePath, string $filename, string $heading, string $provenance, int $page, int $total): void
    {
        $jpeg = self::toBaselineJpeg((string) Storage::disk('local')->get($imagePath));

        if (! $jpeg) {
            [$pageW] = self::startPage($pdf, false);
            $top = self::caption($pdf, $pageW, $heading, $provenance, $filename, $page, $total);
            $pdf->SetXY(self::MARGIN, $top + 4);
            $pdf->SetFont('Helvetica', '', 9);
            $pdf->SetTextColor(153, 27, 27);
            $pdf->MultiCell($pageW - (2 * self::MARGIN), 4.5, self::latin(
                'This page could not be rendered into the report. The uploaded file remains '
                .'attached to the claim record and can be downloaded from the system.'
            ), 0, 'L');
            $pdf->SetTextColor(0, 0, 0);

            return;
        }

        [$bytes, $pxW, $pxH] = $jpeg;

        // FPDF reads images off the filesystem and the private disk is not guaranteed to be
        // local, so the bytes go through a temp file that is always cleaned up.
        $tmp = tempnam(sys_get_temp_dir(), 'claim_');
        file_put_contents($tmp, $bytes);

        try {
            [$pageW, $pageH] = self::startPage($pdf, $pxW > $pxH);
            $top = self::caption($pdf, $pageW, $heading, $provenance, $filename, $page, $total);
            // Pixels → millimetres at 96 dpi, the basis dompdf uses; fit() then scales it into
            // whatever space is left below the caption.
            [$x, $y, $w, $h] = self::fit($pxW / 96 * 25.4, $pxH / 96 * 25.4, $pageW, $pageH, $top);

            $pdf->Image($tmp, $x, $y, $w, $h, 'JPG');
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * Re-encode any GD-readable image to a baseline RGB JPEG, flattening transparency onto
     * white and capping the long edge.
     *
     * @return array{0:string, 1:int, 2:int}|null [jpeg bytes, width, height]
     */
    private static function toBaselineJpeg(string $bytes): ?array
    {
        $src = @imagecreatefromstring($bytes);

        if (! $src) {
            return null;
        }

        try {
            $w = imagesx($src);
            $h = imagesy($src);
            $max = 2000;

            if ($w > $max || $h > $max) {
                $scale = $max / max($w, $h);
                $w = max(1, (int) round($w * $scale));
                $h = max(1, (int) round($h * $scale));
            }

            $canvas = imagecreatetruecolor($w, $h);
            imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
            imagecopyresampled($canvas, $src, 0, 0, 0, 0, $w, $h, imagesx($src), imagesy($src));

            ob_start();
            $ok = imagejpeg($canvas, null, 88);
            $out = (string) ob_get_clean();
            imagedestroy($canvas);

            return $ok && $out !== '' ? [$out, $w, $h] : null;
        } catch (\Throwable $e) {
            return null;
        } finally {
            imagedestroy($src);
        }
    }

    /** The dompdf body, copied through at its original page size. */
    private static function copyBodyPages(Fpdi $pdf, string $body): void
    {
        $total = $pdf->setSourceFile(StreamReader::createByString($body));

        for ($i = 1; $i <= $total; $i++) {
            $template = $pdf->importPage($i);
            $size = $pdf->getTemplateSize($template);
            $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
            $pdf->useTemplate($template);
        }
    }

    /** Every page of an uploaded PDF, each on its own captioned sheet. */
    private static function appendPdf(Fpdi $pdf, array $doc, string $heading, string $provenance): void
    {
        $total = $pdf->setSourceFile(StreamReader::createByString(Storage::disk('local')->get($doc['path'])));

        for ($i = 1; $i <= $total; $i++) {
            $template = $pdf->importPage($i);
            $size = $pdf->getTemplateSize($template);

            [$pageW, $pageH] = self::startPage($pdf, $size['width'] > $size['height']);
            $top = self::caption($pdf, $pageW, $heading, $provenance, $doc['filename'], $i, $total);
            [$x, $y, $w, $h] = self::fit($size['width'], $size['height'], $pageW, $pageH, $top);

            $pdf->useTemplate($template, $x, $y, $w, $h);
        }
    }

    /** Start an A4 sheet in the source's orientation; returns its [width, height] in mm. */
    private static function startPage(Fpdi $pdf, bool $landscape): array
    {
        $pdf->AddPage($landscape ? 'L' : 'P', 'A4');

        return $landscape ? [297.0, 210.0] : [210.0, 297.0];
    }

    /** Centre a source of $srcW x $srcH in what's left below $top, preserving aspect ratio. */
    private static function fit(float $srcW, float $srcH, float $pageW, float $pageH, float $top): array
    {
        $boxW = $pageW - (2 * self::MARGIN);
        $boxH = $pageH - $top - self::MARGIN;
        $scale = min($boxW / $srcW, $boxH / $srcH);
        $w = $srcW * $scale;
        $h = $srcH * $scale;

        return [($pageW - $w) / 2, $top, $w, $h];
    }

    /**
     * The caption band identifying an appended document page: which claim line it
     * belongs to. Grows to fit, and returns the Y at which the document itself may start
     * so a long heading can never be overdrawn by the reproduction.
     */
    private static function caption(Fpdi $pdf, float $pageW, string $heading, string $provenance, ?string $filename, int $page, int $total): float
    {
        $width = $pageW - (2 * self::MARGIN);

        $pdf->SetY(self::MARGIN - 2);
        $pdf->SetX(self::MARGIN);
        $pdf->SetFont('Helvetica', 'B', 10);
        $pdf->SetTextColor(30, 58, 95);
        $pdf->MultiCell($width, 5, self::latin($heading), 0, 'L');

        $pdf->SetX(self::MARGIN);
        $pdf->SetFont('Helvetica', 'I', 6.5);
        $pdf->SetTextColor(148, 163, 184);
        $pdf->MultiCell($width, 3.2, self::latin($provenance.' · page '.$page.' of '.$total), 0, 'L');

        $bottom = $pdf->GetY() + 1.5;

        // Hairline under the caption so the band reads as ours, not the document's.
        $pdf->SetDrawColor(203, 213, 225);
        $pdf->Line(self::MARGIN, $bottom, $pageW - self::MARGIN, $bottom);
        $pdf->SetTextColor(0, 0, 0);

        return $bottom + 2.5;
    }

    /**
     * FPDF core fonts declare /WinAnsiEncoding, so CP1252 is the target — not ISO-8859-1,
     * which lacks the em-dash and would degrade it to a hyphen.
     */
    private static function latin(string $text): string
    {
        return (string) (@iconv('UTF-8', 'Windows-1252//TRANSLIT', $text) ?: $text);
    }
}
