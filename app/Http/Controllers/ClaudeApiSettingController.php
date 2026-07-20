<?php

namespace App\Http\Controllers;

use App\Models\ClaudeApiSetting;
use App\Services\ClaimReceiptOcrService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Superadmin-only "Claude API" settings page (Settings menu). Stores the Anthropic
 * API key + model that powers claim-receipt OCR. When enabled with a valid key,
 * OCR runs through Claude and overrides the env-based CLAIMS_OCR_* config.
 *
 * The key is stored encrypted and never sent back to the browser in full — the
 * view only ever sees a masked hint (sk-ant-…last4). Saving with a blank key keeps
 * the existing one, so re-saving other fields never wipes the stored secret.
 */
class ClaudeApiSettingController extends Controller
{
    private function authorizeSuperadmin(): void
    {
        if (! Auth::user()->isSuperadmin()) {
            abort(403);
        }
    }

    public function index()
    {
        $this->authorizeSuperadmin();

        $setting = ClaudeApiSetting::current();

        return view('superadmin.claude-api', [
            'setting' => $setting,
            'models' => ClaudeApiSetting::MODELS,
        ]);
    }

    public function update(Request $request)
    {
        $this->authorizeSuperadmin();

        $data = $request->validate([
            'api_key' => 'nullable|string|max:255',
            'model' => 'required|string|in:'.implode(',', array_keys(ClaudeApiSetting::MODELS)),
            'enabled' => 'nullable|boolean',
        ]);

        $setting = ClaudeApiSetting::current();
        $setting->model = $data['model'];
        $setting->enabled = $request->boolean('enabled');
        // Blank key = keep the existing one (so toggling/changing model never wipes it).
        $newKey = trim((string) ($data['api_key'] ?? ''));
        if ($newKey !== '') {
            $setting->api_key = $newKey;
        }
        $setting->updated_by = Auth::id();
        $setting->save();

        Log::info('Claude API setting updated', [
            'actor_id' => Auth::id(),
            'enabled' => $setting->enabled,
            'model' => $setting->model,
            'has_key' => (bool) $setting->getRawKey(),
        ]);

        $msg = $setting->isActive()
            ? 'Saved. Claude OCR is ACTIVE — receipt scanning now uses '.$setting->modelLabel().'.'
            : (! $setting->getRawKey()
                ? 'Saved. Add an API key to activate receipt OCR.'
                : 'Saved. OCR is switched OFF — turn it on to start scanning receipts.');

        return redirect()->route('superadmin.claude-api.index')->with('success', $msg);
    }

    /**
     * Live "Test key" — validates the key the superadmin typed (or the saved one if the
     * field is left blank) against Anthropic, using the selected model. Returns JSON.
     */
    public function test(Request $request)
    {
        $this->authorizeSuperadmin();

        $data = $request->validate([
            'api_key' => 'nullable|string|max:255',
            'model' => 'required|string|in:'.implode(',', array_keys(ClaudeApiSetting::MODELS)),
        ]);

        $key = trim((string) ($data['api_key'] ?? ''));
        if ($key === '') {
            $key = (string) ClaudeApiSetting::current()->getRawKey();
        }
        if ($key === '') {
            return response()->json(['ok' => false, 'message' => 'Enter an API key first, then test.']);
        }

        return response()->json(ClaimReceiptOcrService::testAnthropicKey($key, $data['model']));
    }
}
