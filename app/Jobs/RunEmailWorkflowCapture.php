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

    /** Long enough for a full sweep, short enough to surface a hang. */
    public int $timeout = 900;

    /** Stop claiming uniqueness if the job dies without releasing the lock. */
    public int $uniqueFor = 1800;

    public function __construct(
        public readonly int $workflowId,
        public readonly string $trigger = EmailWorkflowRun::TRIGGER_SCHEDULED,
        public readonly ?int $userId = null,
    ) {}

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
