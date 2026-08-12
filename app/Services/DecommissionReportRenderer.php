<?php

namespace App\Services;

use App\Models\AssetDecommissionBatch;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\StreamReader;

/**
 * Renders the decommissioning report PDF and appends the ACTUAL uploaded
 * quotation / payment-receipt documents as real pages.
 *
 * Why a service and not just the Blade: dompdf can rasterise an image but it can
 * never embed another PDF, and every real vendor quote/receipt arrives as a PDF —
 * so the report used to end at "document attached to the file record (non-image,
 * not embedded)", i.e. the finance evidence was named but never shown. The page
 * merge therefore has to happen AFTER dompdf, which means it cannot live in the
 * view. Six call sites render this report (the stored archive copy, the two
 * ReportController download/stream routes, and four mailables) — they all go
 * through render() so none of them can drift back to a document-less PDF.
 *
 * Images keep their existing inline preview inside the body; only non-image
 * documents are appended.
 */
class DecommissionReportRenderer
{
    /** Millimetre margin around an appended page. */
    private const MARGIN = 8;

    /**
     * The archived report if one was already rendered for this batch, else a fresh render.
     *
     * Finalization stores the report and THEN mails it; without this the mailable rendered the
     * whole thing a second time — two dompdf passes plus two FPDI merges of a ~900 KB document,
     * inside the same synchronous upload request that already spent up to 45s on OCR. Identical
     * bytes, double the work, in the request most at risk of hitting max_execution_time.
     */
    public static function archivedOrRender(AssetDecommissionBatch $batch): string
    {
        try {
            if ($batch->report_pdf_path && Storage::disk('local')->exists($batch->report_pdf_path)) {
                return Storage::disk('local')->get($batch->report_pdf_path);
            }
        } catch (\Throwable $e) {
            // Fall through to a fresh render — a missing archive is never fatal.
        }

        return self::render($batch);
    }

    /**
     * The batch report as raw PDF bytes, with the uploaded source documents appended.
     */
    public static function render(AssetDecommissionBatch $batch): string
    {
        $batch->loadMissing(['vendor', 'items.asset', 'quotations.uploader', 'quotations.financeReviewer']);

        // Resolved once and handed to the view, so the body's wording and the pages we
        // actually append are decided by the same inspection of the same files.
        $appendix = self::appendix($batch);

        $body = Pdf::loadView('decommission.report-pdf', ['batch' => $batch, 'appendix' => $appendix])
            ->setPaper('a4')
            ->output();

        $documents = array_filter($appendix, fn ($d) => $d['appendable']);

        if ($documents === []) {
            return $body;
        }

        try {
            return self::merge($body, $documents, $batch);
        } catch (\Throwable $e) {
            // appendix() already opened each file with FPDI, so this is close to
            // unreachable — but a broken merge must never cost the whole report.
            Log::warning('Decommission report: appending source documents failed', [
                'batch' => $batch->batch_number,
                'error' => $e->getMessage(),
            ]);

            return $body;
        }
    }

    /**
     * The uploaded documents that belong at the end of the report, keyed by slot.
     *
     * render() resolves this once and passes it to the view, so the body's wording and
     * the actual appended pages can never disagree: the view says "reproduced on the
     * following pages" only for the entries returned as appendable, and states the
     * reason for the ones it can't. The view falls back to calling this itself when
     * rendered standalone (tests, or any future direct view() call).
     *
     * EVERY quotation revision is reproduced, not just the accepted one: when Finance
     * rejected an offer and the vendor re-quoted, the two documents are the only place the
     * change in price is evidenced, and a report that showed the second alone would read as
     * though the first never existed. Each superseded revision keeps its own key so the
     * ordering stays chronological (revision 1 … revision N, then the receipt).
     *
     * @return array<string, array{label:string, path:string, filename:string, pages:int, appendable:bool, reason:?string}>
     */
    public static function appendix(AssetDecommissionBatch $batch): array
    {
        if (! $batch->isEwaste()) {
            return [];
        }

        $batch->loadMissing(['quotations.uploader', 'quotations.financeReviewer']);

        $out = [];

        foreach (self::quotationSlots($batch) as $key => $slot) {
            $out[$key] = self::inspect($slot['label'], $slot['path']) + [
                'uploaded_at' => $slot['uploaded_at'],
                'uploader' => $slot['uploader'],
                'note' => $slot['note'],
            ];
        }

        if ($batch->receipt_path) {
            $out['receipt'] = self::inspect('Payment Receipt', $batch->receipt_path) + [
                'uploaded_at' => $batch->receipt_uploaded_at,
                'uploader' => AssetDecommissionBatch::actorIdentity($batch->receiptUploader),
                'note' => null,
            ];
        }

        return $out;
    }

    /**
     * One entry per quotation on file, oldest revision first.
     *
     * The current revision keeps the plain `quotation` key it has always had — the batch's
     * quotation_* columns are its cache, and callers (including this class's own tests) read
     * that slot by name. Superseded revisions sit before it under `quotation_rev{N}`.
     *
     * Falls back to the cache columns when there are no revision rows: any cycle whose
     * quotation predates the revision table has exactly one, recorded only there.
     *
     * @return array<string, array{label:string, path:string, uploaded_at:mixed, uploader:?array, note:?string}>
     */
    private static function quotationSlots(AssetDecommissionBatch $batch): array
    {
        $revisions = $batch->quotations->values();
        $total = $revisions->count();

        if ($total === 0) {
            return $batch->quotation_path ? ['quotation' => [
                'label' => 'Quotation',
                'path' => $batch->quotation_path,
                'uploaded_at' => $batch->quotation_uploaded_at,
                'uploader' => AssetDecommissionBatch::actorIdentity($batch->quotationUploader),
                'note' => null,
            ]] : [];
        }

        $out = [];

        foreach ($revisions as $i => $revision) {
            $isCurrent = $i === $total - 1;

            $out[$isCurrent ? 'quotation' : 'quotation_rev'.$revision->revision] = [
                // A single-quotation cycle reads exactly as it always did — the revision
                // numbering only appears once there is more than one document to tell apart.
                'label' => $total > 1
                    ? 'Quotation (revision '.$revision->revision.' of '.$total.')'
                    : 'Quotation',
                'path' => $revision->path,
                'uploaded_at' => $revision->uploaded_at,
                'uploader' => AssetDecommissionBatch::actorIdentity($revision->uploader),
                // Truncated: the reason can run to 1000 characters, and the caption band
                // grows downwards into the space the document itself is scaled into. The
                // full text is in the report body's revision table.
                'note' => $total > 1 ? Str::limit((string) $revision->decisionLine(), 240) ?: null : null,
            ];
        }

        return $out;
    }

    /**
     * "Uploaded {when} by {who}" — the provenance line under each document's caption,
     * plus the Finance decision on it when the cycle went round the re-quote loop (so a
     * reader can see at a glance which of two quotations was the rejected one).
     *
     * The uploader is null on batches created before the actor was captured; those pages
     * state the timestamp alone rather than a fabricated name.
     */
    private static function provenance(array $doc): string
    {
        $when = $doc['uploaded_at'] ? fmt_datetime($doc['uploaded_at']) : 'date not recorded';
        $who = $doc['uploader']['name'] ?? null;

        if ($who) {
            $details = $doc['uploader']['details'] ?? '';
            $line = 'Uploaded '.$when.' by '.$who.($details ? ' ('.$details.')' : '');
        } else {
            $line = 'Uploaded '.$when.' · uploader not recorded';
        }

        return ($doc['note'] ?? null) ? $line.' · '.$doc['note'] : $line;
    }

    /**
     * Resolve one uploaded file: what is it, and can we reproduce it?
     *
     * Uploads are validated as `pdf,jpg,jpeg,png`, so `kind` is 'pdf' or 'image'. Images
     * used to preview inline in the body; now that the body carries no document section
     * at all, they are appended as pages like everything else.
     */
    private static function inspect(string $label, string $path): array
    {
        $entry = [
            'label' => $label,
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

            if (str_starts_with($mime, 'image/')) {
                // Validate with the SAME decoder that will render it. getimagesize() only
                // reads the header and happily accepts a truncated or corrupt file, which
                // would mark it appendable here and then fail at render time.
                $image = @imagecreatefromstring(Storage::disk('local')->get($path));

                if (! $image) {
                    return array_merge($entry, ['reason' => 'the image file is corrupt or unreadable']);
                }

                imagedestroy($image);

                return array_merge($entry, ['kind' => 'image', 'pages' => 1, 'appendable' => true]);
            }

            if ($mime !== 'application/pdf') {
                return array_merge($entry, ['reason' => 'the uploaded file is not a PDF or an image']);
            }

            // Distinguish "this server is missing the PDF library" from "this PDF is unreadable".
            // Without this the catch below blamed the vendor's document — printing "it may be
            // encrypted or password-protected" into a financial report when the real cause is a
            // deploy that skipped `composer install` (the nas hook runs migrate, NOT composer).
            // Reporting a false reason to Finance is worse than reporting no reason.
            if (! class_exists(Fpdi::class)) {
                Log::error('Decommission report: setasign/fpdi is not installed — run composer install');

                return array_merge($entry, ['reason' => 'the PDF support library is not installed on this server (contact IT)']);
            }

            $probe = new Fpdi;
            $pages = $probe->setSourceFile(StreamReader::createByString(Storage::disk('local')->get($path)));

            return array_merge($entry, ['kind' => 'pdf', 'pages' => $pages, 'appendable' => $pages > 0]);
        } catch (\Throwable $e) {
            // Encrypted, or a compressed cross-reference the free FPDI parser can't
            // read. Say so in the report rather than silently dropping the evidence.
            return array_merge($entry, ['reason' => 'the PDF could not be read (it may be encrypted or password-protected)']);
        }
    }

    /** Stitch the dompdf body and each source document into one file. */
    private static function merge(string $body, array $documents, AssetDecommissionBatch $batch): string
    {
        $pdf = new Fpdi;
        $pdf->SetAutoPageBreak(false);
        $pdf->SetCreator('Claritas Asset Decommissioning');

        self::copyBodyPages($pdf, $body);

        foreach ($documents as $doc) {
            $heading = $batch->batch_number.' — '.$doc['label'];
            $provenance = self::provenance($doc);

            $doc['kind'] === 'image'
                ? self::appendImage($pdf, $doc, $heading, $provenance)
                : self::appendPdf($pdf, $doc, $heading, $provenance);
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

    /**
     * An uploaded image (a photographed or scanned quote/receipt) on a captioned sheet.
     *
     * The bytes are re-encoded through GD to a baseline RGB JPEG first. This is NOT
     * cosmetic: FPDF parses PNG chunks by hand, rejects interlaced files outright, and
     * mis-reads the row length on greyscale+alpha (colour type 4) — where it then tries to
     * allocate ~2 GB and dies with a memory-exhaustion FATAL that no try/catch can trap.
     * A single odd receipt PNG would otherwise take down report generation for the whole
     * cycle. Re-encoding also flattens alpha onto white, which is what a document wants.
     *
     * FPDF reads images off the filesystem and the private disk is not guaranteed to be
     * local, so the bytes go through a temp file that is always cleaned up.
     */
    private static function appendImage(Fpdi $pdf, array $doc, string $heading, string $provenance): void
    {
        $jpeg = self::toBaselineJpeg(Storage::disk('local')->get($doc['path']));

        if (! $jpeg) {
            // Still give the document a page — a blank omission would read as "no receipt".
            [$pageW] = self::startPage($pdf, false);
            $top = self::caption($pdf, $pageW, $heading, $provenance, $doc['filename'], 1, 1);
            $pdf->SetXY(self::MARGIN, $top + 4);
            $pdf->SetFont('Helvetica', '', 9);
            $pdf->SetTextColor(153, 27, 27);
            $pdf->MultiCell($pageW - (2 * self::MARGIN), 4.5, self::latin(
                'The uploaded image could not be rendered into this report. '
                .'It remains attached to the batch record and can be downloaded from the system.'
            ), 0, 'L');
            $pdf->SetTextColor(0, 0, 0);

            return;
        }

        [$bytes, $pxW, $pxH] = $jpeg;

        $tmp = tempnam(sys_get_temp_dir(), 'decom_');
        file_put_contents($tmp, $bytes);

        try {
            // Pixels → millimetres at 96 dpi, the basis dompdf uses, so a phone photo lands
            // at a sane size; fit() then scales it into the space below the caption anyway.
            [$pageW, $pageH] = self::startPage($pdf, $pxW > $pxH);
            $top = self::caption($pdf, $pageW, $heading, $provenance, $doc['filename'], 1, 1);
            [$x, $y, $w, $h] = self::fit($pxW / 96 * 25.4, $pxH / 96 * 25.4, $pageW, $pageH, $top);

            $pdf->Image($tmp, $x, $y, $w, $h, 'JPG');
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * Re-encode any GD-readable image to a baseline RGB JPEG, flattening transparency onto
     * white and capping the long edge (a 12 MP phone photo is far more than an A4 page can
     * show, and would bloat every emailed report).
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
     * The caption band identifying an appended document page: what it is, when it was
     * uploaded and by whom. Grows to fit, and returns the Y at which the document itself
     * may start so a long provenance line can never be overdrawn by the reproduction.
     */
    private static function caption(Fpdi $pdf, float $pageW, string $heading, string $provenance, ?string $filename, int $page, int $total): float
    {
        $width = $pageW - (2 * self::MARGIN);

        $pdf->SetY(self::MARGIN - 2);
        $pdf->SetX(self::MARGIN);
        $pdf->SetFont('Helvetica', 'B', 10);
        $pdf->SetTextColor(30, 58, 95);
        $pdf->Cell($width, 5, self::latin($heading), 0, 1, 'L');

        $pdf->SetX(self::MARGIN);
        $pdf->SetFont('Helvetica', '', 7.5);
        $pdf->SetTextColor(71, 85, 105);
        $pdf->MultiCell($width, 3.6, self::latin($provenance), 0, 'L');

        $pdf->SetX(self::MARGIN);
        $pdf->SetFont('Helvetica', 'I', 6.5);
        $pdf->SetTextColor(148, 163, 184);
        $pdf->MultiCell(
            $width,
            3.2,
            self::latin('Reproduced in full from the uploaded file'.($filename ? ' '.$filename : '').' · page '.$page.' of '.$total),
            0,
            'L'
        );

        $bottom = $pdf->GetY() + 1.5;

        // Hairline under the caption so the band reads as ours, not the document's.
        $pdf->SetDrawColor(203, 213, 225);
        $pdf->Line(self::MARGIN, $bottom, $pageW - self::MARGIN, $bottom);
        $pdf->SetTextColor(0, 0, 0);

        return $bottom + 2.5;
    }

    /**
     * FPDF core fonts declare /WinAnsiEncoding, so CP1252 is the target — not ISO-8859-1,
     * which lacks the em-dash and would degrade it to a hyphen. TRANSLIT still catches
     * anything genuinely outside the codepage (a name in a non-Latin script).
     */
    private static function latin(string $text): string
    {
        return (string) (@iconv('UTF-8', 'Windows-1252//TRANSLIT', $text) ?: $text);
    }
}
