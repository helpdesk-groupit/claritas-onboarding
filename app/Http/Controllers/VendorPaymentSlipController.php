<?php

namespace App\Http\Controllers;

use App\Models\Vendor;
use App\Models\VendorBillingDocument;
use App\Models\VendorDocumentScan;
use App\Models\VendorPaymentSlip;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Proof of payment for an invoice in the vendor billing register.
 *
 * NOT the payroll payslip (App\Models\Payslip) — this is a bank transfer slip, remittance
 * advice or receipt showing that a vendor's bill was settled.
 *
 * Filed the same way as every other vendor document: upload → read → correct the summary →
 * save. What makes this one different is its CONSEQUENCE. The Billing tab's Status column
 * is derived from whether a slip exists, so filing one is what marks an invoice Paid and
 * removing one is what marks it Pending again. That is the whole reason the operator cannot
 * simply set a status by hand any more: on a register whose value is provenance, "Paid"
 * ought to mean a document was produced, not that somebody chose it from a dropdown.
 *
 * ONE SLIP PER INVOICE, enforced by a unique index. Uploading against an invoice that
 * already has one REPLACES it, deliberately and loudly (the picker says so, and the flash
 * says what was replaced) rather than failing on a constraint the operator never saw.
 */
class VendorPaymentSlipController extends Controller
{
    private function authorizeManage(): void
    {
        if (! Auth::user()->canManageVendors()) {
            abort(403, 'No permission to manage vendors.');
        }
    }

    /**
     * File a scanned payment slip against one of this vendor's invoices.
     *
     * The invoice is CHOSEN by the operator, not read off the slip: a transfer slip often
     * names no invoice at all, and guessing which bill a payment settles is not a guess this
     * page is allowed to make. What the slip DOES say about an invoice number is stored
     * beside it and compared — see mismatches().
     */
    public function store(Request $request, Vendor $vendor)
    {
        $this->authorizeManage();

        $data = $request->validate([
            // Scoped to this vendor AND to invoices. A quotation is an offer, not a bill, so
            // paying one is not a state this register can represent; and without the vendor
            // scope a posted id would file our proof of payment on another company's bill.
            'vendor_billing_document_id' => [
                'required', 'integer',
                Rule::exists('vendor_billing_documents', 'id')
                    ->where('vendor_id', $vendor->id)
                    ->where('doc_type', 'invoice'),
            ],
            'scan_token' => 'required|string|max:64',
            'ai_summary' => 'nullable|string|max:6000',
            'companies_involved' => 'nullable|string|max:2000',
        ]);

        $invoice = VendorBillingDocument::findOrFail($data['vendor_billing_document_id']);

        $scan = $this->claimScan($vendor, $data['scan_token']);

        if (! $scan) {
            return $this->back($vendor)->with('error',
                'That upload is no longer available — it may have expired. Please attach the payment slip again.');
        }

        $replaced = $invoice->paymentSlip;

        // One transaction: dropping the old row and inserting the new one are two halves of
        // a REPLACEMENT, and the unique index means the second cannot run while the first is
        // outstanding. Half of it committing would leave the invoice reading Pending with a
        // slip file on disk that nothing points at.
        $slip = DB::transaction(function () use ($invoice, $scan, $data, $replaced) {
            $replaced?->delete();

            $slip = new VendorPaymentSlip($this->fieldsFromScan(is_array($scan->fields) ? $scan->fields : []));
            $slip->vendor_billing_document_id = $invoice->id;
            $slip->file_path = $scan->file_path;
            $slip->original_filename = $scan->original_filename;
            $slip->uploaded_by = Auth::id();

            // Carried across whole rather than re-read: it has been paid for, and a second
            // reading would replace the summary the operator just reviewed with one they
            // never saw.
            $slip->forceFill([
                'ai_status' => $scan->status,
                'ai_key_points' => $scan->key_points ?: null,
                'ai_text' => $scan->text,
                'ai_at' => now(),
            ]);
            $slip->ai_summary = $scan->summary;
            $slip->companies_involved = VendorPaymentSlip::parseCompaniesInput($data['companies_involved'] ?? null) ?: null;
            $slip->applySummaryEdit($data['ai_summary'] ?? null, Auth::id());

            $slip->save();

            return $slip;
        });

        // Outside the transaction, and only once it committed: a file deleted inside a
        // transaction that then rolled back is gone from disk with the row still pointing
        // at it. The row is the thing that must be consistent; the file is cleaned up after.
        if ($replaced) {
            $this->deleteFile($replaced->file_path);
        }

        // The record owns the file now; the staging row goes WITHOUT deleting it.
        $scan->delete();

        $number = $invoice->doc_number ?: 'this invoice';

        return $this->back($vendor)->with('success',
            ($replaced
                ? 'Payment slip for '.$number.' replaced — the invoice stays marked Paid.'
                : 'Payment slip filed against '.$number.' — the invoice is now marked Paid.')
            .$this->readingNote($slip)
            .$this->mismatchFlash($slip->fresh()));
    }

    /**
     * Correct the reading of a filed slip — its summary and the parties it names.
     *
     * No figures, for the same reason the invoice form asks for none: they are read off the
     * document, and a value no screen displays must not be settable from a request. A
     * misread amount is fixed by uploading the slip again, which re-reads it.
     */
    public function update(Request $request, Vendor $vendor, VendorPaymentSlip $slip)
    {
        $this->authorizeManage();
        $this->assertBelongs($vendor, $slip);

        $data = $request->validate([
            'ai_summary' => 'nullable|string|max:6000',
            'companies_involved' => 'nullable|string|max:2000',
        ]);

        $slip->companies_involved = VendorPaymentSlip::parseCompaniesInput($data['companies_involved'] ?? null) ?: null;
        $slip->applySummaryEdit($data['ai_summary'] ?? null, Auth::id());
        $slip->save();

        return $this->back($vendor)->with('success', 'Payment slip updated.');
    }

    /**
     * Remove a slip filed against the wrong invoice, or one that turned out not to prove
     * payment at all.
     *
     * This is the ONLY way an invoice goes back from Paid to Pending, which is why it exists
     * at all: with the status derived, a slip attached to the wrong bill would otherwise
     * leave that bill reading Paid forever with no control to say otherwise. The flash says
     * what the removal did to the invoice, because that consequence is the point and it
     * happens on a different row from the one the operator is looking at.
     */
    public function destroy(Vendor $vendor, VendorPaymentSlip $slip)
    {
        $this->authorizeManage();
        $this->assertBelongs($vendor, $slip);

        $number = $slip->document->doc_number ?: 'the invoice';

        $this->deleteFile($slip->file_path);
        $slip->delete();

        return $this->back($vendor)->with('success',
            'Payment slip removed — '.$number.' is marked Pending again.');
    }

    // ── Internals ─────────────────────────────────────────────────────────────

    /**
     * The slip has to reach this vendor THROUGH its invoice.
     *
     * Both ids come from the URL, so without this a slip could be edited or removed through
     * any other vendor's route — and removing one silently un-pays an invoice on a profile
     * the operator never opened.
     */
    private function assertBelongs(Vendor $vendor, VendorPaymentSlip $slip): void
    {
        abort_unless($slip->document?->vendor_id === $vendor->id, 404);
    }

    private function back(Vendor $vendor)
    {
        return redirect()->route('vendors.show', [$vendor, 'tab' => 'billing']);
    }

    /**
     * This operator's completed payment-slip scan for this vendor.
     *
     * Scoped by vendor, uploader AND kind, like every other claim of a staged upload: the
     * token travels in a request, and a contract or an invoice claimed here would be filed
     * as proof that a bill was paid.
     */
    private function claimScan(Vendor $vendor, string $token): ?VendorDocumentScan
    {
        return VendorDocumentScan::where('vendor_id', $vendor->id)
            ->where('user_id', Auth::id())
            ->where('kind', VendorDocumentScan::KIND_PAYMENT)
            ->where('token', $token)
            ->first();
    }

    /** The figures the reading produced, with the column default the reading may not supply. */
    private function fieldsFromScan(array $fields): array
    {
        return array_merge($fields, [
            'currency' => $fields['currency'] ?? VendorBillingDocument::DEFAULT_CURRENCY,
        ]);
    }

    /** Says what the reading managed, so a blank summary never reads as an empty document. */
    private function readingNote(VendorPaymentSlip $slip): string
    {
        if (in_array($slip->ai_status, ['ok', 'partial'], true)) {
            return '';
        }

        return ' '.(VendorPaymentSlip::aiNoteFor($slip->ai_status) ?: 'The payment slip was not read.');
    }

    /**
     * Say it on the way out, not just on the row.
     *
     * A slip that pays a different amount from the invoice, or names a different invoice
     * number, is most cheaply resolved on the day it is filed — by the person who has just
     * been looking at both documents. It never blocks the upload: both figures are machine
     * readings, so a mismatch is at least as likely to be a misread as a mis-payment.
     */
    private function mismatchFlash(VendorPaymentSlip $slip): string
    {
        $flag = $slip->mismatchFlag();

        return $flag ? ' ⚠ '.$flag.' Filed anyway — check both documents.' : '';
    }

    private function deleteFile(?string $path): void
    {
        if ($path && Storage::disk('local')->exists($path)) {
            Storage::disk('local')->delete($path);
        }
    }
}
