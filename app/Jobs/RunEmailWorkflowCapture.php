<?php

namespace App\Jobs;

use App\Models\EmailWorkflow;
use App\Models\EmailWorkflowRun;
use App\Support\Automation\CaptureService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Runs one workflow's capture off the request cycle — a sweep can take minutes
 * (Drive uploads, Sheets appends) and must not block "Run now".
 *
 * ShouldBeUnique keyed on the workflow: a scheduled sweep and an impatient
 * "Run now" click can't process the same mailbox concurrently. That is belt to
 * the captures table's braces — the UNIQUE index is the real guarantee; this
 * just avoids the wasted duplicate work.
 */
class RunEmailWorkflowCapture implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** Overlapping sweeps are pointless; give up rather than pile up. */
    public int $tries = 1;

    /**
     * Generous because a sweep is unbounded by default (message_limit = 0 reads
     * the whole window) and IMAP costs roughly 2 minutes per 100 messages.
     *
     * The worker enforces this (needs the pcntl extension to hard-kill; without
     * it the value is advisory and the queue's retry_after is the real backstop
     * — which is why DB_QUEUE_RETRY_AFTER is set ABOVE this value). A timeout
     * shorter than the sweep would kill it mid-run every run, and with tries=1
     * the workflow would simply never complete: a silent cap wearing a different
     * hat. Captures resume, so a kill is not data loss — just wasted work.
     */
    public int $timeout = 3600;

    /** Stop claiming uniqueness if the job dies without releasing the lock. */
    public int $uniqueFor = 7200;

    public function __construct(
        public readonly int $workflowId,
        public readonly string $trigger = EmailWorkflowRun::TRIGGER_SCHEDULED,
        public readonly ?int $userId = null,
    ) {
        // Run on the `database` queue, not the app-wide `sync` default. Under
        // `sync` each dispatch ran the full multi-minute sweep INLINE, so
        // RunEmailWorkflows never reached the workflows after the first before
        // their cron minute passed — every workflow but the first was starved.
        // Pinning just this job to `database` keeps the blast radius to this
        // module: mails/notifications stay on `sync` exactly as before. Set via
        // onConnection() rather than a $connection property so it doesn't clash
        // with the Queueable trait's own declaration. Requires the `jobs` table
        // (create_queue_jobs_tables migration) and a worker draining it
        // (queue:work database, scheduled in routes/console.php); and
        // DB_QUEUE_RETRY_AFTER (.env = 7200) must exceed $timeout or the queue
        // re-reserves a long sweep mid-run.
        $this->onConnection('database');
    }

    public function uniqueId(): string
    {
        return 'ewf-capture-'.$this->workflowId;
    }

    public function handle(CaptureService $capture): void
    {
        $workflow = EmailWorkflow::find($this->workflowId);

        // Deleted between dispatch and execution — nothing to do.
        if (! $workflow) {
            return;
        }

        // CaptureService records failures on the run rather than throwing, so
        // there is deliberately no try/catch here.
        $capture->run($workflow, $this->trigger, $this->userId);
    }
}
