<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * An uploaded vendor document that has been READ but not yet filed.
 *
 * Lives for the length of one Add-Document interaction: the operator picks a file, it is
 * stored and read here, they correct the summary the scan produced, and saving turns this
 * row into a VendorContract or VendorBillingDocument and deletes it. Anything left behind
 * is an abandoned upload, swept by `vendors:prune-document-scans`.
 *
 * Deliberately NOT the destination row in a draft state: an abandoned scan would then be a
 * half-filed contract sitting on the vendor profile, indistinguishable from a real one.
 */
class VendorDocumentScan extends Model
{
    public const KIND_CONTRACT = 'contract';

    public const KIND_BILLING = 'billing';

    /** Proof of payment for an invoice already in the register. */
    public const KIND_PAYMENT = 'payment';

    /**
     * Where each kind's file is stored — the SAME directories the filed records use, so
     * saving never has to move or copy the file (and a move that failed halfway would be a
     * record pointing at nothing). Every one of them must be registered in BOTH
     * secure_file_url() and SecureFileController::DIRECTORY_PERMISSIONS — the two lists are
     * independent, and a directory missing from either is a silent 404 or a silent 403.
     */
    public const DIRECTORIES = [
        self::KIND_CONTRACT => 'vendor_contracts',
        self::KIND_BILLING => 'vendor_billing',
        self::KIND_PAYMENT => 'vendor_payment_slips',
    ];

    /** Statuses that mean the read finished, whatever it found. */
    public const SETTLED_STATUSES = ['ok', 'partial', 'empty', 'skipped', 'disabled', 'failed'];

    protected $fillable = [
        'vendor_id', 'user_id', 'token', 'kind',
        'file_path', 'original_filename',
        'status', 'summary', 'key_points', 'text', 'companies', 'fields',
    ];

    protected $casts = [
        'key_points' => 'array',
        'companies' => 'array',
        'fields' => 'array',
    ];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public static function directoryFor(string $kind, int $vendorId): string
    {
        return (self::DIRECTORIES[$kind] ?? self::DIRECTORIES[self::KIND_CONTRACT]).'/'.$vendorId;
    }

    /** The read has finished — the modal can stop waiting, whatever it produced. */
    public function isSettled(): bool
    {
        return in_array($this->status, self::SETTLED_STATUSES, true);
    }

    /**
     * Whether the reading produced anything worth putting in front of the operator.
     *
     * A scan that failed is still SAVEABLE — the file is the record and a provider outage
     * must not stop a document being filed — it simply arrives with an empty summary box
     * and a stated reason.
     */
    public function hasReading(): bool
    {
        return filled($this->summary) || ! empty($this->key_points);
    }

    /** Scans abandoned before they were filed. */
    public function scopeStale(Builder $query, int $hours): Builder
    {
        return $query->where('created_at', '<', now()->subHours($hours));
    }

    /**
     * Drop the row and the file it was holding.
     *
     * Safe to delete the file unconditionally: the save path deletes this row and KEEPS the
     * file, so a row that still exists is by definition one whose file was never claimed by
     * a contract or billing record.
     */
    public function discard(): void
    {
        if ($this->file_path && Storage::disk('local')->exists($this->file_path)) {
            Storage::disk('local')->delete($this->file_path);
        }

        $this->delete();
    }

    /**
     * What the modal needs to render, and nothing else.
     *
     * The transcription is deliberately absent: it is up to 200 000 characters, it is not
     * shown or edited anywhere in the modal, and shipping it to the browser on every poll
     * would be the largest payload on the page for no reader.
     */
    public function toModalPayload(): array
    {
        return [
            'token' => $this->token,
            'status' => $this->status,
            'settled' => $this->isSettled(),
            'note' => self::modalNoteFor($this->status),
            'summary' => (string) $this->summary,
            'key_points' => $this->key_points ?? [],
            'companies' => $this->companies ?? [],
            'fields' => $this->fields ?? [],
            'filename' => $this->original_filename,
        ];
    }

    /**
     * What the reading did, worded for the ADD-DOCUMENT MODAL rather than a filed row.
     *
     * Every status but `failed` means the same thing in both places, so the shared wording
     * stands and the two cannot drift. `failed` does not: on a filed row the remedy is that
     * row's "Read the document again" button, and in this modal no such button can exist —
     * the record it belongs to has not been created yet. Naming it here sends the operator
     * hunting their screen for a control that is not on it, which is precisely what happened
     * the first time a contract was filed on live.
     *
     * What it names instead are the two things that ARE on this screen: Save (the file is
     * already stored, and a document that could not be read must never be an unfileable one)
     * and the file picker, which re-runs the read on a fresh choice.
     */
    protected static function modalNoteFor(?string $status): ?string
    {
        return $status === 'failed'
            ? 'The document could not be read. You can still save it and write the summary yourself, '
                .'or choose the file again to retry.'
            : VendorContract::aiNoteFor($status);
    }
}
