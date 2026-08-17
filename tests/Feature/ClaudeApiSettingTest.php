<?php

namespace Tests\Feature;

use App\Http\Middleware\EnforceTwoFactor;
use App\Models\ClaudeApiKeyHistory;
use App\Models\ClaudeApiSetting;
use App\Models\Employee;
use App\Models\User;
use App\Services\ClaimReceiptOcrService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Superadmin "Claude API" page: storage, activation, and that the OCR service
 * picks the DB key over the env config.
 */
class ClaudeApiSettingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(EnforceTwoFactor::class);
    }

    private function superadmin(): User
    {
        $super = User::factory()->create(['role' => 'superadmin']);
        Employee::factory()->create(['user_id' => $super->id, 'company' => 'Claritas']); // sidebar needs an employee

        return $super;
    }

    public function test_only_superadmin_can_open_the_page(): void
    {
        $hr = User::factory()->create(['role' => 'hr_manager']);
        $this->actingAs($hr)->get(route('superadmin.claude-api.index'))->assertForbidden();

        $this->actingAs($this->superadmin())->get(route('superadmin.claude-api.index'))->assertOk()->assertSee('Claude API');
    }

    public function test_saving_a_key_and_enabling_activates_ocr(): void
    {
        $super = $this->superadmin();

        $this->actingAs($super)->post(route('superadmin.claude-api.update'), [
            'api_key' => 'sk-ant-testkey-abcd1234',
            'model' => 'claude-haiku-4-5',
            'enabled' => '1',
        ])->assertRedirect();

        $setting = ClaudeApiSetting::current();
        $this->assertTrue($setting->enabled);
        $this->assertSame('claude-haiku-4-5', $setting->model);
        $this->assertSame('sk-ant-testkey-abcd1234', $setting->getRawKey());
        $this->assertTrue($setting->isActive());

        // The service now treats OCR as enabled, keyed off the DB setting.
        $this->assertTrue(ClaimReceiptOcrService::enabled());
    }

    public function test_key_is_encrypted_at_rest_and_hidden_from_json(): void
    {
        $this->actingAs($this->superadmin())->post(route('superadmin.claude-api.update'), [
            'api_key' => 'sk-ant-secret-9999',
            'model' => 'claude-sonnet-5',
            'enabled' => '1',
        ]);

        // Raw column is not the plaintext.
        $raw = \Illuminate\Support\Facades\DB::table('claude_api_settings')->value('api_key');
        $this->assertNotSame('sk-ant-secret-9999', $raw);
        $this->assertNotEmpty($raw);

        // Serialization never exposes the key.
        $this->assertArrayNotHasKey('api_key', ClaudeApiSetting::current()->toArray());
    }

    public function test_blank_key_on_resave_keeps_the_existing_key(): void
    {
        $super = $this->superadmin();
        $this->actingAs($super)->post(route('superadmin.claude-api.update'), [
            'api_key' => 'sk-ant-keepme-1111', 'model' => 'claude-haiku-4-5', 'enabled' => '1',
        ]);

        // Re-save changing only the model, blank key.
        $this->actingAs($super)->post(route('superadmin.claude-api.update'), [
            'api_key' => '', 'model' => 'claude-opus-4-8', 'enabled' => '1',
        ]);

        $setting = ClaudeApiSetting::current();
        $this->assertSame('sk-ant-keepme-1111', $setting->getRawKey()); // preserved
        $this->assertSame('claude-opus-4-8', $setting->model);          // updated
    }

    public function test_disabling_deactivates_without_losing_the_key(): void
    {
        $super = $this->superadmin();
        $this->actingAs($super)->post(route('superadmin.claude-api.update'), [
            'api_key' => 'sk-ant-off-2222', 'model' => 'claude-haiku-4-5', 'enabled' => '1',
        ]);

        // Turn it off (checkbox unchecked -> 'enabled' absent).
        $this->actingAs($super)->post(route('superadmin.claude-api.update'), [
            'model' => 'claude-haiku-4-5',
        ]);

        $setting = ClaudeApiSetting::current();
        $this->assertFalse($setting->enabled);
        $this->assertSame('sk-ant-off-2222', $setting->getRawKey()); // key kept
        $this->assertFalse($setting->isActive());
    }

    public function test_invalid_model_is_rejected(): void
    {
        $this->actingAs($this->superadmin())->post(route('superadmin.claude-api.update'), [
            'api_key' => 'sk-ant-x', 'model' => 'gpt-4o', 'enabled' => '1',
        ])->assertSessionHasErrors('model');
    }

    // ── Key rotation history & labeling ──────────────────────────────────────

    public function test_saving_a_new_key_creates_a_history_row_and_closes_the_previous_one(): void
    {
        $super = $this->superadmin();

        $this->actingAs($super)->post(route('superadmin.claude-api.update'), [
            'api_key' => 'sk-ant-first-key-1111', 'key_label' => 'Key A', 'model' => 'claude-haiku-4-5', 'enabled' => '1',
        ]);
        $this->actingAs($super)->post(route('superadmin.claude-api.update'), [
            'api_key' => 'sk-ant-second-key-2222', 'key_label' => 'Key B', 'model' => 'claude-haiku-4-5', 'enabled' => '1',
        ]);

        $this->assertSame(2, ClaudeApiKeyHistory::count());

        $first = ClaudeApiKeyHistory::orderBy('id')->first();
        $second = ClaudeApiKeyHistory::orderBy('id')->skip(1)->first();

        $this->assertSame('Key A', $first->label);
        $this->assertSame('sk-ant-…1111', $first->masked_key);
        $this->assertNotNull($first->ended_at);

        $this->assertSame('Key B', $second->label);
        $this->assertSame('sk-ant-…2222', $second->masked_key);
        $this->assertNull($second->ended_at);
        $this->assertTrue($second->isCurrent());

        $this->assertSame($second->id, ClaudeApiKeyHistory::current()->id);
    }

    public function test_label_only_edit_relabels_the_current_row_without_rotating(): void
    {
        $super = $this->superadmin();
        $this->actingAs($super)->post(route('superadmin.claude-api.update'), [
            'api_key' => 'sk-ant-stable-key-3333', 'key_label' => 'Original Label', 'model' => 'claude-haiku-4-5', 'enabled' => '1',
        ]);

        // Blank key, changed label.
        $this->actingAs($super)->post(route('superadmin.claude-api.update'), [
            'api_key' => '', 'key_label' => 'Renamed Label', 'model' => 'claude-haiku-4-5', 'enabled' => '1',
        ]);

        $this->assertSame(1, ClaudeApiKeyHistory::count());
        $current = ClaudeApiKeyHistory::current();
        $this->assertSame('Renamed Label', $current->label);
        $this->assertSame('sk-ant-…3333', $current->masked_key); // key itself untouched
        $this->assertNull($current->ended_at);
    }

    public function test_first_ever_save_creates_exactly_one_history_row_with_nothing_to_close(): void
    {
        $this->actingAs($this->superadmin())->post(route('superadmin.claude-api.update'), [
            'api_key' => 'sk-ant-brand-new-4444', 'key_label' => 'First key', 'model' => 'claude-haiku-4-5', 'enabled' => '1',
        ]);

        $this->assertSame(1, ClaudeApiKeyHistory::count());
        $this->assertNull(ClaudeApiKeyHistory::first()->ended_at);
    }

    public function test_label_only_submit_with_no_key_and_no_history_is_a_noop(): void
    {
        $this->actingAs($this->superadmin())->post(route('superadmin.claude-api.update'), [
            'api_key' => '', 'key_label' => 'Nothing to attach this to', 'model' => 'claude-haiku-4-5', 'enabled' => '0',
        ]);

        $this->assertSame(0, ClaudeApiKeyHistory::count());
    }

    public function test_key_history_card_shows_label_masked_key_and_set_by(): void
    {
        $super = $this->superadmin();
        $this->actingAs($super)->post(route('superadmin.claude-api.update'), [
            'api_key' => 'sk-ant-visible-5555', 'key_label' => 'Finance team key', 'model' => 'claude-haiku-4-5', 'enabled' => '1',
        ]);

        $this->actingAs($super)->get(route('superadmin.claude-api.index'))
            ->assertOk()
            ->assertSee('Key History')
            ->assertSee('Finance team key')
            ->assertSee('sk-ant-…5555');
    }
}
