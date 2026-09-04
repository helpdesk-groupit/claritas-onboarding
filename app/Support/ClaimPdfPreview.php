<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;

/**
 * Where the rasterised page images of a PDF claim attachment live, and nothing else.
 *
 * dompdf can embed a JPG/PNG receipt inline in its own row but can never embed another PDF,
 * so a PDF receipt printed only "not embeddable in this PDF" in the form while its pages were
 * appended after it by ClaimReportRenderer. The pages were genuinely there — verified against
 * a real production export (EC-2026-08-0061 downloads as 20 pages: 2 of form, 18 of receipt) —
 * but an approver reading the item rows saw a sentence where every OTHER line showed a
 * picture, and read that as the receipt having been dropped. These previews put a picture in
 * that row. They are a DISPLAY convenience: the appended pages remain the record, at full
 * vector fidelity, and are not replaced by anything here.
 *
 * The path is DERIVED from the PDF's own path rather than stored in a column, which is what
 * makes this safe against the module's file-sharing rule: makeCorrection() copies receipt
 * paths into the corrected claim's items, so one file is legitimately cited by several rows
 * (CLAUDE.md: "A receipt file is NOT owned by one row"). A derived name means the correction
 * and the frozen original resolve to the SAME preview with nothing to copy, sync or orphan.
 *
 * Rasterising happens in the BROWSER (pdf.js), because this host has no Imagick, Ghostscript
 * or Poppler — confirmed on the NAS, and the reason ClaimReceiptOcrService sends Anthropic a
 * native `document` block instead of an image. See ExpenseClaimController::storeReceiptPreview().
 */
class ClaimPdfPreview
{
    /**
     * Pages rasterised per PDF, at most.
     *
     * Bounded because this renders into a claim row: a 40-page statement would push the form
     * itself off the page it is supposed to summarise. Real receipts here run 1-3 pages. What
     * is not previewed is not lost — it is in the appended pages, which are complete.
     */
    public static function maxPages(): int
    {
        return max(1, (int) config('claims.pdf_preview.max_pages', 3));
    }

    /** Is this attachment one we would ever rasterise? */
    public static function isPdf(?string $path): bool
    {
        return $path !== null && strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'pdf';
    }

    /**
     * The preview for page N of a PDF attachment.
     *
     * Deliberately keeps the ".pdf" in the name and appends to it, so the preview sorts next
     * to its source and can never collide with a real upload: Laravel's ->store() names files
     * with a random 40-char basename plus ONE extension, so nothing it writes ends ".pdf.p1.jpg".
     */
    public static function pathFor(string $pdfPath, int $page): string
    {
        return $pdfPath.'.p'.$page.'.jpg';
    }

    /**
     * Preview pages that actually exist for this PDF, in page order.
     *
     * Stops at the first gap rather than probing every page: previews are written in order, so
     * a hole means generation was interrupted, and showing pages 1 and 3 of a receipt as though
     * they were consecutive misrepresents the document.
     *
     * @return string[]
     */
    public static function existing(string $pdfPath): array
    {
        if (! self::isPdf($pdfPath)) {
            return [];
        }

        $out = [];
        for ($page = 1; $page <= self::maxPages(); $page++) {
            $candidate = self::pathFor($pdfPath, $page);
            if (! Storage::disk('local')->exists($candidate)) {
                break;
            }
            $out[] = $candidate;
        }

        return $out;
    }

    /**
     * Drop every preview belonging to a PDF.
     *
     * Called wherever the source file itself is released. A preview outliving its source is a
     * picture of a receipt nothing can trace back to a claim — worse than no preview, because
     * it still renders.
     */
    public static function forget(string $pdfPath): void
    {
        if (! self::isPdf($pdfPath)) {
            return;
        }

        for ($page = 1; $page <= self::maxPages(); $page++) {
            $candidate = self::pathFor($pdfPath, $page);
            if (Storage::disk('local')->exists($candidate)) {
                Storage::disk('local')->delete($candidate);
            }
        }
    }
}
