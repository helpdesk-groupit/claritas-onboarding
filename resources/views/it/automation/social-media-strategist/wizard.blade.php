@extends('layouts.app')

@section('title', 'Social Media AI Strategist')
@section('page-title', 'Social Media AI Strategist')

@php
    use App\Models\SocialStrategy;
    $intake = $strategy->intake_json ?? [];
    $iv = fn ($k) => (string) ($intake[$k] ?? '');
    $ivArr = fn ($k) => (array) ($intake[$k] ?? []);
    $secByKey = $strategy->sections->keyBy('section_key');
    $running = $strategy->latestRun && $strategy->latestRun->isRunning();
    $shouldPoll = $stage === 'generate' || ($stage === 'editor' && $running);

    // Data for the client-side exporters (PPTX / Excel / Word). Built here as a
    // plain array so @json() in the script receives a single variable — an inline
    // array literal with closures breaks Blade's directive bracket matching.
    $smsData = null;
    if ($stage === 'editor' && $strategy->hasOutput()) {
        $smsData = [
            'client' => $strategy->clientName(),
            'industry' => (string) $strategy->intake('industry'),
            'date' => optional($strategy->generated_at)->toDateString() ?? now()->toDateString(),
            'intake' => $strategy->intake_json ?? [],
            'gaps' => collect($strategy->gaps_json ?? [])
                ->map(fn ($g, $i) => ['q' => $g['q'] ?? '', 'a' => ($strategy->gap_answers_json ?? [])[$i] ?? ''])
                ->values()->all(),
            'sections' => $strategy->sections
                ->map(fn ($s) => ['title' => $s->title, 'content' => $s->content, 'live' => (bool) $s->is_live_sourced])
                ->all(),
        ];
    }
@endphp

@push('styles')
<style nonce="{{ $cspNonce ?? '' }}">
    .sms-rail .card-body { padding:16px; }
    .sms-rail-brand { font-weight:700; font-size:13px; letter-spacing:.4px; text-transform:uppercase; color:#7C3AED; margin-bottom:12px; }
    .sms-phase { display:flex; gap:10px; align-items:flex-start; padding:9px 6px; border-radius:8px; text-decoration:none; color:#64748b; position:relative; }
    .sms-phase .dot { width:11px; height:11px; border-radius:50%; border:2px solid #cbd5e1; margin-top:4px; flex:none; background:#fff; }
    .sms-phase .lbl { font-size:13px; font-weight:600; line-height:1.3; color:#475569; }
    .sms-phase .sub { display:block; font-size:10.5px; font-weight:500; color:#94a3b8; text-transform:uppercase; letter-spacing:.4px; }
    .sms-phase.active { background:rgba(124,58,237,.08); }
    .sms-phase.active .dot { border-color:#7C3AED; box-shadow:0 0 0 4px rgba(124,58,237,.12); }
    .sms-phase.active .lbl { color:#7C3AED; }
    .sms-phase.done .dot { border-color:#16a34a; background:#16a34a; }
    .sms-phase.done .lbl { color:#166534; }

    .sms-steps { display:flex; gap:8px; flex-wrap:wrap; }
    .sms-step { width:34px; height:34px; border-radius:50%; display:inline-flex; align-items:center; justify-content:center;
        border:2px solid #e2e8f0; color:#94a3b8; font-weight:700; font-size:13px; text-decoration:none; }
    .sms-step.active { border-color:#7C3AED; color:#7C3AED; }
    .sms-step.done { border-color:#16a34a; background:#16a34a; color:#fff; }

    .sms-chips { display:flex; flex-wrap:wrap; gap:8px; }
    .sms-chips .btn-check:checked + .btn { background:#7C3AED; border-color:#7C3AED; color:#fff; }

    .sms-gap { border:1px solid #e2e8f0; border-radius:10px; padding:14px 16px; margin-bottom:12px; }
    .sms-gap .q { font-weight:600; }
    .sms-gap .why { font-size:12px; color:#94a3b8; margin:3px 0 8px; }

    .sms-note { border-left:3px solid #7C3AED; padding:10px 14px; background:rgba(124,58,237,.06); border-radius:0 8px 8px 0; font-size:13px; color:#475569; }

    .sms-pline { display:flex; align-items:center; gap:12px; padding:11px 4px; border-bottom:1px solid #eef2f7; font-size:14px; color:#94a3b8; }
    .sms-pline.run { color:#0f172a; }
    .sms-pline.ok { color:#166534; }
    .sms-pline.err { color:#b91c1c; }
    .sms-pdot { width:16px; text-align:center; flex:none; }

    .sms-sec .card-header { cursor:default; display:flex; align-items:center; gap:10px; }
    .sms-sec .sms-body { white-space:pre-wrap; font-size:13.5px; line-height:1.6; }
    .sms-live { font-size:10px; font-weight:700; letter-spacing:.4px; }

    [data-theme="dark"] .sms-phase .lbl { color:#cbd5e1; }
    [data-theme="dark"] .sms-gap, [data-theme="dark"] .sms-sec .card { border-color:#1e293b; }
</style>
@endpush

@section('content')
<div class="d-flex align-items-center gap-2 mb-3">
    <a href="{{ route('it.automation.social-media-strategist.index') }}" class="btn btn-sm btn-light">
        <i class="bi bi-arrow-left"></i>
    </a>
    <div>
        <div class="fw-semibold">{{ $strategy->clientName() }}</div>
        <div class="text-muted" style="font-size:11px;">{{ $strategy->intake('industry') ?: 'Social media strategy' }}</div>
    </div>
    <span class="badge {{ $strategy->statusBadgeClass() }} text-capitalize ms-2">{{ $strategy->status }}</span>
</div>

@foreach (['success' => 'success', 'info' => 'info', 'error' => 'danger'] as $flash => $variant)
    @if(session($flash))
        <div class="alert alert-{{ $variant }} alert-dismissible fade show py-2">{{ session($flash) }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    @endif
@endforeach

<div class="row g-3">
    {{-- ── Pipeline rail ─────────────────────────────────────────── --}}
    <div class="col-lg-3">
        <div class="card sms-rail">
            <div class="card-body">
                <div class="sms-rail-brand"><i class="bi bi-megaphone me-1"></i> Pipeline</div>
                @php
                    $ready = $strategy->status === SocialStrategy::STATUS_READY;
                    $phases = [
                        ['key' => 'intake', 'label' => 'Intake', 'sub' => '6 steps', 'done' => $strategy->isReadyForGapCheck()],
                        ['key' => 'kb', 'label' => 'Knowledge base', 'sub' => 'files · links', 'done' => $strategy->hasFactbase()],
                        ['key' => 'gap', 'label' => 'Gap check', 'sub' => 'gate — no guessing', 'done' => $strategy->allGapsAnswered()],
                        ['key' => 'generate', 'label' => 'Generation', 'sub' => '6 sections', 'done' => $ready],
                        ['key' => 'editor', 'label' => 'Review & export', 'sub' => 'pdf · deck · sheet', 'done' => $ready],
                    ];
                @endphp
                @foreach($phases as $p)
                    <a class="sms-phase {{ $stage === $p['key'] ? 'active' : '' }} {{ $p['done'] ? 'done' : '' }}"
                       href="{{ route('it.automation.social-media-strategist.edit', ['strategy' => $strategy->id, 'stage' => $p['key']]) }}">
                        <span class="dot"></span>
                        <span class="lbl">{{ $p['label'] }}<span class="sub">{{ $p['sub'] }}</span></span>
                    </a>
                @endforeach
                <div class="sms-note mt-3" style="font-size:11.5px;">Doctrine: no guessing. The agent treats only your intake, knowledge base, confirmed gap answers and live search as truth.</div>
            </div>
        </div>
    </div>

    {{-- ── Main panel ────────────────────────────────────────────── --}}
    <div class="col-lg-9">

    {{-- ============ STAGE: INTAKE ============ --}}
    @if($stage === 'intake')
        <div class="card"><div class="card-body">
            <div class="sms-steps mb-4">
                @for($n = 1; $n <= 6; $n++)
                    <a class="sms-step {{ $step === $n ? 'active' : '' }} {{ $strategy->stepDone($n) ? 'done' : '' }}"
                       href="{{ route('it.automation.social-media-strategist.edit', ['strategy' => $strategy->id, 'step' => $n, 'stage' => 'intake']) }}">{{ $n }}</a>
                @endfor
            </div>

            <form method="POST" action="{{ route('it.automation.social-media-strategist.update', $strategy->id) }}">
                @csrf @method('PUT')
                <input type="hidden" name="step" value="{{ $step }}">

                @if($step === 1)
                    <h5 class="fw-semibold">1/6 · Business &amp; goals</h5>
                    <p class="text-muted small">Diagnosis starts with the business, not the platform.</p>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label small fw-semibold">Client / brand name <span class="text-danger">*</span></label>
                            <input type="text" name="client" class="form-control" required maxlength="200" value="{{ $iv('client') }}" placeholder="e.g. Aria Aesthetics KL">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label small fw-semibold">Industry &amp; sub-segment <span class="text-danger">*</span></label>
                            <select name="industry" class="form-select" required>
                                <option value="">Select…</option>
                                @foreach(SocialStrategy::INDUSTRIES as $opt)
                                    <option value="{{ $opt }}" @selected($iv('industry') === $opt)>{{ $opt }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">What exactly is sold, and to whom <span class="text-danger">*</span></label>
                        <textarea name="offering" class="form-control" rows="2" required placeholder="Offer, price range, buyer">{{ $iv('offering') }}</textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Primary business goal <span class="text-danger">*</span></label>
                        <div class="sms-chips">
                            @foreach(SocialStrategy::GOALS as $i => $g)
                                <input type="checkbox" class="btn-check" name="goal[]" id="goal{{ $i }}" value="{{ $g }}" @checked(in_array($g, $ivArr('goal')))>
                                <label class="btn btn-sm btn-outline-secondary" for="goal{{ $i }}">{{ $g }}</label>
                            @endforeach
                        </div>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-semibold">Success definition (numeric if possible) <span class="text-danger">*</span></label>
                        <input type="text" name="success" class="form-control" required maxlength="500" value="{{ $iv('success') }}" placeholder="e.g. 120 booked consultations/month by Q4">
                    </div>

                @elseif($step === 2)
                    <h5 class="fw-semibold">2/6 · Market &amp; audience</h5>
                    <p class="text-muted small">Each jurisdiction adds a regulatory layer — pick every market you will serve ads into.</p>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Jurisdictions <span class="text-danger">*</span></label>
                        <div class="sms-chips">
                            @foreach(SocialStrategy::JURISDICTIONS as $i => $j)
                                <input type="checkbox" class="btn-check" name="juris[]" id="juris{{ $i }}" value="{{ $j }}" @checked(in_array($j, $ivArr('juris')))>
                                <label class="btn btn-sm btn-outline-secondary" for="juris{{ $i }}">{{ $j }}</label>
                            @endforeach
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Audience hypothesis <span class="text-danger">*</span></label>
                        <textarea name="audience" class="form-control" rows="2" required placeholder="Who buys, who influences, who blocks">{{ $iv('audience') }}</textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Sales motion — where social hands off <span class="text-danger">*</span></label>
                        <input type="text" name="salesmotion" class="form-control" required maxlength="500" value="{{ $iv('salesmotion') }}" placeholder="e.g. WhatsApp → consult booking; Shopee checkout">
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-semibold">Competitors you fear or admire</label>
                        <input type="text" name="competitors" class="form-control" maxlength="1000" value="{{ $iv('competitors') }}" placeholder="Names or handles, comma-separated">
                    </div>

                @elseif($step === 3)
                    <h5 class="fw-semibold">3/6 · Budget &amp; resources</h5>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label small fw-semibold">Monthly budget (media + production) <span class="text-danger">*</span></label>
                            <input type="text" name="budget" class="form-control" required maxlength="200" value="{{ $iv('budget') }}" placeholder="e.g. RM 25,000 / month">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label small fw-semibold">Team &amp; production capability</label>
                            <input type="text" name="team" class="form-control" maxlength="500" value="{{ $iv('team') }}" placeholder="e.g. 1 designer, founder will film">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label small fw-semibold">Approval chain &amp; speed</label>
                            <input type="text" name="approval" class="form-control" maxlength="500" value="{{ $iv('approval') }}" placeholder="e.g. founder signs off, 24h">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label small fw-semibold">Existing assets</label>
                            <textarea name="assets" class="form-control" rows="2" placeholder="Accounts, follower base, content library, brand guide">{{ $iv('assets') }}</textarea>
                        </div>
                    </div>

                @elseif($step === 4)
                    <h5 class="fw-semibold">4/6 · Constraints &amp; compliance posture</h5>
                    <p class="text-muted small">Honest answers here prevent banned ads later.</p>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Licenses / approvals held</label>
                        <input type="text" name="licenses" class="form-control" maxlength="500" value="{{ $iv('licenses') }}" placeholder="e.g. KKLIU number, SC license, halal cert, APDL">
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Past platform strikes or regulator issues</label>
                        <input type="text" name="strikes" class="form-control" maxlength="1000" value="{{ $iv('strikes') }}" placeholder="None / describe">
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-semibold">Red lines the brand will not touch</label>
                        <textarea name="redlines" class="form-control" rows="2" placeholder="Topics, competitors, discount depth, sensitivities">{{ $iv('redlines') }}</textarea>
                    </div>

                @elseif($step === 5)
                    <h5 class="fw-semibold">5/6 · Timeline &amp; seasonality</h5>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label small fw-semibold">Launch date / strategy horizon <span class="text-danger">*</span></label>
                            <input type="text" name="timeline" class="form-control" required maxlength="300" value="{{ $iv('timeline') }}" placeholder="e.g. launch Sept, 6-month horizon">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label small fw-semibold">Seasonal anchors</label>
                            <input type="text" name="seasonal" class="form-control" maxlength="500" value="{{ $iv('seasonal') }}" placeholder="e.g. Raya, 11.11, intake season">
                        </div>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-semibold">What has been tried before, and results</label>
                        <textarea name="history" class="form-control" rows="2" placeholder="Past campaigns, what failed, what worked">{{ $iv('history') }}</textarea>
                    </div>

                @else
                    <h5 class="fw-semibold">6/6 · Review intake</h5>
                    <p class="text-muted small">You can edit anything later — the gap check will catch what's thin.</p>
                    <div class="bg-light rounded p-3" style="white-space:pre-wrap; font-size:12.5px; color:#475569;">{{ app(\App\Services\SocialMediaStrategistService::class)->summaryText($strategy) }}</div>
                @endif

                <div class="d-flex justify-content-between mt-4">
                    @if($step > 1)
                        <a class="btn btn-light" href="{{ route('it.automation.social-media-strategist.edit', ['strategy' => $strategy->id, 'step' => $step - 1, 'stage' => 'intake']) }}">Back</a>
                    @else <span></span> @endif
                    <button type="submit" class="btn btn-primary">
                        {{ $step < 6 ? 'Continue' : 'Continue to knowledge base' }} <i class="bi bi-arrow-right ms-1"></i>
                    </button>
                </div>
            </form>
        </div></div>

    {{-- ============ STAGE: KNOWLEDGE BASE ============ --}}
    @elseif($stage === 'kb')
        @if(! $strategy->isReadyForGapCheck())
            <div class="alert alert-warning">Finish the intake first. Missing: {{ implode('; ', $strategy->missingRequirements()) }}.
                <a href="{{ route('it.automation.social-media-strategist.edit', ['strategy' => $strategy->id, 'stage' => 'intake']) }}" class="alert-link">Back to intake</a>.</div>
        @else
        <div class="card mb-3"><div class="card-body">
            <h5 class="fw-semibold">Knowledge base</h5>
            <p class="text-muted small">Everything the agent may treat as truth. The more you feed it, the less it assumes.</p>

            <label class="form-label small fw-semibold">Upload files <span class="text-muted fw-normal">— PDF, images, .txt, .md, .csv, .json (briefs, brand decks, price lists, past reports)</span></label>
            <form method="POST" action="{{ route('it.automation.social-media-strategist.files.store', $strategy->id) }}" enctype="multipart/form-data" class="d-flex gap-2 align-items-center mb-2">
                @csrf
                <input type="file" name="file" class="form-control form-control-sm" required accept=".pdf,.png,.jpg,.jpeg,.webp,.txt,.md,.csv,.json">
                <button type="submit" class="btn btn-sm btn-outline-primary text-nowrap"><i class="bi bi-upload me-1"></i>Add</button>
            </form>
            <div class="mb-3">
                @forelse($strategy->files as $f)
                    <div class="d-flex align-items-center gap-2 border rounded px-3 py-2 mb-1">
                        <span class="badge bg-info text-dark text-uppercase">{{ $f->kind }}</span>
                        <span class="small">{{ $f->original_name }}</span>
                        <span class="text-muted ms-auto" style="font-size:11px;">{{ number_format($f->size / 1024, 0) }} KB</span>
                        <form method="POST" action="{{ route('it.automation.social-media-strategist.files.delete', ['strategy' => $strategy->id, 'file' => $f->id]) }}" class="m-0">
                            @csrf @method('DELETE')
                            <button type="submit" class="btn btn-sm btn-link text-danger p-0"><i class="bi bi-x-lg"></i></button>
                        </form>
                    </div>
                @empty
                    <div class="text-muted small">No files yet.</div>
                @endforelse
            </div>

            <form method="POST" action="{{ route('it.automation.social-media-strategist.kb.save', $strategy->id) }}">
                @csrf
                <label class="form-label small fw-semibold">Cloud storage links <span class="text-muted fw-normal">— make them viewable</span></label>
                <div id="smsLinks" class="mb-2">
                    @foreach($strategy->kb_links_json ?? [] as $i => $l)
                        <div class="d-flex gap-2 mb-1 sms-link-row">
                            <input type="url" name="links[{{ $i }}][url]" class="form-control form-control-sm" value="{{ $l['url'] ?? '' }}" placeholder="https://drive.google.com/…">
                            <input type="text" name="links[{{ $i }}][note]" class="form-control form-control-sm" style="max-width:220px;" value="{{ $l['note'] ?? '' }}" placeholder="What's in it?">
                            <button type="button" class="btn btn-sm btn-outline-danger sms-link-remove"><i class="bi bi-x"></i></button>
                        </div>
                    @endforeach
                </div>
                <button type="button" class="btn btn-sm btn-light mb-3" id="smsAddLink"><i class="bi bi-plus-lg me-1"></i>Add link</button>

                <label class="form-label small fw-semibold">Anything else the agent must know</label>
                <textarea name="kb_notes" class="form-control mb-3" rows="4" placeholder="Paste brand voice notes, founder background, offer details, internal data…">{{ $strategy->kb_notes }}</textarea>

                <div class="row align-items-end">
                    <div class="col-md-6 mb-3">
                        <label class="form-label small fw-semibold">AI model</label>
                        <select name="model" class="form-select form-select-sm">
                            <option value="">Default (Claude Sonnet 5)</option>
                            @foreach($models as $id => $label)
                                <option value="{{ $id }}" @selected($strategy->model === $id)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-6 mb-3">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" role="switch" id="smsWebSearch" name="use_web_search" value="1" @checked($strategy->use_web_search)>
                            <label class="form-check-label small" for="smsWebSearch">Use live web search (market, competitor &amp; compliance phases cite source + year)</label>
                        </div>
                    </div>
                </div>

                <div class="sms-note mb-3">Zero-guessing rule: the agent only treats this knowledge base, your intake, confirmed gap answers and live web search as truth. Gaps become questions, never assumptions.</div>

                <div class="d-flex justify-content-between">
                    <button type="submit" name="action" value="save" class="btn btn-light">Save</button>
                    <button type="submit" name="action" value="gap-check" class="btn btn-primary">Save &amp; run gap check <i class="bi bi-arrow-right ms-1"></i></button>
                </div>
            </form>
        </div></div>
        @endif

    {{-- ============ STAGE: GAP CHECK ============ --}}
    @elseif($stage === 'gap')
        @if(! $strategy->isReadyForGapCheck())
            <div class="alert alert-warning">Finish the intake first.
                <a href="{{ route('it.automation.social-media-strategist.edit', ['strategy' => $strategy->id, 'stage' => 'intake']) }}" class="alert-link">Back to intake</a>.</div>
        @else
        <div class="card"><div class="card-body">
            <h5 class="fw-semibold">Gap check <span class="text-muted small fw-normal">— no assumptions past this gate</span></h5>

            @if(! $strategy->hasFactbase())
                <p class="text-muted small">The agent reads your knowledge base + intake, builds a factbase, and lists what it must ask before writing a zero-guess strategy.</p>
                <button type="button" class="btn btn-primary" id="smsRunGap"><i class="bi bi-search me-1"></i>Run gap check</button>
                <div id="smsGapErr" class="text-danger small mt-2"></div>
                <div id="smsGapSpin" class="d-none text-muted small mt-3"><span class="spinner-border spinner-border-sm me-2"></span>Reading your knowledge base…</div>
            @else
                <p class="text-muted small">Answer, tap a suggestion to accept it, or edit it. Every blank would otherwise become a guess.</p>
                <div id="smsGaps">
                    @foreach($strategy->gaps_json ?? [] as $i => $g)
                        <div class="sms-gap">
                            <div class="q">{{ $i + 1 }}. {{ $g['q'] ?? '' }}</div>
                            <div class="why">Why it matters: {{ $g['why'] ?? '' }}</div>
                            @if(!empty($g['suggestion']))
                                <button type="button" class="btn btn-sm btn-outline-info mb-2 sms-sugg" data-i="{{ $i }}" data-sugg="{{ $g['suggestion'] }}">
                                    <i class="bi bi-lightbulb me-1"></i>Use suggestion → {{ \Illuminate\Support\Str::limit($g['suggestion'], 90) }}
                                </button>
                            @endif
                            <textarea class="form-control sms-answer" data-i="{{ $i }}" rows="2" placeholder="Your answer…">{{ ($strategy->gap_answers_json ?? [])[$i] ?? '' }}</textarea>
                        </div>
                    @endforeach
                </div>
                <div class="d-flex justify-content-between align-items-center mt-3">
                    <button type="button" class="btn btn-sm btn-light" id="smsRerunGap"><i class="bi bi-arrow-clockwise me-1"></i>Re-run gap check</button>
                    <form method="POST" action="{{ route('it.automation.social-media-strategist.generate', $strategy->id) }}" id="smsGenForm">
                        @csrf
                        <button type="submit" class="btn btn-primary" id="smsGenBtn" @disabled(! $strategy->allGapsAnswered())>
                            <i class="bi bi-stars me-1"></i>Generate strategy
                        </button>
                    </form>
                </div>
                <div class="text-muted small mt-2" id="smsGenHint">@unless($strategy->allGapsAnswered()) Answer every question to unlock generation. @endunless This runs several AI calls (3 use live search) and takes a couple of minutes.</div>
            @endif
        </div></div>
        @endif

    {{-- ============ STAGE: GENERATION ============ --}}
    @elseif($stage === 'generate')
        <div class="card"><div class="card-body">
            <h5 class="fw-semibold">Agent working — no guessing allowed</h5>
            <p class="text-muted small">Each phase writes only from your factbase, confirmed answers, and live sourced search.</p>
            <div id="smsProgress">
                @foreach(SocialStrategy::SECTIONS as $key => $meta)
                    @php $sec = $secByKey[$key] ?? null; $st = $sec->status ?? 'wait'; @endphp
                    <div class="sms-pline {{ $st === 'running' ? 'run' : ($st === 'ok' ? 'ok' : ($st === 'error' ? 'err' : '')) }}" data-key="{{ $key }}">
                        <span class="sms-pdot">
                            @if($st === 'running') <span class="spinner-border spinner-border-sm"></span>
                            @elseif($st === 'ok') <i class="bi bi-check-lg"></i>
                            @elseif($st === 'error') <i class="bi bi-exclamation-triangle"></i>
                            @else <i class="bi bi-circle" style="font-size:9px;"></i> @endif
                        </span>
                        <span>{{ $meta['label'] }}@if($meta['search']) <span class="badge bg-light text-muted" style="font-size:9px;">LIVE SEARCH</span>@endif</span>
                    </div>
                @endforeach
            </div>
            <div id="smsGenDone" class="d-none mt-3">
                <a href="{{ route('it.automation.social-media-strategist.edit', ['strategy' => $strategy->id, 'stage' => 'editor']) }}" class="btn btn-success"><i class="bi bi-check2-circle me-1"></i>Open the strategy</a>
            </div>
            <div id="smsGenFailed" class="d-none mt-3">
                <div class="alert alert-danger py-2">Generation failed. Check the Claude API key on the Claude API page, then retry.</div>
                <form method="POST" action="{{ route('it.automation.social-media-strategist.retry', $strategy->id) }}"><button class="btn btn-outline-primary"><i class="bi bi-arrow-clockwise me-1"></i>Retry</button>@csrf</form>
            </div>
        </div></div>

    {{-- ============ STAGE: EDITOR ============ --}}
    @else
        @if(! $strategy->hasOutput())
            <div class="alert alert-warning">Nothing generated yet.
                <a href="{{ route('it.automation.social-media-strategist.edit', ['strategy' => $strategy->id, 'stage' => 'gap']) }}" class="alert-link">Run the gap check and generate</a>.</div>
        @else
        <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
            <h5 class="fw-semibold mb-0 me-auto">{{ $strategy->clientName() }} — Social Media Strategy</h5>
            <a href="{{ route('it.automation.social-media-strategist.export.pdf', $strategy->id) }}" class="btn btn-sm btn-outline-danger"><i class="bi bi-file-earmark-pdf me-1"></i>PDF</a>
            <button type="button" class="btn btn-sm btn-outline-primary" id="smsExportPptx"><i class="bi bi-easel me-1"></i>Deck (PPTX)</button>
            <button type="button" class="btn btn-sm btn-outline-success" id="smsExportXlsx"><i class="bi bi-file-earmark-spreadsheet me-1"></i>Sheet</button>
            <button type="button" class="btn btn-sm btn-outline-secondary" id="smsExportDoc"><i class="bi bi-file-earmark-word me-1"></i>Word</button>
            <a href="{{ route('it.automation.social-media-strategist.edit', ['strategy' => $strategy->id, 'stage' => 'intake']) }}" class="btn btn-sm btn-light">Edit inputs</a>
        </div>

        @foreach($strategy->sections as $s)
            <div class="card mb-3 sms-sec" data-id="{{ $s->id }}">
                <div class="card-header bg-white">
                    <span class="fw-semibold">{{ $s->title ?: (SocialStrategy::SECTIONS[$s->section_key]['label'] ?? $s->section_key) }}</span>
                    @if($s->is_live_sourced)<span class="badge bg-success sms-live">LIVE-SOURCED</span>@endif
                    @if($s->status === 'error')<span class="badge bg-danger">failed</span>@endif
                    @if($s->status === 'running')<span class="badge bg-info text-dark">generating…</span>@endif
                    <div class="ms-auto d-flex gap-1">
                        <button type="button" class="btn btn-sm btn-outline-secondary sms-edit-btn" data-id="{{ $s->id }}">Edit</button>
                        <form method="POST" action="{{ route('it.automation.social-media-strategist.sections.regenerate', ['strategy' => $strategy->id, 'section' => $s->id]) }}" class="m-0">
                            @csrf
                            <button type="submit" class="btn btn-sm btn-outline-secondary">Regenerate</button>
                        </form>
                    </div>
                </div>
                <div class="card-body">
                    <div class="sms-body" id="smsBody{{ $s->id }}">{{ $s->content ?: ($s->error ? '[Generation failed: '.$s->error.']' : '—') }}</div>
                </div>
            </div>
        @endforeach

        <div class="sms-note">Everything above is editable — tap Edit on any section. Items tagged [ASSUMPTION] or [VERIFY BEFORE LAUNCH] need your confirmation before going live. Final creative in regulated verticals requires qualified legal sign-off.</div>
        @endif
    @endif

    </div>
</div>
@endsection

@push('scripts')
<script nonce="{{ $cspNonce ?? '' }}">
(function () {
    var csrf = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
    var base = "{{ url('it/automation/social-media-strategist') }}";
    var sid = "{{ $strategy->id }}";
    var editorUrl = "{{ route('it.automation.social-media-strategist.edit', ['strategy' => $strategy->id, 'stage' => 'editor']) }}";

    function escHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    // ── KB: dynamic link rows ─────────────────────────────────────────
    var addLink = document.getElementById('smsAddLink');
    if (addLink) {
        var linksWrap = document.getElementById('smsLinks');
        var linkIdx = linksWrap.querySelectorAll('.sms-link-row').length + 100;
        addLink.addEventListener('click', function () {
            var i = linkIdx++;
            var row = document.createElement('div');
            row.className = 'd-flex gap-2 mb-1 sms-link-row';
            var url = document.createElement('input');
            url.type = 'url'; url.className = 'form-control form-control-sm';
            url.name = 'links[' + i + '][url]'; url.placeholder = 'https://drive.google.com/…';
            var note = document.createElement('input');
            note.type = 'text'; note.className = 'form-control form-control-sm'; note.style.maxWidth = '220px';
            note.name = 'links[' + i + '][note]'; note.placeholder = "What's in it?";
            var rm = document.createElement('button');
            rm.type = 'button'; rm.className = 'btn btn-sm btn-outline-danger sms-link-remove';
            rm.innerHTML = '<i class="bi bi-x"></i>';
            row.appendChild(url); row.appendChild(note); row.appendChild(rm);
            linksWrap.appendChild(row);
        });
    }
    document.addEventListener('click', function (e) {
        var rm = e.target.closest ? e.target.closest('.sms-link-remove') : null;
        if (rm) { var r = rm.closest('.sms-link-row'); if (r) r.remove(); }
    });

    // ── Gap check: run / re-run ───────────────────────────────────────
    function runGap(confirmFirst) {
        if (confirmFirst && !confirm('Re-running the gap check rebuilds the questions and clears your current answers. Continue?')) return;
        var spin = document.getElementById('smsGapSpin');
        var err = document.getElementById('smsGapErr');
        if (spin) spin.classList.remove('d-none');
        if (err) err.textContent = '';
        var btn = document.getElementById('smsRunGap') || document.getElementById('smsRerunGap');
        if (btn) btn.disabled = true;
        fetch(base + '/' + sid + '/gap-check', {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' }
        }).then(function (r) { return r.json().then(function (d) { return { ok: r.ok, d: d }; }); })
          .then(function (res) {
              if (res.ok && res.d.ok) { window.location.reload(); }
              else { if (err) err.textContent = res.d.error || 'Gap check failed.'; if (spin) spin.classList.add('d-none'); if (btn) btn.disabled = false; }
          })
          .catch(function () { if (err) err.textContent = 'Gap check failed — try again.'; if (spin) spin.classList.add('d-none'); if (btn) btn.disabled = false; });
    }
    var runGapBtn = document.getElementById('smsRunGap');
    if (runGapBtn) runGapBtn.addEventListener('click', function () { runGap(false); });
    var rerunGapBtn = document.getElementById('smsRerunGap');
    if (rerunGapBtn) rerunGapBtn.addEventListener('click', function () { runGap(true); });

    // ── Gap answers: autosave + unlock Generate ───────────────────────
    var genBtn = document.getElementById('smsGenBtn');
    var saveTimer = null;
    function collectAnswers() {
        var out = {};
        document.querySelectorAll('.sms-answer').forEach(function (t) { out[t.getAttribute('data-i')] = t.value; });
        return out;
    }
    function saveAnswers() {
        var answers = collectAnswers();
        fetch(base + '/' + sid + '/gap-answers', {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json', 'Content-Type': 'application/json' },
            body: JSON.stringify({ answers: answers })
        }).then(function (r) { return r.json(); }).then(function (d) {
            if (genBtn) genBtn.disabled = !d.ready;
        }).catch(function () {});
    }
    document.querySelectorAll('.sms-answer').forEach(function (t) {
        t.addEventListener('input', function () { clearTimeout(saveTimer); saveTimer = setTimeout(saveAnswers, 700); });
    });
    document.querySelectorAll('.sms-sugg').forEach(function (b) {
        b.addEventListener('click', function () {
            var i = this.getAttribute('data-i');
            var ta = document.querySelector('.sms-answer[data-i="' + i + '"]');
            if (ta) { ta.value = this.getAttribute('data-sugg'); saveAnswers(); }
        });
    });

    // ── Generation / editor: poll status ──────────────────────────────
    var shouldPoll = @json($shouldPoll);
    var pollTimer = null;
    function applyStatus(d) {
        (d.sections || []).forEach(function (s) {
            var line = document.querySelector('.sms-pline[data-key="' + s.key + '"]');
            if (line) {
                line.classList.remove('run', 'ok', 'err');
                var dot = line.querySelector('.sms-pdot');
                if (s.status === 'running') { line.classList.add('run'); if (dot) dot.innerHTML = '<span class="spinner-border spinner-border-sm"></span>'; }
                else if (s.status === 'ok') { line.classList.add('ok'); if (dot) dot.innerHTML = '<i class="bi bi-check-lg"></i>'; }
                else if (s.status === 'error') { line.classList.add('err'); if (dot) dot.innerHTML = '<i class="bi bi-exclamation-triangle"></i>'; }
            }
        });
        var run = d.run;
        var terminal = run && (run.status === 'success' || run.status === 'partial' || run.status === 'failed');
        if (terminal || d.strategy_status === 'ready' || d.strategy_status === 'error') {
            clearInterval(pollTimer);
            var anyOk = (d.sections || []).some(function (s) { return s.status === 'ok'; });
            if (document.getElementById('smsProgress')) {
                if (anyOk) { window.location = editorUrl; }
                else { var f = document.getElementById('smsGenFailed'); if (f) f.classList.remove('d-none'); }
            } else {
                // editor stage: a single-section regenerate finished — reload to show it
                window.location.reload();
            }
        }
    }
    function poll() {
        fetch(base + '/' + sid + '/status', { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); }).then(applyStatus).catch(function () {});
    }
    if (shouldPoll) { poll(); pollTimer = setInterval(poll, 2500); }

    // ── Editor: inline section edit ───────────────────────────────────
    document.querySelectorAll('.sms-edit-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var id = this.getAttribute('data-id');
            var body = document.getElementById('smsBody' + id);
            if (!body) return;
            if (btn.getAttribute('data-editing') === '1') {
                // Save
                var ta = body.querySelector('textarea');
                var val = ta ? ta.value : '';
                btn.disabled = true;
                fetch(base + '/' + sid + '/sections/' + id, {
                    method: 'PUT',
                    headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json', 'Content-Type': 'application/json' },
                    body: JSON.stringify({ content: val })
                }).then(function (r) { return r.json(); }).then(function () {
                    body.textContent = val;
                    btn.textContent = 'Edit'; btn.setAttribute('data-editing', '0'); btn.disabled = false;
                }).catch(function () { btn.disabled = false; });
            } else {
                var current = body.textContent;
                var ta = document.createElement('textarea');
                ta.className = 'form-control'; ta.style.minHeight = '260px'; ta.value = current;
                body.textContent = ''; body.appendChild(ta);
                btn.textContent = 'Save'; btn.setAttribute('data-editing', '1');
            }
        });
    });

    // ── Exports (client-side, libs loaded from jsdelivr) ──────────────
    var SMS = @json($smsData);

    function loadScript(src) {
        return new Promise(function (res, rej) {
            if (document.querySelector('script[data-lib="' + src + '"]')) { res(); return; }
            var sc = document.createElement('script');
            sc.src = src; sc.setAttribute('data-lib', src);
            sc.onload = res; sc.onerror = function () { rej(new Error('load failed')); };
            document.head.appendChild(sc);
        });
    }
    function fname(ext) {
        return (SMS.client || 'strategy').replace(/[^a-z0-9]+/gi, '-').toLowerCase() + '-social-strategy.' + ext;
    }

    var pptxBtn = document.getElementById('smsExportPptx');
    if (pptxBtn) pptxBtn.addEventListener('click', function () {
        pptxBtn.disabled = true;
        loadScript('https://cdn.jsdelivr.net/npm/pptxgenjs@3.12.0/dist/pptxgen.bundle.js').then(function () {
            var p = new PptxGenJS(); p.defineLayout({ name: 'W', width: 13.33, height: 7.5 }); p.layout = 'W';
            var cover = p.addSlide(); cover.background = { color: '14171D' };
            cover.addText('SOCIAL MEDIA STRATEGY', { x: 0.7, y: 2.2, w: 12, fontSize: 14, color: 'C084FC', bold: true, charSpacing: 3 });
            cover.addText(SMS.client || 'Client', { x: 0.7, y: 2.7, w: 12, fontSize: 40, color: 'FFFFFF', bold: true, fontFace: 'Georgia' });
            cover.addText((SMS.industry || '') + '  ·  ' + SMS.date, { x: 0.7, y: 4.1, w: 12, fontSize: 13, color: '9AA3B2' });
            SMS.sections.forEach(function (s) {
                if (!s.content) return;
                var chunks = s.content.match(/[\s\S]{1,1500}(\n|$)/g) || [s.content];
                chunks.forEach(function (c, ci) {
                    var sl = p.addSlide(); sl.background = { color: 'FFFFFF' };
                    sl.addText(s.title + (chunks.length > 1 ? ' (' + (ci + 1) + ')' : ''), { x: 0.6, y: 0.4, w: 12, fontSize: 22, color: '14171D', bold: true, fontFace: 'Georgia' });
                    sl.addShape(p.ShapeType.rect, { x: 0.6, y: 1.05, w: 1.6, h: 0.06, fill: { color: '7C3AED' } });
                    sl.addText(c.trim(), { x: 0.6, y: 1.3, w: 12.1, h: 5.8, fontSize: 12.5, color: '2A2F3A', valign: 'top' });
                });
            });
            p.writeFile({ fileName: fname('pptx') }).then(function () { pptxBtn.disabled = false; });
        }).catch(function () { alert('Could not load the deck export library.'); pptxBtn.disabled = false; });
    });

    var xlsxBtn = document.getElementById('smsExportXlsx');
    if (xlsxBtn) xlsxBtn.addEventListener('click', function () {
        xlsxBtn.disabled = true;
        loadScript('https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js').then(function () {
            var wb = XLSX.utils.book_new();
            var brief = Object.keys(SMS.intake).map(function (k) { var v = SMS.intake[k]; return [k, Array.isArray(v) ? v.join(', ') : v]; });
            XLSX.utils.book_append_sheet(wb, XLSX.utils.aoa_to_sheet([['Field', 'Value']].concat(brief)), 'Client Brief');
            var gaps = (SMS.gaps || []).map(function (g) { return [g.q, g.a]; });
            XLSX.utils.book_append_sheet(wb, XLSX.utils.aoa_to_sheet([['Gap question', 'Confirmed answer']].concat(gaps)), 'Gap Check');
            SMS.sections.forEach(function (s) {
                var rows = (s.content || '').split('\n').filter(function (x) { return x.trim(); }).map(function (l) { return [l]; });
                var name = (s.title || 'Section').slice(0, 28).replace(/[\\\/\?\*\[\]:]/g, ' ');
                XLSX.utils.book_append_sheet(wb, XLSX.utils.aoa_to_sheet([[s.title]].concat(rows)), name);
            });
            XLSX.writeFile(wb, fname('xlsx'));
            xlsxBtn.disabled = false;
        }).catch(function () { alert('Could not load the sheet export library.'); xlsxBtn.disabled = false; });
    });

    var docBtn = document.getElementById('smsExportDoc');
    if (docBtn) docBtn.addEventListener('click', function () {
        var html = '<html xmlns:w="urn:schemas-microsoft-com:office:word"><head><meta charset="utf-8">'
            + '<style>body{font-family:Calibri;font-size:11pt}h1{font-family:Georgia}h2{font-family:Georgia;border-bottom:2px solid #7C3AED;padding-bottom:4px}</style></head><body>'
            + '<h1>' + escHtml(SMS.client) + ' — Social Media Strategy</h1>'
            + '<p style="color:#666">' + escHtml(SMS.industry || '') + ' · ' + escHtml(SMS.date) + '</p>'
            + SMS.sections.map(function (s) { return '<h2>' + escHtml(s.title) + '</h2><p>' + escHtml(s.content || '').replace(/\n/g, '<br>') + '</p>'; }).join('')
            + '</body></html>';
        var blob = new Blob(['﻿' + html], { type: 'application/msword' });
        var a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = fname('doc'); a.click();
    });
})();
</script>
@endpush
