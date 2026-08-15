<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * AARF — the form acknowledging that rental assets physically changed hands with a vendor.
 *
 * NOT App\Models\Aarf. That one is the EMPLOYEE asset acknowledgement (tokenized email
 * link to a member of staff, `aarfs` table). This is the VENDOR-side rental handover.
 * The business calls both "AARF"; the two records share nothing.
 *
 * One form covers one vendor, one company, and the assets from that vendor which have
 * not been acknowledged before — see RentalAssetAcknowledgementItem's unique index,
 * which is what makes "not yet acknowledged" a database fact rather than a convention.
 */
class RentalAssetAcknowledgement extends Model
{
    protected $table = 'rental_asset_acknowledgements';

    /** We received rental assets FROM the vendor. */
    public const TYPE_RECEIPT = 'receipt';

    /** We handed rental assets BACK to the vendor. Same form, opposite direction. */
    public const TYPE_RETURN = 'return';

    /**
     * The label printed as "Type of process" on the form.
     *
     * The two documents carry identical content; only this wording changes with the
     * direction, which is why they are one format and not two.
     */
    public const TYPE_LABELS = [
        self::TYPE_RECEIPT => 'Receipt of rental asset',
        self::TYPE_RETURN => 'Return of rental asset',
    ];

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACKNOWLEDGED = 'acknowledged';

    protected $fillable = [
        'reference', 'type', 'vendor_id', 'company_rented_to', 'status',
        'condition_confirmed', 'condition_remarks',
        // The second party's reply to the damage note. WHICH column is filled depends on
        // the direction, because the parties swap: on a receipt the vendor's delivery rep
        // answers us; on a return we answer the vendor's collector. Two columns rather than
        // one neutral one, so the column always names the party that actually wrote it.
        //
        // Each party signs their own reply, and the signature takes the shape their
        // identity allows: the vendor's rep types theirs (no account), our processor's is
        // the account reference itself.
        'processor_remarks', 'processor_acknowledged_by', 'processor_acknowledged_at',
        'vendor_rep_remarks', 'vendor_rep_company', 'vendor_rep_name',
        'vendor_rep_ic', 'vendor_rep_phone', 'vendor_rep_acknowledged_at',
        'collector_company', 'collector_name', 'collector_ic', 'collector_phone',
        'created_by', 'acknowledged_by', 'acknowledged_at', 'pdf_path',
    ];

    protected $casts = [
        'condition_confirmed' => 'boolean',
        'acknowledged_at' => 'datetime',
        'vendor_rep_acknowledged_at' => 'datetime',
        'processor_acknowledged_at' => 'datetime',
    ];

    // ── Relations ───────────────────────────────────────────────────────────
    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }

    public function items()
    {
        return $this->hasMany(RentalAssetAcknowledgementItem::class)->orderBy('asset_tag');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The account that CLOSED the document.
     *
     * On a receipt that is the signatory: our receiving staff signed it themselves. On a
     * return it is the account the handover was PROCESSED under — the closing signatory
     * there is the vendor's collector, named in the collector details and stamped by
     * `acknowledged_at`. Both readings are on the form; do not print this as "signed by"
     * without checking the direction.
     */
    public function acknowledger()
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    /** Our staff member who signed the reply to a return collector's condition remarks. */
    public function processorAcknowledger()
    {
        return $this->belongsTo(User::class, 'processor_acknowledged_by');
    }

    // ── State ───────────────────────────────────────────────────────────────

    /**
     * Is the document FINISHED — meaning BOTH parties have signed it?
     *
     * The handover is an agreement between two parties, so one signature does not close it.
     * `status` is flipped by whichever acknowledgement lands SECOND, in either order, and
     * that moment is what archives a return's assets, stores the PDF and mails the signed
     * copy. Neither party is blocked by the other having gone first.
     *
     * It was one signature until 2026-08-13: the "closing signatory" (our staff on a
     * receipt, the vendor's collector on a return) locked the form, and the second party's
     * reply had to be squeezed in beforehand or not at all — a document signed by one side
     * asserting that both sides agreed.
     *
     * Everything that asks "is this piece of work finished?" reads this, and everything that
     * asks "is this form still open?" reads `status = draft` — including openReturnFormFor()
     * and the asset listing's open panel, which therefore keep protecting a half-signed
     * form's assets without needing to know about the two-signature rule at all.
     */
    public function isAcknowledged(): bool
    {
        return $this->status === self::STATUS_ACKNOWLEDGED;
    }

    /**
     * Has the MAIN declaration been signed — the tick box, the condition note and the
     * collector's identity?
     *
     * Deliberately separate from isAcknowledged(): this is one party's act, and it locks
     * that party's own fields and nothing else. On a receipt it is us; on a return it is the
     * vendor's collector.
     */
    public function mainAcknowledged(): bool
    {
        return $this->acknowledged_at !== null;
    }

    /** Both signatures present — the condition status flips on. */
    public function bothPartiesAcknowledged(): bool
    {
        return $this->mainAcknowledged() && $this->secondPartyAcknowledged();
    }

    /**
     * Has EITHER party signed?
     *
     * The delete guard reads this, not isAcknowledged(). A half-signed form is still `draft`,
     * so guarding deletion on the status alone would let a discard destroy a signature that
     * one of the two parties had already given.
     */
    public function anyPartyAcknowledged(): bool
    {
        return $this->mainAcknowledged() || $this->secondPartyAcknowledged();
    }

    /**
     * What each side is called on this form, in the words the buttons and messages use.
     *
     * The parties swap with the direction, so every user-facing sentence about "the other
     * party" resolves through these rather than hard-coding a role that is only right in one
     * direction.
     */
    public function mainPartyLabel(): string
    {
        return $this->isReturn() ? 'Collector' : 'Company PIC';
    }

    public function secondPartyLabel(): string
    {
        return $this->isReturn() ? 'Company PIC' : 'Vendor PIC';
    }

    /** Whoever has not signed yet, for an "Awaiting …" badge. Null once both have. */
    public function awaitingPartyLabel(): ?string
    {
        return match (true) {
            ! $this->mainAcknowledged() && ! $this->secondPartyAcknowledged() => null,
            ! $this->mainAcknowledged() => $this->mainPartyLabel(),
            ! $this->secondPartyAcknowledged() => $this->secondPartyLabel(),
            default => null,
        };
    }

    /** A signed form is evidence — it is never edited, only read or re-printed. */
    public function isEditable(): bool
    {
        return ! $this->isAcknowledged();
    }

    /**
     * Has the vendor's delivery representative signed their reply?
     *
     * Read off the timestamp rather than the remarks: the two are written together in one
     * action, so a filled remark is always signed — but only the timestamp says WHEN, and
     * a signature is a moment, not a body of text.
     */
    public function vendorRepAcknowledged(): bool
    {
        return $this->vendor_rep_acknowledged_at !== null;
    }

    /**
     * Has OUR processor signed their reply to a return collector's condition remarks?
     *
     * The return-side twin of vendorRepAcknowledged(), and read off the timestamp for the
     * same reason: remarks and signature are written in one action, so a filled remark is
     * always signed — but only the timestamp says when.
     */
    public function processorAcknowledged(): bool
    {
        return $this->processor_acknowledged_at !== null;
    }

    public function typeLabel(): string
    {
        return self::TYPE_LABELS[$this->type] ?? self::TYPE_LABELS[self::TYPE_RECEIPT];
    }

    /** Which way the assets moved, in the words the form needs mid-sentence. */
    public function isReceipt(): bool
    {
        return $this->type !== self::TYPE_RETURN;
    }

    public function isReturn(): bool
    {
        return $this->type === self::TYPE_RETURN;
    }

    /**
     * Who signs the second-party block, and which column holds their words.
     *
     * On a RECEIPT we note the damage and the vendor's delivery representative answers it,
     * signing their own typed name because they have no account. On a RETURN the parties
     * swap exactly: the vendor's collector notes what they would not accept, and our own
     * staff answer — signing with the account reference, because they are logged in.
     *
     * Both directions are therefore TWO submits, and in both the second party signs BEFORE
     * the closing signatory locks the document. Only the shape of the signature differs,
     * because only one of the two parties ever has an account.
     */
    public function secondPartyRemarks(): ?string
    {
        return $this->isReturn() ? $this->processor_remarks : $this->vendor_rep_remarks;
    }

    /**
     * Has the second party signed their reply, whichever party that is in this direction?
     *
     * This is the one question the "you may no longer edit the note they answered" rule
     * asks, so it must be asked direction-agnostically. Testing `vendorRepAcknowledged()`
     * alone was correct only while returns had no second signature — on a return it is
     * always false, which would have left `condition_remarks` editable after our processor
     * had signed a reply to it, putting a different question above their name.
     */
    public function secondPartyAcknowledged(): bool
    {
        return $this->isReturn() ? $this->processorAcknowledged() : $this->vendorRepAcknowledged();
    }

    /** When the second party signed, in whichever column this direction records it. */
    public function secondPartyAcknowledgedAt(): ?Carbon
    {
        return $this->isReturn() ? $this->processor_acknowledged_at : $this->vendor_rep_acknowledged_at;
    }

    /**
     * Three states, not two — because a form with one of its two signatures on it is
     * neither a draft nor acknowledged, and calling it either would be a false statement
     * about a document whose whole purpose is to record who agreed to what.
     */
    public function statusBadge(): array
    {
        if ($this->isAcknowledged()) {
            return ['label' => 'Acknowledged', 'color' => 'success'];
        }

        if ($awaiting = $this->awaitingPartyLabel()) {
            return ['label' => 'Awaiting '.$awaiting, 'color' => 'warning'];
        }

        return ['label' => 'Draft', 'color' => 'secondary'];
    }

    /**
     * The process log — what happened to this form, oldest first.
     *
     * SCREEN ONLY. It is a record of the system's own handling of the document, not part of
     * the document: `vendors/aarf/pdf.blade.php` deliberately does not render it, so neither
     * the downloaded copy nor the one emailed to the vendor's PIC carries it.
     *
     * DERIVED from the columns the lifecycle already writes — there is deliberately no event
     * table behind this. Two reasons, and the second is the one that decided it: everything
     * in scope (raised → both signatures → closed) is already stored WITH its actor and its
     * timestamp, so a table would duplicate it and could then disagree with it; and a table
     * would start EMPTY, so every form signed before today would show a blank log — a
     * complete document reading as though nothing had ever happened to it. Backfilling rows
     * to cover that is the same fabrication the un-backfilled `quotation_uploaded_by`
     * refuses: we would be inventing an audit trail nobody recorded.
     *
     * The line that follows from it: this can only ever show what a column records. An event
     * with nowhere to live — a PDF downloaded, a copy emailed, a send that failed — is NOT
     * in here and must not be faked into it. Wanting one of those is a reason to add a real
     * event table, not a reason to guess here.
     *
     * Sorted by TIMESTAMP, never by the order the entries are built: both signatures are
     * order-free, so building order says nothing about what happened first.
     *
     * @return array<int, array{at: ?Carbon, title: string, by: ?string, by_meta: ?string, notes: array<int, string>, state: string}>
     */
    public function activityLog(): array
    {
        $assets = $this->items->count();
        $plural = fn (int $n) => $n.' asset'.($n === 1 ? '' : 's');
        $preparer = self::actorIdentity($this->creator);

        $entries = [[
            'at' => $this->created_at,
            'title' => 'Form raised',
            'by' => $preparer['name'] ?? null,
            'by_meta' => $preparer['details'] ?? null,
            'notes' => array_values(array_filter([
                $this->typeLabel(),
                $plural($assets).' listed on the form',
            ])),
            'state' => 'done',
        ]];

        if ($this->mainAcknowledged()) {
            $account = self::actorIdentity($this->acknowledger);

            $entries[] = [
                'at' => $this->acknowledged_at,
                'title' => 'Acknowledged by the '.$this->mainPartyLabel(),
                // The SIGNATORY — whoever the collector details name, which is not always the
                // account holder. That distinction is the whole reason section 6 is editable.
                'by' => $this->collector_name ?: null,
                'by_meta' => $this->collector_company ?: null,
                'notes' => array_values(array_filter([
                    $this->condition_confirmed ? 'Condition of the assets confirmed' : null,
                    // The account is a fact about the SYSTEM's handling, not about who signed,
                    // which is exactly why it reads here — it is the log's own subject matter,
                    // and the sign-off panel it used to sit in was cut back to the parties on
                    // 2026-08-14. Section 7's sentence still carries it on the document itself.
                    $account ? 'Processed under the account of '.$account['name'] : null,
                    filled($this->condition_remarks) ? 'Condition remarks recorded' : null,
                ])),
                'state' => 'done',
            ];
        }

        if ($this->secondPartyAcknowledged()) {
            $ours = self::actorIdentity($this->processorAcknowledger);

            $entries[] = [
                'at' => $this->secondPartyAcknowledgedAt(),
                'title' => 'Acknowledged by the '.$this->secondPartyLabel(),
                // The two signatures have different SHAPES because only one party ever has an
                // account: the vendor's representative types their identity, ours signs with
                // theirs. Reading one column for both would print an empty name on one
                // direction or the other.
                'by' => $this->isReturn() ? ($ours['name'] ?? null) : ($this->vendor_rep_name ?: null),
                'by_meta' => $this->isReturn() ? ($ours['details'] ?? null) : ($this->vendor_rep_company ?: null),
                'notes' => array_values(array_filter([
                    filled($this->secondPartyRemarks()) ? 'Remarks recorded in reply' : null,
                ])),
                'state' => 'done',
            ];
        }

        usort($entries, fn ($a, $b) => ($a['at']?->getTimestamp() ?? 0) <=> ($b['at']?->getTimestamp() ?? 0));

        $entries[] = $this->isAcknowledged()
            ? [
                // The later of the two signatures — the moment the document became final.
                // Never `updated_at`: that moves whenever anything is written to the row,
                // including the PDF path stored a moment afterwards.
                //
                // Deliberately does NOT say "both parties signed": a form closed under the
                // old single-signature rule is final with one signature on it, and this log
                // may not state something the record does not show.
                'at' => collect([$this->acknowledged_at, $this->secondPartyAcknowledgedAt()])->filter()->max(),
                'title' => 'Form closed',
                'by' => null,
                'by_meta' => null,
                'notes' => array_values(array_filter([
                    'The document is final and can no longer be edited',
                    $this->isReturn() ? $plural($assets).' archived out of the inventory' : null,
                ])),
                'state' => 'done',
            ]
            : [
                'at' => null,
                'title' => match (true) {
                    // awaitingPartyLabel() answers null for BOTH "nobody has signed" and
                    // "everybody has", so it cannot be the whole answer on its own.
                    $this->bothPartiesAcknowledged() => 'Awaiting closure',
                    ! $this->anyPartyAcknowledged() => 'Awaiting both parties',
                    default => 'Awaiting the '.$this->awaitingPartyLabel(),
                },
                'by' => null,
                'by_meta' => null,
                'notes' => ['The form closes once both parties have acknowledged it'],
                'state' => 'pending',
            ];

        return $entries;
    }

    /**
     * Name + "designation · department · company" for a sign-off line.
     *
     * Mirrors AssetDecommissionBatch::actorIdentity() deliberately — the two forms sit
     * next to each other in the same module and must sign off the same way.
     */
    public static function actorIdentity(?User $user): ?array
    {
        if (! $user) {
            return null;
        }

        $employee = $user->employee;

        return [
            'name' => $employee?->full_name ?: $user->name,
            'details' => collect([$employee?->designation, $employee?->department, $employee?->company])
                ->filter()
                ->implode(' · '),
        ];
    }

    /**
     * Collector details to pre-fill the form with, from the acknowledging user.
     *
     * On a receipt the collector is us, so these come off the account rather than being
     * typed. They are only ever a PRE-FILL: the fields stay editable because "normally"
     * is not "always" — a courier or a colleague without a login may be the one signing,
     * and the stored value must be who actually stood there.
     *
     * RECEIPTS ONLY. On a return the collector is the VENDOR'S courier, so pre-filling our
     * own employee's name and IC number into that block would not be a convenience — it
     * would put our staff's identity under a declaration the vendor made. The controller
     * passes an empty prefill for a return; do not "fix" that by calling this there.
     */
    public static function prefillCollector(?User $user): array
    {
        $employee = $user?->employee;

        return [
            'collector_company' => $employee?->company,
            'collector_name' => $employee?->full_name ?: $user?->name,
            // IC/passport sits on the employee row itself (copied there from the
            // onboarding's personal details at activation). Phone never was, so it comes
            // off personal_details through the onboarding — Employee has no phone column.
            // Both are absent for a user with no HR record; the fields are typed then.
            'collector_ic' => $employee?->official_document_id,
            'collector_phone' => $employee?->onboarding?->personalDetail?->personal_contact_number,
        ];
    }

    /**
     * Atomic per-year sequence, e.g. RRA-2026-0001.
     *
     * Locked exactly like AssetDecommissionBatch::generateBatchNumber() — two people
     * generating a form in the same second must not both claim the same number, since
     * `reference` is unique and the loser's insert would simply fail.
     */
    public static function generateReference(string $type, ?Carbon $date = null): string
    {
        $date = $date ?? now();
        $prefixes = config('vendors.aarf_prefixes', [
            self::TYPE_RECEIPT => 'RRA',
            self::TYPE_RETURN => 'RTA',
        ]);
        $prefix = ($prefixes[$type] ?? 'RRA').'-'.$date->year.'-';

        return DB::transaction(function () use ($prefix) {
            $last = static::where('reference', 'like', $prefix.'%')
                ->lockForUpdate()
                ->orderByDesc('reference')
                ->value('reference');

            $seq = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

            return $prefix.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
        });
    }

    /**
     * The day the acknowledgement process began, or null to track every asset.
     *
     * Null on an unset or unparseable value ON PURPOSE. The two ways to be wrong are not
     * symmetric: tracking an old asset produces a form somebody notices and discards,
     * while silently NOT tracking new arrivals produces nothing at all — no form, no
     * badge, no error — and would go unnoticed indefinitely. So a bad date falls back to
     * tracking everything.
     */
    public static function trackFrom(): ?Carbon
    {
        $raw = config('vendors.aarf_track_from');

        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        try {
            return Carbon::parse($raw)->startOfDay();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Was this asset already with us before the acknowledgement process started? */
    public static function isPreExisting(AssetInventory $asset): bool
    {
        $from = self::trackFrom();

        return $from !== null && $asset->created_at !== null && $asset->created_at->lt($from);
    }

    /**
     * The assets from this vendor still waiting to be acknowledged.
     *
     * "Waiting" = rented from them, still live, registered on or after the start-tracking
     * date, and not already on ANY acknowledgement (draft or signed).
     *
     * Excluding drafts too is what stops a second click on Generate producing a duplicate
     * form for assets already sitting on an unsigned one; deleting that draft releases
     * them again. Excluding pre-dated assets is what stops switching the feature on from
     * demanding acknowledgement for every asset the company has ever rented — which would
     * bury the handful that genuinely just arrived.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int,AssetInventory>
     */
    public static function pendingAssetsFor(Vendor $vendor)
    {
        return AssetInventory::query()
            ->where('vendor_id', $vendor->id)
            ->where('ownership_type', 'rental')
            ->whereNull('decommissioned_at')
            ->when(self::trackFrom(), fn ($q, $from) => $q->where('created_at', '>=', $from))
            // Scoped to the receipt direction: the assets on a RETURN form are precisely the
            // ones already receipted, so an unscoped subquery would make every returned asset
            // look permanently acknowledged on the receipt side.
            ->whereNotIn('id', RentalAssetAcknowledgementItem::query()
                ->select('asset_inventory_id')
                ->where('direction', self::TYPE_RECEIPT))
            ->orderBy('company_supplied_to')
            ->orderBy('asset_tag')
            ->get();
    }

    // ── The RETURN direction (driven from the IT Decommissioning queue) ──────
    /**
     * Asset ids that already sit on a return form, mapped to that form.
     *
     * One query for the whole page — the Decommissioning queue needs to say, per row,
     * whether the asset is still awaiting a form and which form took it.
     *
     * @param  iterable<int>  $assetIds
     * @return \Illuminate\Support\Collection<int, RentalAssetAcknowledgement>
     */
    public static function returnFormsByAsset($assetIds)
    {
        $ids = collect($assetIds)->filter()->unique()->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        return RentalAssetAcknowledgementItem::query()
            ->where('direction', self::TYPE_RETURN)
            ->whereIn('asset_inventory_id', $ids)
            ->with('acknowledgement.vendor')
            ->get()
            ->keyBy('asset_inventory_id')
            ->map(fn ($item) => $item->acknowledgement)
            ->filter();
    }

    /**
     * The UNSIGNED return form an asset is committed to, if any.
     *
     * This is the successor to `dispose_assets.decommission_batch_id`, which used to be the
     * "this asset is spoken for, leave it alone" marker: the old vendor-return batch stamped
     * it at creation, and `AssetController` still guards two destructive paths on it. A
     * return AARF stamps nothing (there is no batch), so those guards had silently become
     * no-ops for returns and this is what they must ask instead.
     *
     * Only a DRAFT counts. Once the form is signed its assets carry `decommissioned_at`,
     * which every other guard already reads and which is a stronger statement.
     */
    public static function openReturnFormFor(int $assetId): ?self
    {
        return static::query()
            ->where('type', self::TYPE_RETURN)
            ->where('status', self::STATUS_DRAFT)
            ->whereHas('items', fn ($q) => $q->where('asset_inventory_id', $assetId))
            ->first();
    }

    /**
     * Sort assets picked out of the Decommissioning queue into the forms they will become.
     *
     * The vendor is DETECTED, never chosen: the asset already records who it is rented
     * from. Resolution is the `vendor_id` FK and nothing else — `rental_vendor` holds the
     * PIC's NAME since the 2026-08-06 change, so matching on it would resolve a person and
     * file the assets under whoever happened to share that name.
     *
     * Grouping is (vendor, company rented to), because one document names one legal entity
     * on one side and one vendor on the other. Ticking three vendors' assets together is a
     * normal thing to do from one queue page and produces three forms — it must never
     * produce one form filed under whichever vendor came first, which is what the old
     * hand-picked vendor dropdown allowed.
     *
     * Nothing is silently dropped. An asset that cannot become a form comes back in
     * `skipped` with the reason, because an asset the operator ticked and never heard about
     * again is one that stays on the books believing it was returned.
     *
     * @param  iterable<AssetInventory>  $assets
     * @return array{groups: array<string, array{vendor: Vendor, company: ?string, assets: \Illuminate\Support\Collection<int, AssetInventory>}>, skipped: \Illuminate\Support\Collection<int, array{asset: AssetInventory, reason: string}>}
     */
    public static function planReturns($assets): array
    {
        $assets = collect($assets)->filter()->values();
        $alreadyOnAForm = self::returnFormsByAsset($assets->pluck('id'));

        $groups = [];
        $skipped = collect();

        foreach ($assets as $asset) {
            if ($form = $alreadyOnAForm->get($asset->id)) {
                $skipped->push(['asset' => $asset, 'reason' => "already on return form {$form->reference}"]);

                continue;
            }

            // A company-owned asset marked Returned has no vendor to return it TO. Its
            // `vendor_id`, if set, is the supplier we BOUGHT it from — generating a "return
            // of rental asset" against them would invent a rental that never existed.
            if ($asset->ownership_type !== 'rental') {
                $skipped->push(['asset' => $asset, 'reason' => 'not a rental asset — nothing to return to a vendor']);

                continue;
            }

            if (! $asset->vendor_id || ! $asset->vendor) {
                $skipped->push(['asset' => $asset, 'reason' => 'no rental vendor linked on the asset record']);

                continue;
            }

            $company = trim((string) $asset->company_supplied_to);
            $key = $asset->vendor_id.'|'.$company;

            $groups[$key] ??= [
                'vendor' => $asset->vendor,
                'company' => $company !== '' ? $company : null,
                'assets' => collect(),
            ];
            $groups[$key]['assets']->push($asset);
        }

        return ['groups' => $groups, 'skipped' => $skipped];
    }

    /**
     * Soft-archive every asset on this form, which is what removes them from the active
     * inventory AND the Decommissioning queue (both filter `decommissioned_at IS NULL`).
     *
     * Only ever called once the form is signed. Idempotent — a re-run skips assets that are
     * already archived rather than re-stamping a later date over the real one.
     */
    public function archiveAssets(): int
    {
        $ids = $this->items()->pluck('asset_inventory_id')->filter();

        if ($ids->isEmpty()) {
            return 0;
        }

        return AssetInventory::whereIn('id', $ids)
            ->whereNull('decommissioned_at')
            ->update(['decommissioned_at' => now()]);
    }
}
