<?php

namespace App\Console\Commands;

use App\Models\AssetInventory;
use App\Models\DisposedAsset;
use App\Models\EwasteCompanyApprover;
use App\Models\RentalAssetAcknowledgement;
use App\Models\RentalAssetAcknowledgementItem;
use App\Models\User;
use App\Models\Vendor;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * One-off backfill: these Fixmaster rental laptops were already physically returned
 * (and, for two of them, replaced) before the AARF feature existed for them. IT recorded
 * who collected them and when as free text in dispose_assets.reason instead of raising a
 * proper Return AARF at the time. This creates real, fully-signed, backdated Return/Receipt
 * AARFs matching what actually happened, so the assets archive out of the live inventory
 * exactly as a live-signed return would, and the vendor's Report tab carries a full trail.
 *
 * Every value below (dates, collector identity, batching) was confirmed against the live
 * database and the operator before this was written — nothing here is guessed.
 *
 * TWO DELIBERATE STEPS, not one: `--commit` only creates the records/items, archives the
 * returned assets and stores the PDFs — nobody is emailed. `--notify` is a separate,
 * explicitly-triggered second run that sends the real notification (vendor PIC, IT, Finance,
 * Management) for whichever created batches haven't been notified yet, via the model's own
 * `distributeSignedCopy()` — the exact same method the live signing flow uses, not a
 * hand-copied duplicate. This lets the operator review what --commit created (the AARFs, the
 * PDFs, the archived assets) before anything goes out to four real inboxes about events that
 * happened weeks or months ago.
 *
 * Safe to re-run either mode: --commit skips any asset already carrying a matching-direction
 * item; --notify skips any batch whose `notified_at` is already set.
 */
class BackfillFixmasterRentalAarfs extends Command
{
    protected $signature = 'aarf:backfill-fixmaster
                            {--commit : Create the records/PDFs/archiving; without this flag nothing is saved}
                            {--notify : Send the real notification emails for already-created batches not yet notified. Run this only after --commit and after reviewing the created records.}';

    protected $description = 'Backfill backdated, fully-signed Return/Receipt AARFs for already-returned/exchanged Fixmaster rental laptops';

    private const VENDOR_NAME = 'FIXMASTER IT DISTRIBUTORS SDN BHD';

    private const SIGNER_EMAIL = 'helpdesk@claritas.asia';

    private const VENDOR_COLLECTOR_NAME = 'Tan Jian Hao';

    private const VENDOR_COLLECTOR_IC = '030204-10-0677';

    private const VENDOR_COLLECTOR_PHONE = '+60 12-693 6487';

    /** One row per real physical collection event. */
    private array $returnBatches = [
        ['company' => 'Enlinea Sdn. Bhd.', 'when' => '2026-04-17 12:00:00', 'assets' => ['FIXB2YVLX3']],
        ['company' => 'Enlinea Sdn. Bhd.', 'when' => '2026-07-03 11:17:02', 'assets' => ['FIX05483', 'FIX13594', 'FIX24992', 'FIX15093', 'FIX15443']],
        ['company' => 'Enlinea Sdn. Bhd.', 'when' => '2026-07-24 14:14:58', 'assets' => ['FIX70RSLX3']],
        ['company' => 'Enlinea Sdn. Bhd.', 'when' => '2026-07-31 14:25:12', 'assets' => ['FIX05832', 'FIX20367']],
        ['company' => 'Claritas Consulting (Asia) Sdn. Bhd.', 'when' => '2026-07-31 14:24:02', 'assets' => ['FIX16725']],
    ];

    /** One row per real physical delivery event. */
    private array $receiptBatches = [
        ['company' => 'Enlinea Sdn. Bhd.', 'when' => '2026-04-17 15:04:54', 'assets' => ['FIX-PW0C79DQ']],
        ['company' => 'Enlinea Sdn. Bhd.', 'when' => '2026-07-24 14:32:56', 'assets' => ['SPW0C99DF']],
    ];

    private const RELATIONS = ['items', 'vendor', 'creator', 'acknowledger', 'processorAcknowledger'];

    public function handle(): int
    {
        if ($this->option('notify')) {
            return $this->runNotify();
        }

        return $this->runCommit((bool) $this->option('commit'));
    }

    // ── --commit / dry-run ───────────────────────────────────────────────────

    private function runCommit(bool $commit): int
    {
        $vendor = Vendor::where('name', self::VENDOR_NAME)->first();
        if (! $vendor) {
            $this->error('Vendor "'.self::VENDOR_NAME.'" not found — aborting.');

            return self::FAILURE;
        }

        $signer = User::where('work_email', self::SIGNER_EMAIL)->first();
        if (! $signer) {
            $this->error('Signer user "'.self::SIGNER_EMAIL.'" not found — aborting.');

            return self::FAILURE;
        }

        $ourCollector = RentalAssetAcknowledgement::prefillCollector($signer);

        $this->info($commit
            ? 'COMMIT MODE — records will be created, PDFs stored, assets archived. No emails are sent by this step — run --notify separately for that.'
            : 'DRY RUN — nothing will be written. Re-run with --commit to apply.');
        $this->newLine();

        $summary = ['created' => 0, 'skipped' => 0, 'errors' => 0];

        $this->line('== Return AARFs (assets going back to the vendor) ==');
        foreach ($this->returnBatches as $i => $batch) {
            $this->processReturnBatch($i + 1, $batch, $vendor, $signer, $commit, $summary);
        }

        $this->newLine();
        $this->line('== Receipt AARFs (replacement assets delivered by the vendor) ==');
        foreach ($this->receiptBatches as $i => $batch) {
            $this->processReceiptBatch($i + 1, $batch, $vendor, $signer, $ourCollector, $commit, $summary);
        }

        $this->newLine();
        $this->info("Batches created: {$summary['created']}, assets skipped: {$summary['skipped']}, errors: {$summary['errors']}.");
        if ($commit && $summary['created'] > 0) {
            $this->info('Records are NOT yet emailed to anyone. Review them, then run: php artisan aarf:backfill-fixmaster --notify');
        }

        return $summary['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function processReturnBatch(int $n, array $batch, Vendor $vendor, User $signer, bool $commit, array &$summary): void
    {
        $when = Carbon::parse($batch['when']);
        $this->line("Return batch R{$n} — {$batch['company']} — {$when->format('d M Y H:i')}");

        $rows = collect();
        foreach ($batch['assets'] as $tag) {
            $asset = AssetInventory::where('asset_tag', $tag)->first();
            if (! $asset) {
                $this->error("  ! {$tag} — asset not found, skipping.");
                $summary['errors']++;

                continue;
            }
            if ($asset->decommissioned_at) {
                $this->warn("  - {$tag} — already decommissioned ({$asset->decommissioned_at}), skipping.");
                $summary['skipped']++;

                continue;
            }
            if ($asset->vendor_id !== $vendor->id || $asset->ownership_type !== 'rental') {
                $this->error("  ! {$tag} — not a rental asset linked to this vendor (vendor_id={$asset->vendor_id}, ownership_type={$asset->ownership_type}), skipping.");
                $summary['errors']++;

                continue;
            }

            $alreadyOnReturn = RentalAssetAcknowledgementItem::where('asset_inventory_id', $asset->id)
                ->where('direction', RentalAssetAcknowledgement::TYPE_RETURN)
                ->exists();
            if ($alreadyOnReturn) {
                $this->warn("  - {$tag} — already on a return AARF, skipping.");
                $summary['skipped']++;

                continue;
            }

            $reason = DisposedAsset::where('asset_inventory_id', $asset->id)
                ->where('decommission_type', 'vendor_return')
                ->latest('id')
                ->value('reason');

            $this->line("  + {$tag} — {$asset->brand} {$asset->model} — ".mb_strimwidth((string) $reason, 0, 90, '…'));
            $rows->push(['asset' => $asset, 'reason' => $reason]);
        }

        if ($rows->isEmpty()) {
            $this->warn('  (nothing to create for this batch)');

            return;
        }

        if (! $commit) {
            return;
        }

        DB::transaction(function () use ($rows, $batch, $vendor, $signer, $when) {
            $aarf = RentalAssetAcknowledgement::create([
                'reference' => RentalAssetAcknowledgement::generateReference(RentalAssetAcknowledgement::TYPE_RETURN, $when),
                'type' => RentalAssetAcknowledgement::TYPE_RETURN,
                'vendor_id' => $vendor->id,
                'company_rented_to' => $batch['company'],
                'status' => RentalAssetAcknowledgement::STATUS_ACKNOWLEDGED,
                'condition_confirmed' => true,
                'condition_remarks' => $rows->pluck('reason')->filter()->unique()->implode(' / '),
                'collector_company' => self::VENDOR_NAME,
                'collector_name' => self::VENDOR_COLLECTOR_NAME,
                'collector_ic' => self::VENDOR_COLLECTOR_IC,
                'collector_phone' => self::VENDOR_COLLECTOR_PHONE,
                'processor_remarks' => null,
                'processor_acknowledged_by' => $signer->id,
                'processor_acknowledged_at' => $when,
                'created_by' => $signer->id,
                'acknowledged_by' => $signer->id,
                'acknowledged_at' => $when,
            ]);

            foreach ($rows as $row) {
                $aarf->items()->create(
                    RentalAssetAcknowledgementItem::snapshotFrom($row['asset'], RentalAssetAcknowledgement::TYPE_RETURN)
                );
            }

            AssetInventory::whereIn('id', $rows->pluck('asset.id'))
                ->whereNull('decommissioned_at')
                ->update(['decommissioned_at' => $when]);

            $this->backdateCreatedAt($aarf, $when);
            $this->storePdfSafely($aarf);
        });

        $summary['created']++;
        $this->info("  ✓ created and archived {$rows->count()} asset(s).");
    }

    private function processReceiptBatch(int $n, array $batch, Vendor $vendor, User $signer, array $ourCollector, bool $commit, array &$summary): void
    {
        $when = Carbon::parse($batch['when']);
        $this->line("Receipt batch P{$n} — {$batch['company']} — {$when->format('d M Y H:i')}");

        $rows = collect();
        foreach ($batch['assets'] as $tag) {
            $asset = AssetInventory::where('asset_tag', $tag)->first();
            if (! $asset) {
                $this->error("  ! {$tag} — asset not found, skipping.");
                $summary['errors']++;

                continue;
            }
            if ($asset->vendor_id !== $vendor->id || $asset->ownership_type !== 'rental') {
                $this->error("  ! {$tag} — not a rental asset linked to this vendor (vendor_id={$asset->vendor_id}, ownership_type={$asset->ownership_type}), skipping.");
                $summary['errors']++;

                continue;
            }

            $alreadyOnReceipt = RentalAssetAcknowledgementItem::where('asset_inventory_id', $asset->id)
                ->where('direction', RentalAssetAcknowledgement::TYPE_RECEIPT)
                ->exists();
            if ($alreadyOnReceipt) {
                $this->warn("  - {$tag} — already on a receipt AARF, skipping.");
                $summary['skipped']++;

                continue;
            }

            $this->line("  + {$tag} — {$asset->brand} {$asset->model}");
            $rows->push(['asset' => $asset]);
        }

        if ($rows->isEmpty()) {
            $this->warn('  (nothing to create for this batch)');

            return;
        }

        if (! $commit) {
            return;
        }

        DB::transaction(function () use ($rows, $batch, $vendor, $signer, $ourCollector, $when) {
            $aarf = RentalAssetAcknowledgement::create([
                'reference' => RentalAssetAcknowledgement::generateReference(RentalAssetAcknowledgement::TYPE_RECEIPT, $when),
                'type' => RentalAssetAcknowledgement::TYPE_RECEIPT,
                'vendor_id' => $vendor->id,
                'company_rented_to' => $batch['company'],
                'status' => RentalAssetAcknowledgement::STATUS_ACKNOWLEDGED,
                'condition_confirmed' => true,
                'condition_remarks' => null,
                'collector_company' => $ourCollector['collector_company'],
                'collector_name' => $ourCollector['collector_name'],
                'collector_ic' => $ourCollector['collector_ic'],
                'collector_phone' => $ourCollector['collector_phone'],
                'vendor_rep_remarks' => null,
                'vendor_rep_company' => self::VENDOR_NAME,
                'vendor_rep_name' => self::VENDOR_COLLECTOR_NAME,
                'vendor_rep_ic' => self::VENDOR_COLLECTOR_IC,
                'vendor_rep_phone' => self::VENDOR_COLLECTOR_PHONE,
                'vendor_rep_acknowledged_at' => $when,
                'created_by' => $signer->id,
                'acknowledged_by' => $signer->id,
                'acknowledged_at' => $when,
            ]);

            foreach ($rows as $row) {
                $aarf->items()->create(
                    RentalAssetAcknowledgementItem::snapshotFrom($row['asset'], RentalAssetAcknowledgement::TYPE_RECEIPT)
                );
            }

            $this->backdateCreatedAt($aarf, $when);
            $this->storePdfSafely($aarf);
        });

        $summary['created']++;
        $this->info("  ✓ created {$rows->count()} asset(s).");
    }

    /**
     * Only `created_at` is backdated on the parent row — that's the fact that matters
     * ("when was this record established", read by the model's own activityLog()).
     * `updated_at` is left to advance naturally as storePdf()/distributeSignedCopy() genuinely
     * touch the row today; it's Eloquent bookkeeping, not an audited fact. Items are a
     * one-time snapshot nothing else ever touches, so both their timestamps are backdated.
     */
    private function backdateCreatedAt(RentalAssetAcknowledgement $aarf, Carbon $when): void
    {
        DB::table('rental_asset_acknowledgements')->where('id', $aarf->id)->update(['created_at' => $when]);
        DB::table('rental_asset_acknowledgement_items')->where('rental_asset_acknowledgement_id', $aarf->id)
            ->update(['created_at' => $when, 'updated_at' => $when]);
    }

    private function storePdfSafely(RentalAssetAcknowledgement $aarf): void
    {
        try {
            $aarf->fresh(self::RELATIONS)->storePdf();
        } catch (\Throwable $e) {
            Log::error("Backfill AARF PDF failed for {$aarf->reference}: ".$e->getMessage());
            $this->warn("    (PDF generation failed for {$aarf->reference} — see logs; record was still created.)");
        }
    }

    // ── --notify ─────────────────────────────────────────────────────────────

    private function runNotify(): int
    {
        $this->info('NOTIFY MODE — sending the real signed-copy email (vendor PIC, IT, Finance, Management) for every batch not yet notified.');
        $this->newLine();

        $summary = ['notified' => 0, 'skipped' => 0, 'missing' => 0, 'sendErrors' => 0];

        $this->line('== Return AARFs ==');
        foreach ($this->returnBatches as $i => $batch) {
            $this->notifyBatch('R'.($i + 1), $batch, RentalAssetAcknowledgement::TYPE_RETURN, $summary);
        }

        $this->newLine();
        $this->line('== Receipt AARFs ==');
        foreach ($this->receiptBatches as $i => $batch) {
            $this->notifyBatch('P'.($i + 1), $batch, RentalAssetAcknowledgement::TYPE_RECEIPT, $summary);
        }

        $this->newLine();
        $this->info("Notified: {$summary['notified']}, already notified (skipped): {$summary['skipped']}, AARF not found: {$summary['missing']}, batches with a failed leg: {$summary['sendErrors']}.");
        if ($summary['sendErrors'] > 0) {
            $this->warn('Check storage/logs/laravel.log for which recipient(s) failed on which batch.');
        }

        return ($summary['missing'] > 0 || $summary['sendErrors'] > 0) ? self::FAILURE : self::SUCCESS;
    }

    private function notifyBatch(string $label, array $batch, string $direction, array &$summary): void
    {
        $firstTag = $batch['assets'][0];
        $asset = AssetInventory::where('asset_tag', $firstTag)->first();
        $aarf = $asset
            ? RentalAssetAcknowledgementItem::where('asset_inventory_id', $asset->id)
                ->where('direction', $direction)
                ->first()?->acknowledgement
            : null;

        if (! $aarf) {
            $this->error("{$label} — no {$direction} AARF found for {$firstTag}. Run --commit first.");
            $summary['missing']++;

            return;
        }

        if ($aarf->notified_at) {
            $this->warn("{$label} — {$aarf->reference} already notified at {$aarf->notified_at}, skipping.");
            $summary['skipped']++;

            return;
        }

        $this->line("{$label} — {$aarf->reference} ({$batch['company']}, ".Carbon::parse($batch['when'])->format('d M Y').')');
        $this->line('    vendor PIC: '.($aarf->vendor?->pic_email ?: 'none on file — leg skipped'));
        $this->line('    IT: '.$this->describeRecipients(User::itEmailRecipients()));
        $this->line('    Finance: '.$this->describeRecipients(User::financeEmailRecipients()));
        $this->line("    Management ({$aarf->company_rented_to}): ".$this->describeRecipients([
            'to' => EwasteCompanyApprover::notificationEmailsFor($aarf->company_rented_to),
            'cc' => [],
        ]));

        $ok = $aarf->fresh(self::RELATIONS)->distributeSignedCopy();

        if ($ok) {
            $this->info('    ✓ notified.');
        } else {
            $this->warn('    ⚠ notified, but at least one recipient failed — see logs.');
            $summary['sendErrors']++;
        }
        $summary['notified']++;
    }

    private function describeRecipients(array $recipients): string
    {
        if (empty($recipients['to'])) {
            return 'none configured — leg skipped';
        }

        $to = implode(', ', (array) $recipients['to']);
        $cc = ! empty($recipients['cc']) ? ' (cc: '.implode(', ', (array) $recipients['cc']).')' : '';

        return $to.$cc;
    }
}
