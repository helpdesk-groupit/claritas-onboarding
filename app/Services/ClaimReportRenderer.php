<?php

namespace App\Services;

use App\Models\ExpenseClaim;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use setasign\Fpdi\Fpdi;
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
            // Encrypted, or a compressed cross-reference the free FPDI parser can't read.
            return array_merge($entry, ['reason' => 'the PDF could not be read (it may be encrypted or password-protected)']);
        }
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
            $provenance = 'Reproduced in full from the uploaded file '.$doc['filename'];

            self::appendPdf($pdf, $doc, $heading, $provenance);
        }

        return $pdf->Output('S');
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
