<?php

namespace App\Services;

use App\Mail\EwasteInspectionReminderMail;
use App\Models\DisposedAsset;
use App\Models\User;
use App\Notifications\DecommissionNotification;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

/**
 * Phase 3 — the run-up to the quarterly collection.
 *
 * The cycle refuses to run while ANY queued e-waste asset is still uninspected, and a
 * postponement costs a whole quarter of storage. So the reminders exist to make sure that
 * never comes as a surprise: one a month out, then 15, 5 and 3 days, then on the day itself.
 *
 * Deliberately SILENT when there is nothing outstanding. A reminder that arrives whether or
 * not there is anything to do is one people learn to delete, and this one has to survive being
 * read four times a quarter.
 *
 * The date arithmetic is pure and separately testable — nothing here needs a mailer to answer
 * "is today a reminder day?".
 */
class EwasteInspectionReminderService
{
    /**
     * The marks, furthest out first. `days` null = a calendar month before (not 30 days), so
     * the notice always lands on the same day-of-month as the sweep.
     */
    public const MARKS = [
        'month' => ['days' => null, 'label' => '1 month'],
        'd15' => ['days' => 15, 'label' => '15 days'],
        'd5' => ['days' => 5, 'label' => '5 days'],
        'd3' => ['days' => 3, 'label' => '3 days'],
        'day' => ['days' => 0, 'label' => 'today'],
    ];

    /**
     * The first quarterly sweep date on or after $from — "on or after" so that the sweep day
     * itself resolves to today rather than skipping three months ahead.
     */
    public static function nextSweepDate(?CarbonInterface $from = null): Carbon
    {
        $from = $from ? Carbon::parse($from)->startOfDay() : now()->startOfDay();
        $day = max(1, (int) config('decommission.sweep_day', 1));

        foreach ([$from->year, $from->year + 1] as $year) {
            foreach ([1, 4, 7, 10] as $month) {
                $base = Carbon::create($year, $month, 1)->startOfDay();
                // A sweep_day past the end of a short month lands on its last day rather than
                // silently rolling into the next one.
                $candidate = $base->copy()->day(min($day, $base->daysInMonth));
                if ($candidate->gte($from)) {
                    return $candidate;
                }
            }
        }

        // Unreachable: the loop above always covers a full year ahead.
        return $from->copy();
    }

    /** The calendar date each mark falls on, for a given sweep date. */
    public static function markDates(CarbonInterface $sweepDate): array
    {
        $sweep = Carbon::parse($sweepDate)->startOfDay();
        $dates = [];
        foreach (self::MARKS as $key => $mark) {
            $dates[$key] = $mark['days'] === null
                ? $sweep->copy()->subMonthNoOverflow()
                : $sweep->copy()->subDays($mark['days']);
        }

        return $dates;
    }

    /**
     * Which mark today is, or null if today is not a reminder day. If two marks collide on one
     * date (possible only with an unusual sweep_day) the NEAREST to the sweep wins — the more
     * urgent wording is the honest one.
     */
    public static function markFor(?CarbonInterface $today = null): ?string
    {
        $today = $today ? Carbon::parse($today)->startOfDay() : now()->startOfDay();
        $dates = self::markDates(self::nextSweepDate($today));

        $hit = null;
        foreach ($dates as $key => $date) {
            if ($date->isSameDay($today)) {
                $hit = $key;   // later keys are nearer the sweep, so the last match wins
            }
        }

        return $hit;
    }

    /**
     * Queued e-waste assets that are not ready for a cycle — never inspected, or inspected
     * without a confirmed owner. Scoped to live assets, matching exactly what the sweep will
     * try to gather, so the count in a reminder can never disagree with what blocks the cycle.
     */
    public static function outstanding(): Collection
    {
        return DisposedAsset::awaitingInspection()
            ->whereHas('asset', fn ($q) => $q->whereNull('decommissioned_at'))
            ->with('asset')
            ->orderBy('disposed_at')
            ->get();
    }

    /**
     * Send the reminders for $today, if it is a mark and anything is outstanding.
     *
     * @return array{sent: bool, mark: ?string, count: int, message: string}
     */
    public static function run(?CarbonInterface $today = null, bool $force = false): array
    {
        $today = $today ? Carbon::parse($today)->startOfDay() : now()->startOfDay();
        $mark = self::markFor($today);

        if (! $mark) {
            if (! $force) {
                return ['sent' => false, 'mark' => null, 'count' => 0,
                    'message' => 'Not an inspection-reminder day. Skipping.'];
            }
            // --force is for testing the mail itself; treat it as the nearest mark so the
            // wording matches what an operator would actually receive on the day.
            $mark = 'day';
        }

        $rows = self::outstanding();
        if ($rows->isEmpty()) {
            return ['sent' => false, 'mark' => $mark, 'count' => 0,
                'message' => 'Every queued e-waste asset is inspected — no reminder sent.'];
        }

        $sweepDate = self::nextSweepDate($today);

        // IT own the inspections, so they are told at every mark.
        $itSent = self::mailTo(User::itEmailRecipients(), new EwasteInspectionReminderMail(
            mark: $mark, sweepDate: $sweepDate, rows: $rows, audience: 'it'
        ));

        // Finance get the one-month notice only — a heads-up that a cycle is coming, not an
        // inspection nag they cannot act on. (Management joins this line in Phase 5, once the
        // per-company approver mapping exists to say who they are.)
        $financeSent = false;
        if ($mark === 'month') {
            $financeSent = self::mailTo(User::financeEmailRecipients(), new EwasteInspectionReminderMail(
                mark: $mark, sweepDate: $sweepDate, rows: $rows, audience: 'finance'
            ));
        }

        self::bellIt($mark, $sweepDate, $rows->count());

        $label = self::MARKS[$mark]['label'];

        return [
            'sent' => $itSent || $financeSent,
            'mark' => $mark,
            'count' => $rows->count(),
            'message' => "Inspection reminder ({$label} to go): {$rows->count()} asset(s) outstanding."
                .($itSent ? ' IT notified.' : ' IT NOT notified (no recipients).')
                .($financeSent ? ' Finance notified.' : ''),
        ];
    }

    /** Never throws — a reminder that fails to send must not break the scheduler. */
    private static function mailTo(array $recipients, $mailable): bool
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
            Log::error('E-waste inspection reminder email failed: '.$e->getMessage());

            return false;
        }
    }

    /**
     * Bell the people who can actually clear the queue. Wider than
     * EwasteSweepService::notifyIt(), which stops at it_manager — inspecting is day-to-day
     * work and it_executive holds canManageDecommission() precisely so they can do it.
     */
    private static function bellIt(string $mark, Carbon $sweepDate, int $count): void
    {
        $recipients = User::whereIn('role', ['it_manager', 'it_executive', 'superadmin', 'system_admin'])
            ->where('is_active', true)->get();

        if ($recipients->isEmpty()) {
            return;
        }

        $label = self::MARKS[$mark]['label'];
        $noun = $count === 1 ? 'asset' : 'assets';
        $message = $mark === 'day'
            ? "Collection day is today and {$count} e-waste {$noun} are still uninspected — the cycle cannot run until they are."
            : "{$count} e-waste {$noun} awaiting inspection — {$label} until the quarterly collection on ".fmt_date($sweepDate).'.';

        Notification::send($recipients, new DecommissionNotification(
            event: 'ewaste.inspection_reminder',
            batchNumber: null,
            subject: 'E-waste inspection due',
            message: $message,
            url: route('assets.index', ['tab' => 'damaged']),
            icon: 'bi-clipboard-check',
            color: $mark === 'day' ? 'danger' : 'warning',
        ));
    }
}
