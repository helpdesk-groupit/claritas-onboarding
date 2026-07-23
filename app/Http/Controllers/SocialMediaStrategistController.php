<?php

namespace App\Http\Controllers;

use App\Jobs\RunStrategyGeneration;
use App\Models\ClaudeApiSetting;
use App\Models\SocialStrategy;
use App\Models\SocialStrategyFile;
use App\Models\SocialStrategyRun;
use App\Models\SocialStrategySection;
use App\Services\AttachmentProcessor;
use App\Services\SocialMediaStrategistService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * IT > Automation > Social Media AI Strategist.
 *
 * Server-side port of the "Strategist OS" browser agent: a 6-step intake wizard,
 * a knowledge base (files/links/notes), a gap-check gate, background generation
 * of six strategy sections, an inline editor, and PDF/PPTX/Excel export.
 *
 * Authorization: broadened past the sibling Email Workflow module to include HR
 * managers (see User::canUseSocialStrategist()). The route group has no per-route
 * role middleware — the controller self-gates (project convention).
 */
class SocialMediaStrategistController extends Controller
{
    /** The stages of the single-page pipeline view. */
    private const STAGES = ['intake', 'kb', 'gap', 'generate', 'editor'];

    /** Gate: who may use this module at all. */
    private function authorizeModule(): void
    {
        if (! Auth::user()->canUseSocialStrategist()) {
            abort(403);
        }
    }

    /** Load a strategy the current user is allowed to touch, or 403/404. */
    private function findOwned(int $id): SocialStrategy
    {
        return SocialStrategy::visibleTo(Auth::user())->findOrFail($id);
    }

    // ── List ─────────────────────────────────────────────────────────────
    public function index()
    {
        $this->authorizeModule();

        $strategies = SocialStrategy::visibleTo(Auth::user())
            ->with(['owner', 'latestRun'])
            ->withCount(['sections as ready_sections' => fn ($q) => $q->where('status', SocialStrategySection::STATUS_OK)])
            ->orderByDesc('updated_at')
            ->paginate(20);

        return view('it.automation.social-media-strategist.index', compact('strategies'));
    }

    // ── Create (name only; the intake wizard fills the rest) ─────────────
    public function store(Request $request)
    {
        $this->authorizeModule();

        $data = $request->validate([
            'name' => 'required|string|max:120',
        ]);

        $strategy = SocialStrategy::create([
            'created_by' => Auth::id(),
            'company' => Auth::user()?->employee?->company,
            'name' => $data['name'],
            'status' => SocialStrategy::STATUS_DRAFT,
            'wizard_step' => 1,
            'intake_json' => [],
            'use_web_search' => true,
        ]);

        return redirect()
            ->route('it.automation.social-media-strategist.edit', ['strategy' => $strategy->id, 'step' => 1])
            ->with('success', 'Strategy created. Complete the intake below.');
    }

    // ── The pipeline view (intake → kb → gap → generate → editor) ────────
    public function edit(Request $request, int $strategy)
    {
        $this->authorizeModule();
        $model = $this->findOwned($strategy);
        $model->load(['sections', 'files', 'latestRun']);

        $step = (int) $request->query('step', $model->wizard_step ?: 1);
        $step = max(1, min(SocialStrategy::TOTAL_STEPS, $step));

        $stage = (string) $request->query('stage', '');
        if (! in_array($stage, self::STAGES, true)) {
            $stage = $this->defaultStage($model);
        }

        return view('it.automation.social-media-strategist.wizard', [
            'strategy' => $model,
            'step' => $step,
            'stage' => $stage,
            'models' => ClaudeApiSetting::MODELS,
        ]);
    }

    /** Where to land when no explicit ?stage= is given. */
    private function defaultStage(SocialStrategy $m): string
    {
        return match (true) {
            $m->status === SocialStrategy::STATUS_GENERATING => 'generate',
            $m->hasOutput() => 'editor',
            $m->hasFactbase() => 'gap',
            $m->isReadyForGapCheck() => 'kb',
            default => 'intake',
        };
    }

    // ── Save one intake step ─────────────────────────────────────────────
    public function update(Request $request, int $strategy)
    {
        $this->authorizeModule();
        $model = $this->findOwned($strategy);

        $step = max(1, min(SocialStrategy::TOTAL_STEPS, (int) $request->input('step', 1)));

        $fieldsByStep = [
            1 => ['client', 'industry', 'offering', 'success'],
            2 => ['audience', 'salesmotion', 'competitors'],
            3 => ['budget', 'team', 'approval', 'assets'],
            4 => ['licenses', 'strikes', 'redlines'],
            5 => ['timeline', 'seasonal', 'history'],
            6 => [],
        ];
        $fields = $fieldsByStep[$step] ?? [];

        // Build rules: everything nullable string, then upgrade the step's scalar
        // required fields (chip fields goal/juris are enforced separately below).
        $rules = [];
        foreach ($fields as $f) {
            $rules[$f] = 'nullable|string|max:5000';
        }
        foreach (SocialStrategy::STEP_REQUIRED[$step] ?? [] as $f) {
            if (! in_array($f, SocialStrategy::INTAKE_ARRAY_FIELDS, true)) {
                $rules[$f] = 'required|string|max:5000';
            }
        }
        $request->validate($rules);

        $intake = $model->intake_json ?? [];
        foreach ($fields as $f) {
            $intake[$f] = trim((string) $request->input($f, $intake[$f] ?? ''));
        }
        if ($step === 1) {
            $intake['goal'] = $this->cleanArray($request->input('goal', $intake['goal'] ?? []));
            if (empty($intake['goal'])) {
                return back()->withInput()->with('error', 'Select at least one primary business goal — the doctrine forbids guessing it.');
            }
        }
        if ($step === 2) {
            $intake['juris'] = $this->cleanArray($request->input('juris', $intake['juris'] ?? []));
            if (empty($intake['juris'])) {
                return back()->withInput()->with('error', 'Select at least one jurisdiction — each adds a regulatory layer.');
            }
        }

        $model->intake_json = $intake;
        $model->wizard_step = max($model->wizard_step, min($step + 1, SocialStrategy::TOTAL_STEPS));
        $model->save();

        // Finished the intake (step 6 review, or an explicit jump) → knowledge base.
        if ($step >= SocialStrategy::TOTAL_STEPS || $request->input('action') === 'to-kb') {
            return redirect()
                ->route('it.automation.social-media-strategist.edit', ['strategy' => $model->id, 'stage' => 'kb'])
                ->with('success', 'Intake saved. Add any knowledge base material, then run the gap check.');
        }

        return redirect()
            ->route('it.automation.social-media-strategist.edit', ['strategy' => $model->id, 'step' => $step + 1, 'stage' => 'intake'])
            ->with('success', 'Saved. Next step.');
    }

    // ── Knowledge base: notes / links / AI settings ──────────────────────
    public function saveKb(Request $request, int $strategy)
    {
        $this->authorizeModule();
        $model = $this->findOwned($strategy);

        $request->validate([
            'kb_notes' => 'nullable|string|max:20000',
            'model' => ['nullable', Rule::in(array_keys(ClaudeApiSetting::MODELS))],
            'use_web_search' => 'nullable|boolean',
            'links' => 'nullable|array|max:30',
            'links.*.url' => 'nullable|string|max:1000|url',
            'links.*.note' => 'nullable|string|max:300',
        ]);

        $links = collect($request->input('links', []))
            ->map(fn ($l) => ['url' => trim((string) ($l['url'] ?? '')), 'note' => trim((string) ($l['note'] ?? ''))])
            ->filter(fn ($l) => $l['url'] !== '')
            ->values()
            ->all();

        $model->fill([
            'kb_notes' => $request->input('kb_notes') ?: null,
            'model' => $request->input('model') ?: null,
            'use_web_search' => (bool) $request->input('use_web_search', false),
            'kb_links_json' => $links,
        ])->save();

        $stage = $request->input('action') === 'gap-check' ? 'gap' : 'kb';

        return redirect()
            ->route('it.automation.social-media-strategist.edit', ['strategy' => $model->id, 'stage' => $stage])
            ->with('success', 'Knowledge base saved.');
    }

    // ── Knowledge base: file upload ──────────────────────────────────────
    public function uploadFile(Request $request, int $strategy)
    {
        $this->authorizeModule();
        $model = $this->findOwned($strategy);

        // valid_file_content magic-byte-checks the binary types and passes text
        // types through; the global ScanUploadsForMalware already ran on this
        // request, so a file that reaches here is scanned-clean.
        $request->validate([
            'file' => ['required', 'file', 'max:10240', 'valid_file_content'],
        ]);

        $file = $request->file('file');
        $ext = strtolower($file->getClientOriginalExtension());
        $allowed = ['pdf', 'png', 'jpg', 'jpeg', 'webp', 'txt', 'md', 'csv', 'json'];
        if (! in_array($ext, $allowed, true)) {
            return back()->with('error', 'Unsupported file type. Allowed: PDF, images, txt, md, csv, json.');
        }

        $kind = match (true) {
            $ext === 'pdf' => 'pdf',
            in_array($ext, ['png', 'jpg', 'jpeg', 'webp'], true) => 'image',
            $ext === 'csv' => 'csv',
            $ext === 'json' => 'json',
            default => 'text',
        };

        $stored = AttachmentProcessor::store($file, 'social_strategy_files/'.$model->id);

        $extracted = null;
        if (in_array($kind, ['text', 'csv', 'json'], true)) {
            $abs = Storage::disk('local')->path($stored['file_path']);
            $extracted = mb_substr((string) @file_get_contents($abs), 0, 60000);
        }

        $model->files()->create([
            'uploaded_by' => Auth::id(),
            'original_name' => $stored['original_name'],
            'file_path' => $stored['file_path'],
            'mime' => $stored['mime'],
            'size' => $stored['size'],
            'is_image' => $stored['is_image'],
            'kind' => $kind,
            'extracted_text' => $extracted,
            'scan_status' => SocialStrategyFile::SCAN_CLEAN,
            'scanned_at' => now(),
        ]);

        return back()->with('success', 'File added to the knowledge base.');
    }

    public function deleteFile(int $strategy, int $file)
    {
        $this->authorizeModule();
        $model = $this->findOwned($strategy);
        $f = $model->files()->findOrFail($file);

        Storage::disk('local')->delete($f->file_path);
        $f->delete();

        return back()->with('success', 'File removed.');
    }

    // ── Gap-check gate (synchronous AJAX — one call) ─────────────────────
    public function gapCheck(Request $request, int $strategy)
    {
        $this->authorizeModule();
        $model = $this->findOwned($strategy);

        if ($missing = $model->missingRequirements()) {
            return response()->json([
                'ok' => false,
                'error' => 'Complete the intake first — missing: '.implode('; ', $missing).'.',
            ], 422);
        }

        try {
            $result = SocialMediaStrategistService::for($model)->gapCheck($model);

            return response()->json(['ok' => true, 'factbase' => $result['factbase'], 'gaps' => $result['gaps']]);
        } catch (\Throwable $e) {
            Log::error('Strategy gap check failed', ['strategy' => $model->id, 'error' => $e->getMessage()]);

            return response()->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /** Autosave the gap answers (AJAX). Returns whether the gate is now open. */
    public function saveGapAnswers(Request $request, int $strategy)
    {
        $this->authorizeModule();
        $model = $this->findOwned($strategy);

        $model->update(['gap_answers_json' => $this->cleanAnswers($request->input('answers', []))]);

        return response()->json(['ok' => true, 'ready' => $model->allGapsAnswered()]);
    }

    // ── Generation (background job + poll) ───────────────────────────────
    public function generate(Request $request, int $strategy)
    {
        $this->authorizeModule();
        $model = $this->findOwned($strategy);

        // Persist any answers posted with the form (belt to the autosave braces).
        if ($request->has('answers')) {
            $model->update(['gap_answers_json' => $this->cleanAnswers($request->input('answers', []))]);
            $model->refresh();
        }

        if (! $model->isReadyToGenerate()) {
            return back()->with('error', 'Answer every gap question before generating.');
        }

        // Don't stack a second generation on a still-running one.
        if ($model->status === SocialStrategy::STATUS_GENERATING && $model->latestRun?->isRunning()) {
            return $this->toStage($model, 'generate');
        }

        $this->dispatchGeneration($model, null, SocialStrategyRun::TRIGGER_MANUAL);

        return $this->toStage($model, 'generate')
            ->with('info', 'Generating your strategy — this runs several AI calls in the background and takes a couple of minutes.');
    }

    /** Re-run only the sections that are still pending or errored. */
    public function retryGeneration(int $strategy)
    {
        $this->authorizeModule();
        $model = $this->findOwned($strategy);

        if (! $model->isReadyToGenerate()) {
            return back()->with('error', 'Complete the intake and gap answers first.');
        }

        $targets = $model->sections()
            ->whereIn('status', [SocialStrategySection::STATUS_WAIT, SocialStrategySection::STATUS_ERROR])
            ->pluck('section_key')
            ->all();

        if (empty($targets)) {
            return $this->toStage($model, 'editor')->with('info', 'All sections are already generated.');
        }

        $this->dispatchGeneration($model, $targets, SocialStrategyRun::TRIGGER_MANUAL);

        return $this->toStage($model, 'generate')->with('info', 'Retrying the unfinished sections…');
    }

    /** Regenerate a single section (backgrounded — a search section can exceed 100s). */
    public function regenerateSection(int $strategy, int $section)
    {
        $this->authorizeModule();
        $model = $this->findOwned($strategy);
        $sec = $model->sections()->findOrFail($section);

        if (! $model->isReadyToGenerate()) {
            return back()->with('error', 'Complete the intake and gap answers first.');
        }

        $this->dispatchGeneration($model, [$sec->section_key], SocialStrategyRun::TRIGGER_REGENERATE);

        return $this->toStage($model, 'generate')->with('info', 'Regenerating “'.($sec->title ?: $sec->section_key).'”…');
    }

    /** JSON progress poll for the generation + editor panels. */
    public function status(int $strategy)
    {
        $this->authorizeModule();
        $model = $this->findOwned($strategy);
        $run = $model->latestRun;

        $sections = $model->sections()->get()->map(fn (SocialStrategySection $s) => [
            'id' => $s->id,
            'key' => $s->section_key,
            'position' => $s->position,
            'title' => $s->title ?: (SocialStrategy::SECTIONS[$s->section_key]['label'] ?? $s->section_key),
            'status' => $s->status,
            'is_live_sourced' => $s->is_live_sourced,
        ]);

        return response()->json([
            'strategy_status' => $model->status,
            'run' => $run ? [
                'status' => $run->status,
                'trigger' => $run->trigger,
                'total' => $run->total_sections,
                'completed' => $run->completed_sections,
                'failed' => $run->failed_sections,
                'current_section' => $run->current_section,
            ] : null,
            'sections' => $sections,
        ]);
    }

    /** Inline-edit save of one section (AJAX). */
    public function updateSection(Request $request, int $strategy, int $section)
    {
        $this->authorizeModule();
        $model = $this->findOwned($strategy);
        $sec = $model->sections()->findOrFail($section);

        $data = $request->validate([
            'title' => 'nullable|string|max:200',
            'content' => 'required|string|max:60000',
        ]);

        $sec->update([
            'title' => $data['title'] ?: $sec->title,
            'content' => $data['content'],
            'edited_at' => now(),
        ]);

        return response()->json(['ok' => true]);
    }

    // ── Export ───────────────────────────────────────────────────────────
    /** Server-side PDF (dompdf). PPTX + Excel are exported client-side. */
    public function exportPdf(int $strategy)
    {
        $this->authorizeModule();
        $model = $this->findOwned($strategy);

        $sections = $model->sections()->where('status', SocialStrategySection::STATUS_OK)->get();
        if ($sections->isEmpty()) {
            return back()->with('error', 'Nothing to export yet — generate the strategy first.');
        }

        $pdf = Pdf::loadView('it.automation.social-media-strategist.report-pdf', [
            'strategy' => $model,
            'sections' => $sections,
        ])->setPaper('a4');

        $name = Str::slug($model->clientName() ?: 'strategy').'-social-strategy.pdf';

        return $pdf->download($name);
    }

    // ── Delete ───────────────────────────────────────────────────────────
    public function destroy(int $strategy)
    {
        $this->authorizeModule();
        $model = $this->findOwned($strategy);

        foreach ($model->files as $f) {
            Storage::disk('local')->delete($f->file_path);
        }
        $name = $model->clientName();
        $model->delete();

        return redirect()
            ->route('it.automation.social-media-strategist.index')
            ->with('success', "Strategy “{$name}” deleted.");
    }

    // ── Helpers ──────────────────────────────────────────────────────────
    /**
     * Seed the targeted section rows to `wait`, open a run, flip the strategy to
     * `generating`, and dispatch the background job. Returns the run.
     *
     * @param  array<int,string>|null  $targets  null = all six sections
     */
    private function dispatchGeneration(SocialStrategy $model, ?array $targets, string $trigger): SocialStrategyRun
    {
        $keys = $targets ?: array_keys(SocialStrategy::SECTIONS);

        foreach ($keys as $key) {
            $model->sections()->updateOrCreate(
                ['section_key' => $key],
                [
                    'position' => SocialStrategy::SECTIONS[$key]['position'],
                    'status' => SocialStrategySection::STATUS_WAIT,
                    'error' => null,
                ]
            );
        }

        $run = $model->runs()->create([
            'trigger' => $trigger,
            'triggered_by' => Auth::id(),
            'status' => SocialStrategyRun::STATUS_RUNNING,
            'target_sections_json' => $targets,
            'total_sections' => count($keys),
        ]);

        $model->update(['status' => SocialStrategy::STATUS_GENERATING, 'last_error' => null]);

        RunStrategyGeneration::dispatch($model->id, $run->id);

        return $run;
    }

    /** Normalise a gap-answers map: string int keys, trimmed values. */
    private function cleanAnswers(mixed $answers): array
    {
        if (! is_array($answers)) {
            return [];
        }

        $clean = [];
        foreach ($answers as $i => $v) {
            $clean[(string) (int) $i] = trim((string) $v);
        }

        return $clean;
    }

    /** Drop empties + trim a multi-select chip array. */
    private function cleanArray(mixed $input): array
    {
        if (! is_array($input)) {
            return [];
        }

        return array_values(array_filter(
            array_map(fn ($v) => trim((string) $v), $input),
            fn ($v) => $v !== ''
        ));
    }

    private function toStage(SocialStrategy $model, string $stage): \Illuminate\Http\RedirectResponse
    {
        return redirect()->route('it.automation.social-media-strategist.edit', [
            'strategy' => $model->id,
            'stage' => $stage,
        ]);
    }
}
