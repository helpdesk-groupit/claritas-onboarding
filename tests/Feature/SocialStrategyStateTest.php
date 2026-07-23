<?php

namespace Tests\Feature;

use App\Models\SocialStrategy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pins the Social Media AI Strategist's wizard-state quartet and its gates:
 * step chips tell the truth (visited AND complete), the intake gate names its
 * blockers, and the gap-check gate only opens when every question is answered.
 */
class SocialStrategyStateTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->itManager()->create();
    }

    private function make(array $attrs = []): SocialStrategy
    {
        return SocialStrategy::create(array_merge([
            'created_by' => $this->owner->id,
            'name' => 'Test engagement',
            'status' => SocialStrategy::STATUS_DRAFT,
            'wizard_step' => 1,
            'intake_json' => [],
            'use_web_search' => true,
        ], $attrs));
    }

    private function completeIntake(): array
    {
        return [
            'client' => 'Aria Aesthetics',
            'industry' => 'Beauty / aesthetics',
            'offering' => 'Facials & injectables',
            'success' => '120 consults/month',
            'goal' => ['Revenue / e-commerce sales'],
            'juris' => ['Malaysia'],
            'audience' => 'Women 25-45 in KL',
            'salesmotion' => 'WhatsApp → booking',
            'budget' => 'RM 25,000 / month',
            'timeline' => 'Launch Sept, 6-month horizon',
        ];
    }

    public function test_a_fresh_strategy_is_not_ready_and_names_its_blockers(): void
    {
        $s = $this->make();

        $this->assertFalse($s->isReadyForGapCheck());
        $this->assertNotEmpty($s->missingRequirements());
        $this->assertFalse($s->stepComplete(1));
        // No step is green on a fresh strategy (nothing visited or complete).
        foreach (range(1, 6) as $step) {
            $this->assertFalse($s->stepDone($step), "step {$step} must not be green on a fresh strategy");
        }
    }

    public function test_complete_intake_opens_the_gap_check_gate(): void
    {
        $s = $this->make(['intake_json' => $this->completeIntake(), 'wizard_step' => 6]);

        $this->assertTrue($s->isReadyForGapCheck());
        $this->assertSame([], $s->missingRequirements());
        foreach (range(1, 6) as $step) {
            $this->assertTrue($s->stepComplete($step), "step {$step} should be complete");
            $this->assertTrue($s->stepDone($step), "step {$step} should be green (visited + complete)");
        }
    }

    public function test_missing_a_required_chip_field_keeps_the_gate_shut(): void
    {
        $intake = $this->completeIntake();
        $intake['goal'] = []; // required multi-select left empty
        $s = $this->make(['intake_json' => $intake, 'wizard_step' => 6]);

        $this->assertFalse($s->isReadyForGapCheck());
        $this->assertFalse($s->stepComplete(1));
    }

    public function test_step_is_green_only_when_visited(): void
    {
        // Step 1 fields are complete, but wizard_step=1 means it hasn't been
        // advanced past — so it is not yet green (mirrors EmailWorkflow::stepDone).
        $s = $this->make(['intake_json' => $this->completeIntake(), 'wizard_step' => 1]);
        $this->assertTrue($s->stepComplete(1));
        $this->assertFalse($s->stepDone(1));

        $s->update(['wizard_step' => 2]);
        $this->assertTrue($s->stepDone(1));
    }

    public function test_generation_gate_requires_factbase_and_every_answer(): void
    {
        $s = $this->make([
            'intake_json' => $this->completeIntake(),
            'wizard_step' => 6,
            'factbase' => '- Aria sells facials in KL',
            'gaps_json' => [
                ['q' => 'What is the average ticket?', 'why' => 'sizing', 'suggestion' => 'RM 800'],
                ['q' => 'Any regulator issues?', 'why' => 'compliance', 'suggestion' => 'None'],
            ],
            'gap_answers_json' => ['0' => 'RM 800'], // second answer missing
        ]);

        $this->assertTrue($s->hasFactbase());
        $this->assertFalse($s->allGapsAnswered());
        $this->assertFalse($s->isReadyToGenerate());

        $s->update(['gap_answers_json' => ['0' => 'RM 800', '1' => 'None']]);
        $this->assertTrue($s->fresh()->allGapsAnswered());
        $this->assertTrue($s->fresh()->isReadyToGenerate());
    }

    public function test_no_gaps_yet_means_gate_is_shut(): void
    {
        $s = $this->make(['intake_json' => $this->completeIntake(), 'wizard_step' => 6, 'factbase' => 'x']);
        // Gap check hasn't run — an empty gap set is not "all answered".
        $this->assertFalse($s->allGapsAnswered());
        $this->assertFalse($s->isReadyToGenerate());
    }

    public function test_client_name_falls_back_to_engagement_name(): void
    {
        $s = $this->make(['name' => 'Q4 launch']);
        $this->assertSame('Q4 launch', $s->clientName());

        $s->update(['intake_json' => ['client' => 'Aria']]);
        $this->assertSame('Aria', $s->fresh()->clientName());
    }
}
