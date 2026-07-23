<?php

namespace App\Jobs;

use App\Models\SocialStrategy;
use App\Models\SocialStrategyRun;
use App\Models\SocialStrategySection;
use App\Services\SocialMediaStrategistService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Generates a social strategy's sections off the request cycle.
 *
 * A full run is six sequential Claude calls (three with live web search) and
 * blows past Cloudflare's ~100s edge timeout, so it can never run in the web
 * request. The controller creates a SocialStrategyRun, sets the strategy to
 * `generating`, and dispatches this job onto the `database` queue — the same
 * scheduler-supervised `queue:work database --stop-when-empty` worker that
 * drains Email Workflow sweeps picks it up (no new worker needed). The wizard
 * polls the run row for progress.
 *
 * ShouldBeUnique keyed on the strategy: a second "Generate" click can't run a
 * concurrent generation on the same strategy (the run row + section statuses are
 * the real state; this just avoids wasted duplicate work).
 */
class RunStrategyGeneration implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** One shot: a killed generation is re-dispatched by the Retry button, not auto-retried. */
    public int $tries = 1;

    /** Generous — 6 calls, up to 3 with web search, can take several minutes. */
    public int $timeout = 1800;

    /** Release the uniqueness lock if the job dies without clearing it. */
    public int $uniqueFor = 3600;

    public function __construct(
        public readonly int $strategyId,
        public readonly int $runId,
    ) {
        // Pin to the `database` queue (drained by the scheduler-supervised
        // queue:work in routes/console.php). Set via onConnection() rather than a
        // $connection property so it doesn't clash with Queueable's declaration.
        $this->onConnection('database');
    }

    public function uniqueId(): string
    {
        return 'sms-gen-'.$this->strategyId;
    }

    public function handle(): void
    {
        $run = SocialStrategyRun::find($this->runId);
        if (! $run) {
            return; // run deleted before execution
        }

        /** @var SocialStrategy|null $strategy */
        $strategy = $run->strategy;
        if (! $strategy) {
            $run->update(['status' => SocialStrategyRun::STATUS_FAILED, 'error' => 'Strategy was deleted.', 'finished_at' => now()]);

            return;
        }

        // Never write a section from an incomplete gate — the doctrine forbids
        // guessing the intake/gap answers.
        if (! $strategy->isReadyToGenerate()) {
            $run->update(['status' => SocialStrategyRun::STATUS_FAILED, 'error' => 'Intake or gap answers are incomplete.', 'finished_at' => now()]);
            $strategy->update(['status' => SocialStrategy::STATUS_ERROR, 'last_error' => 'Cannot generate — intake or gap answers are incomplete.']);

            return;
        }

        // Which sections this run writes (null target = all six), in render order.
        $targets = $run->target_sections_json ?: array_keys(SocialStrategy::SECTIONS);
        $ordered = collect(SocialStrategy::SECTIONS)->keys()
            ->filter(fn ($k) => in_array($k, $targets, true))
            ->values()
            ->all();

        $run->update([
            'total_sections' => count($ordered),
            'started_at' => now(),
        ]);

        $service = SocialMediaStrategistService::for($strategy);

        // Seed the "already written" context from sections NOT being (re)generated,
        // so a single-section regenerate still sees the rest of the deck.
        $accumulated = '';
        foreach ($strategy->sections()->where('status', SocialStrategySection::STATUS_OK)->get() as $existing) {
            if (! in_array($existing->section_key, $ordered, true)) {
                $accumulated .= "\n[{$existing->title}]\n{$existing->content}";
            }
        }

        $completed = 0;
        $failed = 0;

        foreach ($ordered as $key) {
            $meta = SocialStrategy::SECTIONS[$key];

            $section = $strategy->sections()->firstOrNew(['section_key' => $key]);
            $section->position = $meta['position'];
            $section->status = SocialStrategySection::STATUS_RUNNING;
            $section->error = null;
            $section->save();

            $run->update(['current_section' => $key]);

            try {
                $out = $service->generateSection($strategy, $key, $accumulated);
                $section->fill([
                    'title' => $out['title'] ?: $meta['label'],
                    'content' => $out['content'],
                    'is_live_sourced' => $out['live'],
                    'status' => SocialStrategySection::STATUS_OK,
                    'error' => null,
                    'generated_at' => now(),
                ])->save();

                $accumulated .= "\n[{$section->title}]\n{$section->content}";
                $completed++;
                $run->increment('completed_sections');
            } catch (\Throwable $e) {
                // One bad section never aborts the rest (artifact parity).
                $section->fill([
                    'status' => SocialStrategySection::STATUS_ERROR,
                    'error' => Str::limit($e->getMessage(), 490),
                ])->save();
                $failed++;
                $run->increment('failed_sections');
                Log::error('Strategy section generation failed', [
                    'strategy' => $strategy->id,
                    'section' => $key,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $run->update([
            'status' => $failed === 0
                ? SocialStrategyRun::STATUS_SUCCESS
                : ($completed === 0 ? SocialStrategyRun::STATUS_FAILED : SocialStrategyRun::STATUS_PARTIAL),
            'current_section' => null,
            'finished_at' => now(),
        ]);

        // Ready as long as SOMETHING was produced; error only when nothing was.
        $strategy->forceFill([
            'status' => $completed > 0 ? SocialStrategy::STATUS_READY : SocialStrategy::STATUS_ERROR,
            'last_error' => $failed > 0 ? "{$failed} section(s) failed — open the editor and regenerate them." : null,
            'generated_at' => now(),
            'meta_json' => [
                'client' => $strategy->intake('client'),
                'industry' => $strategy->intake('industry'),
                'date' => now()->toDateString(),
            ],
        ])->save();
    }
}
