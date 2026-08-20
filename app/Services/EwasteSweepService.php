<?php

namespace App\Services;

use App\Mail\EwasteAwaitingReportMail;
use App\Mail\EwasteCyclePostponedMail;
use App\Mail\EwasteRfqMail;
use App\Models\AssetDecommissionBatch;
use App\Models\AssetInventory;
use App\Models\DisposedAsset;
use App\Models\EwasteCompanyApprover;
use App\Models\User;
use App\Models\Vendor;
use App\Notifications\DecommissionNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

/**
 * The quarterly e-waste sweep — gathers assets awaiting e-waste decommissioning
 * into a new cycle, RFQs every active e-waste vendor, and reports to Finance.
 *
 * Shared by the scheduled command (ewaste:sweep-quarterly) and the throttled
 * "Run sweep now" button, so both behave identically. Network-independent from
 * the HTTP layer — safe to call from CLI (auth() may be null).
 */
class EwasteSweepService
{
    /**
     * Run one sweep. Returns a result array:
     *   ['batch' => ?AssetDecommissionBatch, 'gathered' => int, 'rfq_sent' => bool,
     *    'finance_notified' => bool, 'message' => string]
     */
    public static function sweep(?int $actorId = null): array
    {
        // Gather e-waste staging rows not yet collected into any cycle, whose asset
        // is still live (not soft-archived).
        $rows = DisposedAsset::where('decommission_type', 'e_waste')
            ->whereNull('decommission_batch_id')
            ->whereHas('asset', fn ($q) => $q->whereNull('decommissioned_at'))
            ->with('asset')
            ->get();

        if ($rows->isEmpty()) {
            return self::result(message: 'No assets awaiting e-waste decommissioning — nothing to sweep.');
        }

        // ── THE GATE (Phase 4) ────────────────────────────────────────────────
        // All or nothing: one asset that has not been inspected, or has been inspected
        // without its owning company confirmed, postpones the ENTIRE quarter. Nothing is
        // created — not even a cycle for the assets that are ready — because a part-swept
        // quarter would leave the rest sitting in a queue whose reminders have just reset,
        // and because the operator asked for the strict rule precisely so the backlog cannot
        // be worked around. The Phase 3 reminders exist to stop this arriving as a surprise.
        $blocking = $rows->reject(fn ($r) => $r->isReadyForCycle())->values();
        if ($blocking->isNotEmpty()) {
            self::notifyPostponed($blocking, $rows->count());

            return self::result(
                blocked: true,
                blocking: $blocking,
                message: "Cycle postponed — {$blocking->count()} of {$rows->count()} queued asset(s) are not ready "
                    .'(not inspected, or no owning company confirmed). Nothing was collected.',
            );
        }

        // ── ONE CYCLE PER COMPANY ─────────────────────────────────────────────
        // The management approver who authorises a disposal is per-company, so a mixed batch
        // would have nobody able to sign it and would name the wrong entity on the vendor's
        // paperwork. The gate above guarantees every row has a confirmed company, so no
        // "unassigned" bucket can arise here.
        $batches = collect();
        $rfqSentAny = false;
        $financeNotifiedAny = false;

        foreach ($rows->groupBy('company') as $company => $companyRows) {
            $batch = AssetDecommissionBatch::create([
                'batch_number' => AssetDecommissionBatch::generateBatchNumber('e_waste', null, $company),
                'type' => AssetDecommissionBatch::TYPE_EWASTE,
                'company' => $company,
                'status' => 'awaiting_quotation',
                'created_by' => $actorId,
            ]);

            // Stamp the batch on both the staging rows (line items) and the live assets
            // (so the batch can archive them + relate to them).
            $assetIds = $companyRows->pluck('asset_inventory_id')->filter()->all();
            DisposedAsset::whereIn('id', $companyRows->pluck('id'))->update(['decommission_batch_id' => $batch->id]);
            if ($assetIds) {
                AssetInventory::whereIn('id', $assetIds)->update(['decommission_batch_id' => $batch->id]);
            }

            if (self::sendRfq($batch)) {
                $rfqSentAny = true;
            }

            // ── Phase 5A — Finance AND management are told the process has started ──
            // One email per cycle: TO the Finance Manager(s), CC the Finance Executive(s).
            $fresh = $batch->fresh(['vendor', 'items.asset']);
            if (self::mailFinance(new EwasteAwaitingReportMail($fresh))) {
                $batch->update(['finance_report_sent_at' => now()]);
                $financeNotifiedAny = true;
            }

            // Management is notified now — that quotations are being requested — separately
            // from being ASKED TO DECIDE, which happens later once IT submits the comparison.
            // Named per company, same as the decision request, so an entity with nobody
            // configured is flagged rather than silently skipped.
            self::notifyManagementOfSweep($batch, $fresh);

            $batches->push($batch->fresh());
        }

        $summary = $batches
            ->map(fn ($b) => $b->batch_number.' ('.$b->company.', '.$b->items()->count().')')
            ->implode('; ');

        return self::result(
            batches: $batches,
            gathered: $rows->count(),
            rfqSent: $rfqSentAny,
            financeNotified: $financeNotifiedAny,
            message: $batches->count() === 1
                ? "Cycle {$summary} created."
                : "{$batches->count()} cycles created, one per company: {$summary}.",
        );
    }

    /**
     * The sweep's return shape, in one place so every exit agrees on the keys.
     *
     * `batches` is a Collection because a quarter now produces one cycle per company — there
     * is deliberately no `batch` key any more, so nothing can read "the" batch of a sweep that
     * legitimately made three.
     *
     * @return array{batches: \Illuminate\Support\Collection, blocked: bool, blocking: \Illuminate\Support\Collection, gathered: int, rfq_sent: bool, finance_notified: bool, message: string}
     */
    private static function result(
        $batches = null,
        bool $blocked = false,
        $blocking = null,
        int $gathered = 0,
        bool $rfqSent = false,
        bool $financeNotified = false,
        string $message = '',
    ): array {
        return [
            'batches' => $batches ?? collect(),
            'blocked' => $blocked,
            'blocking' => $blocking ?? collect(),
            'gathered' => $gathered,
            'rfq_sent' => $rfqSent,
            'finance_notified' => $financeNotified,
            'message' => $message,
        ];
    }

    /**
     * RFQ EVERY active e-waste vendor for one cycle (Phase 5). Never throws.
     *
     * It used to ask only the vendor flagged `is_primary_ewaste`, which made "compare the
     * offers and take the best price" impossible — there was only ever one offer. That flag
     * is gone entirely (migration 2026_08_15_100000): the market is asked, and who collects
     * is decided by management from the offers that come back.
     *
     * Each vendor is mailed and caught INDEPENDENTLY: one bad PIC address must not stop the
     * rest of the market being asked.
     */
    private static function sendRfq(AssetDecommissionBatch $batch): bool
    {
        $vendors = Vendor::ewasteRfqRecipients()->orderBy('name')->get();

        if ($vendors->isEmpty()) {
            // Don't fail the sweep — flag IT that nobody can be asked to quote.
            self::notifyIt(new DecommissionNotification(
                event: 'ewaste.no_rfq_vendor',
                batchNumber: $batch->batch_number,
                subject: 'E-waste RFQ skipped',
                message: "Cycle {$batch->batch_number} was created but no active e-waste vendor has a PIC email — add one in Vendor Management to send the RFQ.",
                url: route('decommission.show', $batch),
                icon: 'bi-exclamation-triangle',
                color: 'warning',
            ));

            return false;
        }

        // The batch's own vendor_id is a PLACEHOLDER until an offer is selected — it is
        // re-pointed at whoever wins when management choose. Nothing may read it as "the
        // vendor collecting" before that. It is the first invitee by name rather than by
        // insertion order purely so a cycle reads the same however the master was filled in.
        $batch->update(['vendor_id' => $vendors->first()->id, 'rfq_sent_at' => now()]);

        $sentAny = false;
        foreach ($vendors as $vendor) {
            try {
                Mail::to($vendor->pic_email)->send(new EwasteRfqMail($batch->fresh('vendor'), $vendor));
                $sentAny = true;
            } catch (\Throwable $e) {
                Log::error('E-waste RFQ email failed for '.$batch->batch_number.' to '.$vendor->name.': '.$e->getMessage());
            }
        }

        return $sentAny;
    }

    /**
     * Phase 5A — tell the cycle's company management that the quotation process has started,
     * at the SAME moment Finance is told (sweep time), not only once IT later submits a
     * comparison for their decision. Named per company, same as the later decision request —
     * an unnamed company falls back to nobody here (unlike the decision request, which falls
     * back to superadmins), so a missing configuration is flagged to IT rather than quietly
     * emailing a superadmin about a cycle they may have no stake in.
     */
    private static function notifyManagementOfSweep(AssetDecommissionBatch $batch, AssetDecommissionBatch $fresh): void
    {
        $approvers = EwasteCompanyApprover::configuredFor($batch->company);

        if ($approvers->isEmpty()) {
            self::notifyIt(new DecommissionNotification(
                event: 'ewaste.no_management_approver_at_sweep',
                batchNumber: $batch->batch_number,
                subject: 'No management approver configured',
                message: "Cycle {$batch->batch_number} opened for ".($batch->company ?: 'an unnamed company')
                    .', but no management approver is set for it — set one in Settings → E-Waste Approvers so they are told the process has started.',
                url: route('decommission.show', $batch),
                icon: 'bi-exclamation-triangle',
                color: 'warning',
            ));

            return;
        }

        $emails = $approvers->pluck('work_email')->filter()->unique()->values()->all();
        if ($emails) {
            try {
                Mail::to($emails)->send(new EwasteAwaitingReportMail($fresh, audience: 'management'));
            } catch (\Throwable $e) {
                Log::error('E-waste sweep-start email to management failed for '.$batch->batch_number.': '.$e->getMessage());
            }
        }

        Notification::send($approvers, new DecommissionNotification(
            event: 'ewaste.quotation_process_started',
            batchNumber: $batch->batch_number,
            subject: 'E-waste quotation process started',
            message: "Cycle {$batch->batch_number} for {$batch->company} has opened — quotations are being requested from every registered e-waste vendor. You'll be asked to approve once IT submits a comparison.",
            url: route('assets.index', ['tab' => 'company-decom']),
            icon: 'bi-recycle',
            color: 'info',
        ));
    }

    /**
     * Tell IT and Finance that the quarter was postponed, and exactly what is holding it.
     *
     * Both sides need it for different reasons: IT have to clear the list, and Finance are
     * expecting a quotation to approve and would otherwise simply never hear that the cycle
     * they were told about a month ago did not happen. A postponement nobody is told about is
     * indistinguishable from the system quietly failing.
     */
    private static function notifyPostponed($blocking, int $total): void
    {
        $next = EwasteInspectionReminderService::nextSweepDate(now()->addDay());

        self::mailIt(new EwasteCyclePostponedMail(
            blocking: $blocking, total: $total, nextSweepDate: $next, audience: 'it'
        ));
        self::mailFinance(new EwasteCyclePostponedMail(
            blocking: $blocking, total: $total, nextSweepDate: $next, audience: 'finance'
        ));

        self::notifyIt(new DecommissionNotification(
            event: 'ewaste.cycle_postponed',
            batchNumber: null,
            subject: 'E-waste cycle postponed',
            message: "The quarterly e-waste collection did not run: {$blocking->count()} of {$total} queued asset(s) "
                .'are not inspected. The next attempt is '.fmt_date($next).'.',
            url: route('assets.index', ['tab' => 'damaged']),
            icon: 'bi-exclamation-octagon',
            color: 'danger',
        ));
    }

    /** Email the IT team: TO the manager(s), CC the executive(s). Never throws. */
    public static function mailIt($mailable): bool
    {
        return self::mailRecipients(User::itEmailRecipients(), $mailable);
    }

    /**
     * Email a mailable to the finance team for the e-waste flow: TO the Finance
     * Manager(s), CC the Finance Executive(s), work email only. Returns true when at
     * least one recipient was addressed. Never throws — logs and returns false on failure.
     */
    public static function mailFinance($mailable): bool
    {
        return self::mailRecipients(User::financeEmailRecipients(), $mailable);
    }

    /** Shared body of mailIt()/mailFinance() so the two can't drift on the rules that matter. */
    private static function mailRecipients(array $recipients, $mailable): bool
    {
        if (empty($recipients['to'])) {
            return false;
        }

        try {
            $mail = Mail::to($recipients['to']);
            if (! empty($recipients['cc'])) {
                $mail->cc($recipients['cc']);
            }
            $mail->send($mailable);

            return true;
        } catch (\Throwable $e) {
            Log::error('E-waste team email failed: '.$e->getMessage());

            return false;
        }
    }

    /** Bell every IT manager / admin who owns the decommission flows. */
    public static function notifyIt(DecommissionNotification $notification): void
    {
        $recipients = User::whereIn('role', ['it_manager', 'superadmin', 'system_admin'])
            ->where('is_active', true)->get();
        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, $notification);
        }
    }
}
