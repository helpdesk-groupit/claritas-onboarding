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
            if (! $this->option('force') && ! $this->isDue($workflow)) {
                continue;
            }

            if (! $workflow->isReadyToActivate()) {
                $this->warn("#{$workflow->id} “{$workflow->name}” skipped — incomplete configuration.");

                continue;
            }

            if ($this->option('sync')) {
                $run = $capture->run($workflow, EmailWorkflowRun::TRIGGER_SCHEDULED);
                $this->line(sprintf(
                    '#%d “%s” → %s (scanned %d, captured %d, skipped %d, failed %d)%s',
                    $workflow->id, $workflow->name, strtoupper($run->status),
                    $run->scanned_count, $run->captured_count, $run->skipped_count, $run->failed_count,
                    $run->error ? ' — '.$run->error : ''
                ));
            } else {
                RunEmailWorkflowCapture::dispatch($workflow->id, EmailWorkflowRun::TRIGGER_SCHEDULED);
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
        // can never recover from a transient fault.
        return $query->whereIn('status', EmailWorkflow::SWEEPABLE_STATUSES)->get();
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
