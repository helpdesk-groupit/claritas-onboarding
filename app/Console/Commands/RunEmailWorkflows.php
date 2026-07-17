<?php

namespace App\Console\Commands;

use App\Jobs\RunEmailWorkflowCapture;
use App\Models\EmailWorkflow;
use App\Models\EmailWorkflowRun;
use App\Support\Automation\CaptureService;
use Carbon\Carbon;
use Cron\CronExpression;
use Illuminate\Console\Command;

/**
 * Drives every active Email Workflow on its own cron, in its own timezone.
 *
 * Runs every minute from routes/console.php and self-gates: each workflow
 * carries its own `capture_cron` + `timezone`, which Laravel's static scheduler
 * can't express, so we evaluate the expressions here instead. This is the same
 * shape as the self-gating claim reminders.
 */
class RunEmailWorkflows extends Command
{
    protected $signature = 'email-workflows:run
                            {--workflow= : Run a single workflow by id}
                            {--force : Ignore the cron schedule and run now}
                            {--sync : Run inline instead of queueing (testing/CLI)}';

    protected $description = 'Run due Email Workflow captures (Email → Rules → Storage → Log)';

    public function handle(CaptureService $capture): int
    {
        $workflows = $this->targets();

        if ($workflows->isEmpty()) {
            $this->info('No workflows to run.');

            return self::SUCCESS;
        }

        $dispatched = 0;

        foreach ($workflows as $workflow) {
            // A pending "Run now" marker fires regardless of cron — that is what
            // makes the button feel immediate (the operator gets the sweep within
            // a minute over the CLI, no browser timeout). Everything else fires on
            // its own cron. --force still forces a cron-style run.
            $requested = $workflow->hasPendingRunRequest();

            if (! $requested && ! $this->option('force') && ! $this->isDue($workflow)) {
                continue;
            }

            if (! $workflow->isReadyToActivate()) {
                // Clear a marker on a now-unrunnable workflow so it doesn't retry
                // every minute forever; readiness is checked when the button is
                // pressed, but state can drift between request and this tick.
                if ($requested) {
                    $workflow->clearRunRequest();
                }
                $this->warn("#{$workflow->id} “{$workflow->name}” skipped — incomplete configuration.");

                continue;
            }

            // A requested run is operator-initiated: label it MANUAL and attribute
            // it to whoever asked, so the history reads true. Clear the marker
            // BEFORE running (at-most-once): if the sweep dies, the failed run is
            // in the history and the operator re-requests — better than a marker
            // that re-fires the same failing sweep every minute.
            $trigger = $requested ? EmailWorkflowRun::TRIGGER_MANUAL : EmailWorkflowRun::TRIGGER_SCHEDULED;
            $userId = $requested ? $workflow->run_requested_by : null;
            if ($requested) {
                $workflow->clearRunRequest();
            }

            if ($this->option('sync')) {
                $run = $capture->run($workflow, $trigger, $userId);
                $this->line(sprintf(
                    '#%d “%s” → %s (scanned %d, captured %d, skipped %d, failed %d)%s',
                    $workflow->id, $workflow->name, strtoupper($run->status),
                    $run->scanned_count, $run->captured_count, $run->skipped_count, $run->failed_count,
                    $run->error ? ' — '.$run->error : ''
                ));
            } else {
                RunEmailWorkflowCapture::dispatch($workflow->id, $trigger, $userId);
                $this->line("#{$workflow->id} “{$workflow->name}” → queued.");
            }

            $dispatched++;
        }

        $this->info($dispatched === 0 ? 'Nothing due this minute.' : "{$dispatched} workflow(s) run.");

        return self::SUCCESS;
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int,EmailWorkflow> */
    private function targets()
    {
        $query = EmailWorkflow::query()
            ->with(['emailConnection', 'storageConnection', 'logConnection']);

        if ($id = $this->option('workflow')) {
            // Explicit id: honour it whatever the status, so an operator can
            // exercise a paused or draft workflow from the CLI.
            return $query->where('id', (int) $id)->get();
        }

        // Includes `error` — see EmailWorkflow::SWEEPABLE_STATUSES. A workflow
        // whose last run failed is still enabled and must keep retrying, or it
        // can never recover from a transient fault. A workflow with a pending
        // "Run now" marker is included too, whatever its status — the operator
        // asked for it explicitly (a ready draft/paused can be exercised), same
        // intent as passing --workflow=.
        return $query->where(function ($q) {
            $q->whereIn('status', EmailWorkflow::SWEEPABLE_STATUSES)
                ->orWhereNotNull('run_requested_at');
        })->get();
    }

    /**
     * Is this workflow's capture cron due in the current minute, in its own
     * timezone? An unparseable expression is skipped, never fatal — one bad
     * workflow must not stop the others.
     */
    private function isDue(EmailWorkflow $workflow): bool
    {
        // Same accessor the readiness gate uses, so "this workflow is ready" and
        // "this workflow will fire" can never disagree.
        $expression = $workflow->effectiveCaptureCron();
        $timezone = $workflow->timezone ?: config('app.timezone');

        try {
            return (new CronExpression($expression))
                ->isDue(Carbon::now($timezone));
        } catch (\Throwable $e) {
            $this->warn("#{$workflow->id} has an invalid capture cron [{$expression}] — skipped.");

            return false;
        }
    }
}
