<?php

namespace Tests\Feature;

use App\Jobs\RunStrategyGeneration;
use App\Models\ClaudeApiSetting;
use App\Models\SocialStrategy;
use App\Models\SocialStrategyRun;
use App\Models\SocialStrategySection;
use App\Models\User;
use App\Services\SocialMediaStrategistService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Feature cover for the Social Media AI Strategist: role gating (broadened to HR
 * managers), the gap-check gate, background generation dispatch, the Anthropic
 * transport's text-only join (web_search interleaves tool blocks), and the
 * generation job's happy + all-fail paths.
 */
class SocialMediaStrategistTest extends TestCase
{
    use RefreshDatabase;

    private function activateClaude(): void
    {
        ClaudeApiSetting::current()->update([
            'api_key' => 'sk-ant-test-key',
            'enabled' => true,
            'model' => 'claude-sonnet-5',
        ]);
    }

    private function completeIntake(): array
    {
        return [
            'client' => 'Aria Aesthetics', 'industry' => 'Beauty / aesthetics',
            'offering' => 'Facials', 'success' => '120/mo', 'goal' => ['Recruitment'],
            'juris' => ['Malaysia'], 'audience' => 'Women 25-45', 'salesmotion' => 'WhatsApp',
            'budget' => 'RM 25k', 'timeline' => 'Launch Sept',
        ];
    }

    private function readyStrategy(User $owner): SocialStrategy
    {
        return SocialStrategy::create([
            'created_by' => $owner->id,
            'name' => 'Aria',
            'status' => SocialStrategy::STATUS_DRAFT,
            'wizard_step' => 6,
            'intake_json' => $this->completeIntake(),
            'factbase' => '- Aria sells facials in KL',
            'gaps_json' => [['q' => 'Ticket size?', 'why' => 'sizing', 'suggestion' => 'RM 800']],
            'gap_answers_json' => ['0' => 'RM 800'],
            'use_web_search' => true,
        ]);
    }

    // ── Role gating ──────────────────────────────────────────────────────
    public function test_a_plain_employee_is_forbidden(): void
    {
        $employee = User::factory()->withTwoFactor()->create(['role' => 'employee']);

        $this->actingAs($employee)
            ->get(route('it.automation.social-media-strategist.index'))
            ->assertForbidden();
    }

    public function test_employee_in_a_creative_department_can_view_the_list(): void
    {
        $user = User::factory()->withTwoFactor()->create(['role' => 'employee']);
        \App\Models\Employee::factory()->withUser($user)->create(['department' => 'Content']);

        $this->actingAs($user)
            ->get(route('it.automation.social-media-strategist.index'))
            ->assertOk();
    }

    public function test_employee_in_a_non_listed_department_is_forbidden(): void
    {
        $user = User::factory()->withTwoFactor()->create(['role' => 'employee']);
        \App\Models\Employee::factory()->withUser($user)->create(['department' => 'Finance']);

        $this->actingAs($user)
            ->get(route('it.automation.social-media-strategist.index'))
            ->assertForbidden();
    }

    public function test_hr_manager_and_it_manager_can_view_the_list(): void
    {
        foreach ([User::factory()->hrManager(), User::factory()->itManager()] as $factory) {
            $user = $factory->withTwoFactor()->create();
            $this->actingAs($user)
                ->get(route('it.automation.social-media-strategist.index'))
                ->assertOk()
                ->assertSee('Social Media AI Strategist');
        }
    }

    public function test_store_creates_a_draft_and_redirects_into_the_intake(): void
    {
        $user = User::factory()->itManager()->withTwoFactor()->create();

        $this->actingAs($user)
            ->post(route('it.automation.social-media-strategist.store'), ['name' => 'Aria launch'])
            ->assertRedirect();

        $this->assertDatabaseHas('social_strategies', ['name' => 'Aria launch', 'status' => 'draft', 'created_by' => $user->id]);
    }

    public function test_edit_page_renders_the_intake_wizard(): void
    {
        $user = User::factory()->itManager()->withTwoFactor()->create();
        $strategy = SocialStrategy::create([
            'created_by' => $user->id, 'name' => 'Aria', 'status' => 'draft', 'wizard_step' => 1, 'intake_json' => [],
        ]);

        $this->actingAs($user)
            ->get(route('it.automation.social-media-strategist.edit', $strategy->id))
            ->assertOk()
            ->assertSee('Business')       // step 1 heading
            ->assertSee('Pipeline');      // the rail
    }

    public function test_editor_stage_renders_generated_sections_with_export_bar(): void
    {
        $user = User::factory()->itManager()->withTwoFactor()->create();
        $strategy = $this->readyStrategy($user);
        $strategy->update(['status' => SocialStrategy::STATUS_READY, 'generated_at' => now()]);
        $strategy->sections()->create([
            'section_key' => 'market', 'position' => 1, 'title' => 'Market Intelligence',
            'content' => 'A sourced market read.', 'is_live_sourced' => true, 'status' => 'ok',
        ]);

        $this->actingAs($user)
            ->get(route('it.automation.social-media-strategist.edit', ['strategy' => $strategy->id, 'stage' => 'editor']))
            ->assertOk()
            ->assertSee('Market Intelligence')
            ->assertSee('LIVE-SOURCED')
            ->assertSee('Deck (PPTX)');
    }

    // ── Anthropic transport ──────────────────────────────────────────────
    public function test_call_claude_joins_only_text_blocks_from_a_search_response(): void
    {
        $this->activateClaude();

        // A web_search response interleaves server_tool_use / result blocks with
        // the model's text — only the text must survive the join.
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [
                    ['type' => 'server_tool_use', 'id' => 'x', 'name' => 'web_search', 'input' => ['query' => 'q']],
                    ['type' => 'web_search_tool_result', 'tool_use_id' => 'x', 'content' => [['type' => 'web_search_result', 'title' => 'noise']]],
                    ['type' => 'text', 'text' => '{"section":"Market","content":"Body"}'],
                ],
                'usage' => ['input_tokens' => 100, 'output_tokens' => 50],
            ], 200),
        ]);

        $svc = new SocialMediaStrategistService(null, null);
        $res = $svc->callClaude([['type' => 'text', 'text' => 'hi']], true, 'strategist_market');

        $this->assertTrue($res['searched']);
        $this->assertSame('{"section":"Market","content":"Body"}', $res['text']);
        $this->assertStringNotContainsString('noise', $res['text']);
    }

    public function test_parse_json_strips_fences_and_prose(): void
    {
        $svc = new SocialMediaStrategistService(null, null);
        $out = $svc->parseJson("Here you go:\n```json\n{\"section\":\"X\",\"content\":\"Y\"}\n```\nThanks!");
        $this->assertSame(['section' => 'X', 'content' => 'Y'], $out);
    }

    // ── Gap check ────────────────────────────────────────────────────────
    public function test_gap_check_persists_the_factbase_and_gaps(): void
    {
        $this->activateClaude();
        $user = User::factory()->itManager()->withTwoFactor()->create();
        $strategy = SocialStrategy::create([
            'created_by' => $user->id, 'name' => 'Aria', 'status' => 'draft', 'wizard_step' => 6,
            'intake_json' => $this->completeIntake(), 'use_web_search' => false,
        ]);

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => json_encode([
                    'factbase' => '- Aria sells facials',
                    'gaps' => [['q' => 'Ticket size?', 'why' => 'sizing', 'suggestion' => 'RM 800']],
                ])]],
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ], 200),
        ]);

        $this->actingAs($user)
            ->postJson(route('it.automation.social-media-strategist.gap-check', $strategy->id))
            ->assertOk()
            ->assertJson(['ok' => true]);

        $strategy->refresh();
        $this->assertStringContainsString('Aria sells facials', $strategy->factbase);
        $this->assertCount(1, $strategy->gaps_json);
    }

    public function test_gap_check_is_blocked_until_the_intake_is_complete(): void
    {
        $this->activateClaude();
        $user = User::factory()->itManager()->withTwoFactor()->create();
        $strategy = SocialStrategy::create([
            'created_by' => $user->id, 'name' => 'Aria', 'status' => 'draft', 'wizard_step' => 1, 'intake_json' => [],
        ]);

        $this->actingAs($user)
            ->postJson(route('it.automation.social-media-strategist.gap-check', $strategy->id))
            ->assertStatus(422)
            ->assertJson(['ok' => false]);
    }

    // ── Generation dispatch ──────────────────────────────────────────────
    public function test_generate_dispatches_the_background_job_and_seeds_sections(): void
    {
        Queue::fake();
        $user = User::factory()->itManager()->withTwoFactor()->create();
        $strategy = $this->readyStrategy($user);

        $this->actingAs($user)
            ->post(route('it.automation.social-media-strategist.generate', $strategy->id))
            ->assertRedirect();

        Queue::assertPushed(RunStrategyGeneration::class);
        $strategy->refresh();
        $this->assertSame(SocialStrategy::STATUS_GENERATING, $strategy->status);
        $this->assertCount(6, $strategy->sections);
        $this->assertDatabaseHas('social_strategy_runs', ['social_strategy_id' => $strategy->id, 'status' => 'running', 'total_sections' => 6]);
    }

    public function test_generate_is_refused_when_gap_answers_are_missing(): void
    {
        Queue::fake();
        $user = User::factory()->itManager()->withTwoFactor()->create();
        $strategy = $this->readyStrategy($user);
        $strategy->update(['gap_answers_json' => []]); // wipe answers

        $this->actingAs($user)
            ->post(route('it.automation.social-media-strategist.generate', $strategy->id))
            ->assertRedirect();

        Queue::assertNothingPushed();
    }

    // ── Generation job ───────────────────────────────────────────────────
    public function test_generation_job_writes_every_section_and_marks_ready(): void
    {
        $this->activateClaude();
        $user = User::factory()->itManager()->create();
        $strategy = $this->readyStrategy($user);

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => json_encode(['section' => 'A Section', 'content' => 'Section body.'])]],
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ], 200),
        ]);

        foreach (SocialStrategy::SECTIONS as $key => $meta) {
            $strategy->sections()->create(['section_key' => $key, 'position' => $meta['position'], 'status' => 'wait']);
        }
        $run = $strategy->runs()->create([
            'trigger' => SocialStrategyRun::TRIGGER_MANUAL, 'status' => 'running', 'total_sections' => 6,
        ]);

        (new RunStrategyGeneration($strategy->id, $run->id))->handle();

        $strategy->refresh();
        $run->refresh();
        $this->assertSame(SocialStrategy::STATUS_READY, $strategy->status);
        $this->assertSame(SocialStrategyRun::STATUS_SUCCESS, $run->status);
        $this->assertSame(6, $strategy->sections()->where('status', 'ok')->count());
    }

    public function test_generation_job_marks_error_when_every_section_fails(): void
    {
        $this->activateClaude();
        $user = User::factory()->itManager()->create();
        $strategy = $this->readyStrategy($user);
        $strategy->update(['use_web_search' => false]); // no search → no retry backoff, fast

        // Valid HTTP but non-JSON text → parseJson throws → section errors (no retry).
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => 'sorry, I cannot help with that']],
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ], 200),
        ]);

        foreach (SocialStrategy::SECTIONS as $key => $meta) {
            $strategy->sections()->create(['section_key' => $key, 'position' => $meta['position'], 'status' => 'wait']);
        }
        $run = $strategy->runs()->create([
            'trigger' => SocialStrategyRun::TRIGGER_MANUAL, 'status' => 'running', 'total_sections' => 6,
        ]);

        (new RunStrategyGeneration($strategy->id, $run->id))->handle();

        $strategy->refresh();
        $run->refresh();
        $this->assertSame(SocialStrategy::STATUS_ERROR, $strategy->status);
        $this->assertSame(SocialStrategyRun::STATUS_FAILED, $run->status);
        $this->assertSame(6, $strategy->sections()->where('status', SocialStrategySection::STATUS_ERROR)->count());
    }
}
