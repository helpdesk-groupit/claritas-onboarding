<?php

namespace App\Services;

use App\Mail\EwasteAwaitingReportMail;
use App\Mail\EwasteRfqMail;
use App\Models\AssetDecommissionBatch;
use App\Models\AssetInventory;
use App\Models\DisposedAsset;
use App\Models\User;
use App\Models\Vendor;
use App\Notifications\DecommissionNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

/**
 * The quarterly e-waste sweep — gathers assets awaiting e-waste decommissioning
 * into a new cycle, RFQs the primary e-waste vendor, and reports to Finance.
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
            ->get();

        if ($rows->isEmpty()) {
            return [
                'batch' => null, 'gathered' => 0, 'rfq_sent' => false,
                'finance_notified' => false, 'message' => 'No assets awaiting e-waste decommissioning — nothing to sweep.',
            ];
        }

        $batch = AssetDecommissionBatch::create([
            'batch_number' => AssetDecommissionBatch::generateBatchNumber('e_waste'),
            'type' => AssetDecommissionBatch::TYPE_EWASTE,
            'status' => 'awaiting_quotation',
            'created_by' => $actorId,
        ]);

        // Stamp the batch on both the staging rows (line items) and the live assets
        // (so the batch can archive them + relate to them).
        $assetIds = $rows->pluck('asset_inventory_id')->filter()->all();
        DisposedAsset::whereIn('id', $rows->pluck('id'))->update(['decommission_batch_id' => $batch->id]);
        if ($assetIds) {
            AssetInventory::whereIn('id', $assetIds)->update(['decommission_batch_id' => $batch->id]);
        }

        // ── RFQ to the primary e-waste vendor (skip + alert IT if none set) ──
        $rfqSent = false;
        $vendor = Vendor::primaryEwaste();
        if ($vendor && $vendor->pic_email) {
            $batch->update(['vendor_id' => $vendor->id, 'rfq_sent_at' => now()]);
            try {
                Mail::to($vendor->pic_email)->send(new EwasteRfqMail($batch->fresh('vendor')));
                $rfqSent = true;
            } catch (\Throwable $e) {
                Log::error('E-waste RFQ email failed for '.$batch->batch_number.': '.$e->getMessage());
            }
        } else {
            // Don't fail the sweep — flag IT that no primary vendor is configured.
            self::notifyIt(new DecommissionNotification(
                event: 'ewaste.no_primary_vendor',
                batchNumber: $batch->batch_number,
                subject: 'E-waste RFQ skipped',
                message: "Cycle {$batch->batch_number} was created but no primary e-waste vendor is set — set one in Vendor Management to send the RFQ.",
                url: route('decommission.show', $batch),
                icon: 'bi-exclamation-triangle',
                color: 'warning',
            ));
        }

        // ── "Assets awaiting decommissioning" report to Finance ──
        // One email: TO the Finance Manager(s), CC the Finance Executive(s), work email only.
        $financeNotified = self::mailFinance(new EwasteAwaitingReportMail($batch->fresh(['vendor', 'items.asset'])));
        if ($financeNotified) {
            $batch->update(['finance_report_sent_at' => now()]);
        }

        return [
            'batch' => $batch->fresh(),
            'gathered' => $rows->count(),
            'rfq_sent' => $rfqSent,
            'finance_notified' => $financeNotified,
            'message' => "Cycle {$batch->batch_number} created with {$rows->count()} asset(s)."
                .($rfqSent ? ' RFQ sent to the primary e-waste vendor.' : ' No RFQ sent (no primary vendor).')
                .($financeNotified ? ' Finance notified.' : ''),
        ];
    }

    /**
     * Email a mailable to the finance team for the e-waste flow: TO the Finance
     * Manager(s), CC the Finance Executive(s), work email only. Returns true when at
     * least one recipient was addressed. Never throws — logs and returns false on failure.
     */
    public static function mailFinance($mailable): bool
    {
        $recipients = User::financeEmailRecipients();
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
            Log::error('E-waste finance email failed: '.$e->getMessage());

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
