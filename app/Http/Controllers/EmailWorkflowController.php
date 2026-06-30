<?php

namespace App\Http\Controllers;

use App\Models\EmailWorkflow;
use App\Models\EmailWorkflowConnection;
use App\Support\Automation\DetectionEngine;
use App\Support\Automation\ProviderRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * IT > Automation > Email Workflow.
 *
 * Phase 1: workflow CRUD + list (active status toggle, edit, delete), the
 * create/edit wizard (Source → Rules → Storage → Log → Schedule), the
 * "Test rules" preview, and connection credential storage + OAuth-start
 * scaffolding. Live Gmail/Drive/Sheets calls + capture/reconcile jobs land
 * in Phase 2 behind the already-defined adapter contracts.
 *
 * Authorization: IT roles + superadmin/system_admin only. The route group has
 * no per-route role middleware, so the controller self-gates (project convention).
 */
class EmailWorkflowController extends Controller
{
    /** Gate: who may use this module at all. */
    private function authorizeModule(): void
    {
        $u = Auth::user();
        if (! $u->isIt() && ! $u->isSuperadmin() && $u->role !== 'system_admin') {
            abort(403);
        }
    }

    /** Load a workflow the current user is allowed to touch, or 403/404. */
    private function findOwned(int $id): EmailWorkflow
    {
        $workflow = EmailWorkflow::visibleTo(Auth::user())->findOrFail($id);

        return $workflow;
    }

    // ── List ─────────────────────────────────────────────────────────────
    public function index()
    {
        $this->authorizeModule();

        $workflows = EmailWorkflow::visibleTo(Auth::user())
            ->with(['emailConnection', 'storageConnection', 'logConnection', 'owner'])
            ->orderByDesc('updated_at')
            ->paginate(20);

        return view('it.automation.email-workflow.index', compact('workflows'));
    }

    // ── Create (wizard, step 1) ──────────────────────────────────────────
    public function create()
    {
        $this->authorizeModule();

        $workflow = new EmailWorkflow([
            'name' => '',
            'status' => EmailWorkflow::STATUS_DRAFT,
            'rules_json' => EmailWorkflow::DEFAULT_RULES,
            'storage_config_json' => EmailWorkflow::DEFAULT_STORAGE_CONFIG,
            'log_config_json' => EmailWorkflow::DEFAULT_LOG_CONFIG,
            'timezone' => 'Asia/Kuala_Lumpur',
            'capture_cron' => EmailWorkflow::DEFAULT_CAPTURE_CRON,
            'reconcile_cron' => EmailWorkflow::DEFAULT_RECONCILE_CRON,
            'first_sweep_on_activate' => true,
            'wizard_step' => 1,
        ]);

        return view('it.automation.email-workflow.wizard', [
            'workflow' => $workflow,
            'step' => 1,
            'connections' => $this->userConnections(),
            'registry' => ProviderRegistry::all(),
        ]);
    }

    // ── Store (create on first save) ─────────────────────────────────────
    public function store(Request $request)
    {
        $this->authorizeModule();

        $data = $request->validate([
            'name' => 'required|string|max:120',
        ]);

        $workflow = EmailWorkflow::create([
            'created_by' => Auth::id(),
            'name' => $data['name'],
            'status' => EmailWorkflow::STATUS_DRAFT,
            'rules_json' => EmailWorkflow::DEFAULT_RULES,
            'storage_config_json' => EmailWorkflow::DEFAULT_STORAGE_CONFIG,
            'log_config_json' => EmailWorkflow::DEFAULT_LOG_CONFIG,
            'timezone' => 'Asia/Kuala_Lumpur',
            'capture_cron' => EmailWorkflow::DEFAULT_CAPTURE_CRON,
            'reconcile_cron' => EmailWorkflow::DEFAULT_RECONCILE_CRON,
            'wizard_step' => 1,
        ]);

        return redirect()
            ->route('it.automation.email-workflow.edit', ['workflow' => $workflow->id, 'step' => 1])
            ->with('success', 'Workflow created. Continue the setup below.');
    }

    // ── Edit (wizard, any step) ──────────────────────────────────────────
    public function edit(Request $request, int $workflow)
    {
        $this->authorizeModule();
        $model = $this->findOwned($workflow);

        $step = (int) $request->query('step', $model->wizard_step ?: 1);
        $step = max(1, min(EmailWorkflow::TOTAL_STEPS, $step));

        return view('it.automation.email-workflow.wizard', [
            'workflow' => $model,
            'step' => $step,
            'connections' => $this->userConnections(),
            'registry' => ProviderRegistry::all(),
        ]);
    }

    // ── Update one wizard step ───────────────────────────────────────────
    public function update(Request $request, int $workflow)
    {
        $this->authorizeModule();
        $model = $this->findOwned($workflow);

        $step = (int) $request->input('step', 1);

        switch ($step) {
            case 1: // Name + email source connection
                $data = $request->validate([
                    'name' => 'required|string|max:120',
                    'email_connection_id' => ['nullable', $this->connectionRule('email')],
                ]);
                $model->name = $data['name'];
                $model->email_connection_id = $data['email_connection_id'] ?? null;
                break;

            case 2: // Detection rules
                $model->rules_json = $this->parseRules($request);
                break;

            case 3: // Storage destination
                $data = $request->validate([
                    'storage_connection_id' => ['nullable', $this->connectionRule('storage')],
                    'folder_ref' => 'nullable|string|max:1000',
                    'monthly_subfolders' => 'nullable|boolean',
                    'filename_template' => 'nullable|string|max:200',
                ]);
                $model->storage_connection_id = $data['storage_connection_id'] ?? null;
                $model->storage_config_json = [
                    'folder_ref' => trim($data['folder_ref'] ?? ''),
                    'monthly_subfolders' => (bool) ($request->input('monthly_subfolders', false)),
                    'filename_template' => $data['filename_template'] ?: '{{date}}_{{originalName}}',
                ];
                break;

            case 4: // Log destination + column mapping
                $data = $request->validate([
                    'log_connection_id' => ['nullable', $this->connectionRule('log')],
                    'target_ref' => 'nullable|string|max:1000',
                    'partition_by_month' => 'nullable|boolean',
                ]);
                $model->log_connection_id = $data['log_connection_id'] ?? null;
                $model->log_config_json = [
                    'target_ref' => trim($data['target_ref'] ?? ''),
                    'partition_by_month' => (bool) ($request->input('partition_by_month', false)),
                    'columns' => $this->parseColumns($request),
                    'idempotency_columns' => ['email.message_id', 'attachment.name'],
                ];
                break;

            case 5: // Schedule
                $data = $request->validate([
                    'timezone' => 'required|string|max:64',
                    'capture_cron' => 'required|string|max:60',
                    'reconcile_cron' => 'required|string|max:60',
                    'first_sweep_on_activate' => 'nullable|boolean',
                ]);
                $model->timezone = $data['timezone'];
                $model->capture_cron = $data['capture_cron'];
                $model->reconcile_cron = $data['reconcile_cron'];
                $model->first_sweep_on_activate = (bool) $request->input('first_sweep_on_activate', false);
                break;
        }

        // Advance recorded progress.
        $model->wizard_step = max($model->wizard_step, min($step + 1, EmailWorkflow::TOTAL_STEPS));
        $model->save();

        // "Save & continue" vs "Save & finish".
        if ($request->input('action') === 'finish' || $step >= EmailWorkflow::TOTAL_STEPS) {
            return redirect()
                ->route('it.automation.email-workflow.index')
                ->with('success', "Workflow “{$model->name}” saved.");
        }

        return redirect()
            ->route('it.automation.email-workflow.edit', ['workflow' => $model->id, 'step' => $step + 1])
            ->with('success', 'Saved. Next step.');
    }

    // ── Toggle Active ↔ Paused (the list's status switch) ────────────────
    public function toggleActive(int $workflow)
    {
        $this->authorizeModule();
        $model = $this->findOwned($workflow);

        if ($model->isActive()) {
            $model->update(['status' => EmailWorkflow::STATUS_PAUSED]);

            return back()->with('success', "“{$model->name}” paused.");
        }

        // Guard: can't activate an incompletely-configured workflow.
        if (! $model->isReadyToActivate()) {
            return back()->with('error', 'Complete all connections and destinations before activating this workflow.');
        }

        $model->update(['status' => EmailWorkflow::STATUS_ACTIVE]);

        return back()->with('success', "“{$model->name}” is now active.");
    }

    // ── Delete ───────────────────────────────────────────────────────────
    public function destroy(int $workflow)
    {
        $this->authorizeModule();
        $model = $this->findOwned($workflow);
        $name = $model->name;
        $model->delete();

        return redirect()
            ->route('it.automation.email-workflow.index')
            ->with('success', "Workflow “{$name}” deleted.");
    }

    // ── Test rules (read-only preview against sample data) ───────────────
    public function testRules(Request $request, int $workflow)
    {
        $this->authorizeModule();
        $model = $this->findOwned($workflow);

        $rules = $this->parseRules($request);
        $engine = new DetectionEngine;

        // Phase 1: preview against representative sample messages so the user
        // can sanity-check their rules before live Gmail access exists.
        // Phase 2 swaps this for the last N real emails via GmailAdapter.
        $samples = $this->sampleMessages();
        $preview = [];
        foreach ($samples as $msg) {
            $r = $engine->evaluate($msg, $rules);
            $preview[] = [
                'subject' => $msg['subject'],
                'from' => $msg['from'],
                'matched' => $r['matched'],
                'reasons' => $r['reasons'],
                'attachments' => array_map(fn ($a) => $a['name'], $r['attachments']),
                'amount' => $r['fields']['amount'],
                'currency' => $r['fields']['currency'],
            ];
        }

        return response()->json([
            'note' => 'Preview uses sample emails until a Gmail account is connected.',
            'results' => $preview,
        ]);
    }

    // ── Connections: save user-supplied OAuth credentials ────────────────
    public function saveConnection(Request $request)
    {
        $this->authorizeModule();

        $data = $request->validate([
            'category' => ['required', Rule::in(EmailWorkflowConnection::CATEGORIES)],
            'provider_id' => 'required|string|max:40',
            'client_id' => 'required|string|max:500',
            'client_secret' => 'required|string|max:500',
        ]);

        $provider = ProviderRegistry::find($data['provider_id']);
        if (! $provider || ! $provider['enabled'] || $provider['category'] !== $data['category']) {
            return back()->with('error', 'That provider is not available yet.');
        }

        $conn = EmailWorkflowConnection::create([
            'created_by' => Auth::id(),
            'category' => $data['category'],
            'provider_id' => $data['provider_id'],
            'client_id' => $data['client_id'],
            'client_secret' => $data['client_secret'],
            'scopes' => $provider['scopes'],
            'status' => EmailWorkflowConnection::STATUS_PENDING,
        ]);

        // Phase 2: redirect into the provider OAuth consent flow here.
        return back()->with('success', ProviderRegistry::name($data['provider_id'])
            .' credentials saved. Click “Connect” to authorize the account.');
    }

    // ── Connections: remove ──────────────────────────────────────────────
    public function deleteConnection(int $connection)
    {
        $this->authorizeModule();

        $conn = EmailWorkflowConnection::where('created_by', Auth::id())->findOrFail($connection);
        $conn->delete();

        return back()->with('success', 'Connection removed.');
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /** Connections owned by the current user, grouped by category. */
    private function userConnections(): array
    {
        $all = EmailWorkflowConnection::where('created_by', Auth::id())
            ->orderByDesc('updated_at')
            ->get();

        return [
            'email' => $all->where('category', 'email')->values(),
            'storage' => $all->where('category', 'storage')->values(),
            'log' => $all->where('category', 'log')->values(),
        ];
    }

    /** Validation rule: a connection must exist, belong to the user, and match the category. */
    private function connectionRule(string $category)
    {
        return Rule::exists('email_workflow_connections', 'id')
            ->where('created_by', Auth::id())
            ->where('category', $category);
    }

    /** @return array<string,mixed> rules built from the step-2 form. */
    private function parseRules(Request $request): array
    {
        return [
            'subject' => [
                'enabled' => (bool) $request->input('subject_enabled', false),
                'mode' => $request->input('subject_mode', 'contains') === 'regex' ? 'regex' : 'contains',
                'keywords' => $this->splitKeywords($request->input('subject_keywords', '')),
            ],
            'body' => [
                'enabled' => (bool) $request->input('body_enabled', false),
                'mode' => $request->input('body_mode', 'contains') === 'regex' ? 'regex' : 'contains',
                'keywords' => $this->splitKeywords($request->input('body_keywords', '')),
            ],
            'combine_subject_body' => $request->input('combine_subject_body', 'or') === 'and' ? 'and' : 'or',
            'attachment' => [
                'required' => (bool) $request->input('attachment_required', false),
                'types' => array_values(array_intersect(
                    ['pdf', 'png', 'jpg', 'docx', 'xlsx'],
                    array_map('strtolower', (array) $request->input('attachment_types', []))
                )),
                'filename_keywords' => $this->splitKeywords($request->input('attachment_keywords', '')),
            ],
            'sender' => [
                'allowlist' => $this->splitKeywords($request->input('sender_allowlist', '')),
                'denylist' => $this->splitKeywords($request->input('sender_denylist', '')),
            ],
            'capture_logic' => in_array($request->input('capture_logic'), ['attachment_only', 'text_only'], true)
                ? $request->input('capture_logic')
                : 'attachment_and_text',
        ];
    }

    /** @return array<int,array<string,string>> column map from the step-4 form. */
    private function parseColumns(Request $request): array
    {
        $labels = (array) $request->input('col_label', []);
        $sources = (array) $request->input('col_source', []);
        $cols = [];
        foreach ($labels as $i => $label) {
            $label = trim((string) $label);
            if ($label === '') {
                continue;
            }
            $cols[] = [
                'label' => $label,
                'source' => (string) ($sources[$i] ?? 'email.subject'),
            ];
        }

        return $cols ?: EmailWorkflow::DEFAULT_LOG_CONFIG['columns'];
    }

    /** Split a comma/newline-separated list into trimmed non-empty keywords. */
    private function splitKeywords(?string $raw): array
    {
        if (! $raw) {
            return [];
        }

        return array_values(array_filter(array_map(
            'trim',
            preg_split('/[\r\n,]+/', $raw) ?: []
        ), fn ($v) => $v !== ''));
    }

    /** Representative sample emails for the Phase-1 "Test rules" preview. */
    private function sampleMessages(): array
    {
        return [
            [
                'message_id' => 's1', 'from' => 'billing@acmesupplies.com',
                'subject' => 'Invoice for June services', 'body' => 'Total RM 1,250.00 due on receipt.',
                'date' => now()->subDays(2)->toIso8601String(),
                'attachments' => [['id' => 'a', 'name' => 'invoice-202606.pdf', 'mime' => 'application/pdf', 'size' => 2048]],
            ],
            [
                'message_id' => 's2', 'from' => 'no-reply@newsletter.com',
                'subject' => 'Your weekly digest', 'body' => 'Top stories this week.',
                'date' => now()->subDay()->toIso8601String(),
                'attachments' => [],
            ],
            [
                'message_id' => 's3', 'from' => 'accounts@vendor.io',
                'subject' => 'Payment received for order #4821', 'body' => 'Thank you. Amount: $89.90.',
                'date' => now()->toIso8601String(),
                'attachments' => [['id' => 'b', 'name' => 'receipt-4821.pdf', 'mime' => 'application/pdf', 'size' => 1500]],
            ],
        ];
    }
}
