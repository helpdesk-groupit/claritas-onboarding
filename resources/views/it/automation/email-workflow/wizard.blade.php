@extends('layouts.app')

@section('title', $workflow->exists ? 'Edit Workflow' : 'New Workflow')
@section('page-title', 'Email Workflow')

@php
    $rules   = $workflow->rules_json   ?? \App\Models\EmailWorkflow::DEFAULT_RULES;
    $storage = $workflow->storage_config_json ?? \App\Models\EmailWorkflow::DEFAULT_STORAGE_CONFIG;
    $log     = $workflow->log_config_json ?? \App\Models\EmailWorkflow::DEFAULT_LOG_CONFIG;
    $steps   = ['Source', 'Detection Rules', 'Storage', 'Log Destination', 'Schedule'];
    $tzList  = ['Asia/Kuala_Lumpur','Asia/Singapore','Asia/Jakarta','Asia/Bangkok','Asia/Hong_Kong','UTC','Europe/London','America/New_York'];
    // Build placeholder strings in PHP so the literal {{ }} braces never reach
    // Blade's echo compiler (which would otherwise throw a ParseError).
    $defaultFilenameTemplate = \App\Models\EmailWorkflow::DEFAULT_STORAGE_CONFIG['filename_template'];
    $filenameTemplate = old('filename_template', data_get($storage, 'filename_template', $defaultFilenameTemplate));
    $phDate = '{{date}}';
    $phName = '{{originalName}}';
@endphp

@push('styles')
<style nonce="{{ $cspNonce ?? '' }}">
    .wz-steps { display:flex; gap:6px; flex-wrap:wrap; margin-bottom:22px; }
    .wz-step { flex:1; min-width:120px; padding:10px 12px; border-radius:10px; background:#f1f5f9; border:1px solid #e2e8f0; font-size:12px; color:#64748b; display:flex; align-items:center; gap:8px; }
    .wz-step .n { width:22px; height:22px; border-radius:50%; background:#cbd5e1; color:#fff; display:flex; align-items:center; justify-content:center; font-size:11px; font-weight:700; flex-shrink:0; }
    .wz-step.active { background:#e0f2fe; border-color:#7dd3fc; color:#075985; font-weight:600; }
    .wz-step.active .n { background:#0284c7; }
    .wz-step.done { background:#dcfce7; border-color:#86efac; color:#166534; }
    .wz-step.done .n { background:#16a34a; }
    .wz-step a { color:inherit; text-decoration:none; display:flex; align-items:center; gap:8px; width:100%; }

    .prov-card { border:1px solid #e2e8f0; border-radius:12px; padding:14px; cursor:pointer; transition:all .15s; background:#fff; height:100%; }
    .prov-card:hover { border-color:#7dd3fc; box-shadow:0 2px 8px rgba(2,132,199,.10); }
    .prov-card.selected { border-color:#0284c7; box-shadow:0 0 0 2px rgba(2,132,199,.25); }
    .prov-card.disabled { opacity:.55; cursor:not-allowed; }
    .prov-card .bi { font-size:22px; color:#0284c7; }
    .prov-card .pname { font-weight:600; font-size:14px; }
    .prov-card .pblurb { font-size:11px; color:#64748b; }
    .coming-soon { font-size:10px; background:#f1f5f9; color:#64748b; padding:1px 7px; border-radius:999px; }

    .conn-row { display:flex; align-items:center; justify-content:space-between; gap:10px; padding:9px 12px; border:1px solid #e2e8f0; border-radius:8px; margin-bottom:8px; background:#f8fafc; }
    .conn-row .meta { font-size:12px; }
    .scope-list { font-size:11px; color:#64748b; background:#f8fafc; border-radius:8px; padding:8px 10px; }

    .col-map-row { display:flex; gap:8px; align-items:center; margin-bottom:8px; }
    .col-map-row input, .col-map-row select { font-size:13px; }

    .test-result { font-size:12px; }
    .test-result .row-item { display:flex; align-items:flex-start; gap:8px; padding:8px 10px; border-bottom:1px solid #f1f5f9; }
    .test-result .row-item:last-child { border-bottom:none; }

    [data-theme="dark"] .wz-step { background:#1e293b; border-color:#334155; color:#cbd5e1; }
    [data-theme="dark"] .prov-card, [data-theme="dark"] .conn-row { background:#1e293b; border-color:#334155; }
</style>
@endpush

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <a href="{{ route('it.automation.email-workflow.index') }}" class="btn btn-sm btn-light">
        <i class="bi bi-arrow-left me-1"></i> Back to workflows
    </a>
    @if($workflow->exists)
        <span class="badge {{ $workflow->statusBadgeClass() }} text-capitalize">{{ $workflow->status }}</span>
    @endif
</div>

{{-- ── Step indicator ── --}}
<div class="wz-steps">
    @foreach($steps as $i => $label)
        @php
            $num = $i + 1;
            // Green means "visited AND genuinely configured" — see EmailWorkflow::stepDone().
            // It used to mean only "navigated past", which painted steps green that
            // were never configured and kept them green after their connection was
            // deleted. Re-derived every render, so it can go back to grey.
            $isDone = $workflow->stepDone($num);
            $cls = $num === $step ? 'active' : ($isDone ? 'done' : '');
        @endphp
        <div class="wz-step {{ $cls }}"
             @if($workflow->exists && !$isDone && $num !== $step) title="Not finished yet" @endif>
            @if($workflow->exists)
                <a href="{{ route('it.automation.email-workflow.edit', ['workflow' => $workflow->id, 'step' => $num]) }}">
                    <span class="n">{{ $num }}</span> {{ $label }}
                </a>
            @else
                <span class="n">{{ $num }}</span> {{ $label }}
            @endif
        </div>
    @endforeach
</div>

<div class="card">
    <div class="card-body p-4">

    {{-- ════════════════ STEP 1 — Name + Email Source ════════════════ --}}
    @if($step === 1)
        @if(!$workflow->exists)
            {{-- First save: just name → creates the row, then continues. --}}
            <form method="POST" action="{{ route('it.automation.email-workflow.store') }}">
                @csrf
                <div class="section-header"><h6>Name your workflow</h6></div>
                <div class="mb-3">
                    <label class="form-label">Workflow name</label>
                    <input type="text" name="name" class="form-control" maxlength="120"
                           placeholder="e.g. Capture supplier invoices" value="{{ old('name') }}" required>
                    @error('name')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                </div>
                <div class="d-flex justify-content-end">
                    <button type="submit" class="btn btn-primary">Create &amp; continue <i class="bi bi-arrow-right ms-1"></i></button>
                </div>
            </form>
        @else
            {{-- Carrier form holds token/method/step + name only. The provider
                 picker (which renders its own <form>s) sits OUTSIDE it so forms
                 never nest; its radio + the Save button associate back via form=. --}}
            <form id="ewfStep1" method="POST" action="{{ route('it.automation.email-workflow.update', $workflow->id) }}">
                @csrf @method('PUT')
                <input type="hidden" name="step" value="1">

                <div class="section-header"><h6>1 · Name &amp; email source</h6></div>
                <div class="mb-4">
                    <label class="form-label">Workflow name</label>
                    <input type="text" name="name" class="form-control" maxlength="120"
                           value="{{ old('name', $workflow->name) }}" required>
                    @error('name')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                </div>
            </form>

            <label class="form-label">Choose an email provider</label>
            <div class="row g-2 mb-3">
                @foreach(\App\Support\Automation\ProviderRegistry::forCategory('email') as $p)
                    <div class="col-md-6 col-lg-3">
                        <div class="prov-card {{ $p['enabled'] ? '' : 'disabled' }}">
                            <div class="d-flex justify-content-between align-items-start mb-1">
                                <i class="bi {{ $p['icon'] }}"></i>
                                @unless($p['enabled'])<span class="coming-soon">Soon</span>@endunless
                            </div>
                            <div class="pname">{{ $p['name'] }}</div>
                            <div class="pblurb">{{ $p['blurb'] }}</div>
                        </div>
                    </div>
                @endforeach
            </div>

            @include('it.automation.email-workflow.partials.connection-picker', [
                'category' => 'email',
                'field'    => 'email_connection_id',
                'selected' => $workflow->email_connection_id,
                'items'    => $connections['email'],
                'formId'   => 'ewfStep1',
            ])

            <div class="d-flex justify-content-end mt-4">
                <button type="submit" form="ewfStep1" class="btn btn-primary">Save &amp; continue <i class="bi bi-arrow-right ms-1"></i></button>
            </div>
        @endif
    @endif

    {{-- ════════════════ STEP 2 — Detection Rules ════════════════ --}}
    @if($step === 2 && $workflow->exists)
        <form method="POST" action="{{ route('it.automation.email-workflow.update', $workflow->id) }}" id="rulesForm">
            @csrf @method('PUT')
            <input type="hidden" name="step" value="2">

            <div class="section-header d-flex justify-content-between align-items-center">
                <h6 class="mb-0">2 · Detection rules</h6>
                <button type="button" class="btn btn-sm btn-outline-secondary" id="loadInvoicePresetBtn"
                        title="Fill this form with the supplier-invoice descriptors (house document codes + invoice wording). Review, then Save.">
                    <i class="bi bi-magic me-1"></i> Load supplier-invoice preset
                </button>
            </div>
            <p class="text-muted small">
                Decide what counts as a target document. Defaults are pre-filled for invoices &amp; receipts.
                Supplier documents often carry a house reference instead of the word “invoice”
                (<code>CDSB-IV-2608-002</code>, <code>ENSB-IO-02452</code>, <code>I-001068</code>,
                <code>CHS26051383</code>, <code>SOA-20260731</code>) — the preset matches those by filename pattern.
            </p>

            {{-- Subject --}}
            <div class="mb-3 p-3 border rounded">
                <div class="form-check form-switch mb-2">
                    <input class="form-check-input" type="checkbox" name="subject_enabled" value="1" id="subjEnabled"
                           {{ data_get($rules,'subject.enabled') ? 'checked' : '' }}>
                    <label class="form-check-label fw-semibold" for="subjEnabled">Match on subject</label>
                </div>
                <div class="row g-2">
                    <div class="col-md-4">
                        <select name="subject_mode" class="form-select form-select-sm">
                            <option value="contains" {{ data_get($rules,'subject.mode')==='contains'?'selected':'' }}>Contains any keyword</option>
                            <option value="regex" {{ data_get($rules,'subject.mode')==='regex'?'selected':'' }}>Matches pattern (regex)</option>
                        </select>
                    </div>
                    <div class="col-md-8">
                        <input type="text" name="subject_keywords" class="form-control form-control-sm"
                               placeholder="invoice, receipt, credit note (comma-separated)"
                               value="{{ implode(', ', (array) data_get($rules,'subject.keywords', [])) }}">
                    </div>
                </div>
            </div>

            {{-- Body --}}
            <div class="mb-3 p-3 border rounded">
                <div class="form-check form-switch mb-2">
                    <input class="form-check-input" type="checkbox" name="body_enabled" value="1" id="bodyEnabled"
                           {{ data_get($rules,'body.enabled') ? 'checked' : '' }}>
                    <label class="form-check-label fw-semibold" for="bodyEnabled">Match on body (optional)</label>
                </div>
                <div class="row g-2">
                    <div class="col-md-4">
                        <select name="body_mode" class="form-select form-select-sm">
                            <option value="contains" {{ data_get($rules,'body.mode')==='contains'?'selected':'' }}>Contains any keyword</option>
                            <option value="regex" {{ data_get($rules,'body.mode')==='regex'?'selected':'' }}>Matches pattern (regex)</option>
                        </select>
                    </div>
                    <div class="col-md-8">
                        <input type="text" name="body_keywords" class="form-control form-control-sm"
                               placeholder="amount due, payment received…"
                               value="{{ implode(', ', (array) data_get($rules,'body.keywords', [])) }}">
                    </div>
                </div>
            </div>

            {{-- Combine --}}
            <div class="mb-3">
                <label class="form-label">Combine subject &amp; body with</label>
                <select name="combine_subject_body" class="form-select form-select-sm" style="max-width:200px;">
                    <option value="or" {{ data_get($rules,'combine_subject_body')==='or'?'selected':'' }}>OR (either matches)</option>
                    <option value="and" {{ data_get($rules,'combine_subject_body')==='and'?'selected':'' }}>AND (both match)</option>
                </select>
            </div>

            {{-- Attachment --}}
            <div class="mb-3 p-3 border rounded">
                <div class="form-check form-switch mb-2">
                    <input class="form-check-input" type="checkbox" name="attachment_required" value="1" id="attReq"
                           {{ data_get($rules,'attachment.required') ? 'checked' : '' }}>
                    <label class="form-check-label fw-semibold" for="attReq">Require a matching attachment</label>
                </div>
                <label class="form-label small">Allowed file types</label>
                <div class="d-flex flex-wrap gap-3 mb-2">
                    @foreach(['pdf','png','jpg','docx','xlsx'] as $t)
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="attachment_types[]" value="{{ $t }}" id="att_{{ $t }}"
                                   {{ in_array($t, (array) data_get($rules,'attachment.types', [])) ? 'checked' : '' }}>
                            <label class="form-check-label small text-uppercase" for="att_{{ $t }}">{{ $t }}</label>
                        </div>
                    @endforeach
                </div>
                <label class="form-label small">Filename must match</label>
                <div class="row g-2">
                    <div class="col-md-4">
                        <select name="attachment_mode" class="form-select form-select-sm">
                            <option value="contains" {{ data_get($rules,'attachment.filename_mode')==='regex'?'':'selected' }}>Contains any keyword</option>
                            <option value="regex" {{ data_get($rules,'attachment.filename_mode')==='regex'?'selected':'' }}>Matches pattern (regex)</option>
                        </select>
                    </div>
                    <div class="col-md-8">
                        <input type="text" name="attachment_keywords" class="form-control form-control-sm"
                               placeholder="filename keywords e.g. invoice, receipt (leave blank to accept every allowed file type)"
                               value="{{ implode(', ', (array) data_get($rules,'attachment.filename_keywords', [])) }}">
                    </div>
                </div>
                <div class="form-text small">
                    Leave blank to capture every attachment of an allowed type. Regex mode is for house
                    document codes — e.g. <code>CDSB-\s*IV-\d</code> or <code>(?&lt;![a-z0-9])I-\d{6}(?!\d)</code>.
                    A pattern that won’t compile is rejected on save rather than silently ignored.
                </div>
            </div>

            {{-- Sender --}}
            <div class="row g-2 mb-3">
                <div class="col-md-6">
                    <label class="form-label small">Sender allowlist (optional)</label>
                    <input type="text" name="sender_allowlist" class="form-control form-control-sm"
                           placeholder="@trusted.com"
                           value="{{ implode(', ', (array) data_get($rules,'sender.allowlist', [])) }}">
                </div>
                <div class="col-md-6">
                    <label class="form-label small">Sender denylist (optional)</label>
                    <input type="text" name="sender_denylist" class="form-control form-control-sm"
                           placeholder="@spam.com"
                           value="{{ implode(', ', (array) data_get($rules,'sender.denylist', [])) }}">
                </div>
            </div>

            {{-- Capture logic --}}
            <div class="mb-3">
                <label class="form-label">Capture when</label>
                <select name="capture_logic" class="form-select form-select-sm" style="max-width:520px;">
                    @foreach(\App\Models\EmailWorkflow::CAPTURE_LOGICS as $val => $label)
                        <option value="{{ $val }}" {{ data_get($rules,'capture_logic')===$val?'selected':'' }}>{{ $label }}</option>
                    @endforeach
                </select>
                <div class="form-text small">
                    Use <strong>OR</strong> for a dedicated invoice mailbox: a document-shaped filename is
                    enough on its own, and so is invoice wording in the subject (in which case every
                    allowed attachment on that mail is captured).
                </div>
            </div>

            <div class="d-flex justify-content-between align-items-center mt-4">
                <button type="button" class="btn btn-outline-info" id="testRulesBtn">
                    <i class="bi bi-search me-1"></i> Test rules
                </button>
                <button type="submit" class="btn btn-primary">Save &amp; continue <i class="bi bi-arrow-right ms-1"></i></button>
            </div>
        </form>

        {{-- Test-rules preview output --}}
        <div id="testResultWrap" class="mt-3 d-none">
            <div class="section-header"><h6>Preview <small class="text-muted fw-normal" id="testNote"></small></h6></div>
            <div class="test-result border rounded" id="testResult"></div>
        </div>
    @endif

    {{-- ════════════════ STEP 3 — Storage ════════════════ --}}
    @if($step === 3 && $workflow->exists)
        {{-- Carrier form (token/method/step). All inputs + Save associate via
             form="ewfStep3"; the picker sits outside so no <form> nests. --}}
        <form id="ewfStep3" method="POST" action="{{ route('it.automation.email-workflow.update', $workflow->id) }}">
            @csrf @method('PUT')
            <input type="hidden" name="step" value="3">
        </form>

        <div class="section-header"><h6>3 · Storage destination</h6></div>

        <label class="form-label">Choose a storage provider</label>
        <div class="row g-2 mb-3">
            @foreach(\App\Support\Automation\ProviderRegistry::forCategory('storage') as $p)
                <div class="col-md-6 col-lg-3">
                    <div class="prov-card {{ $p['enabled'] ? '' : 'disabled' }}">
                        <div class="d-flex justify-content-between align-items-start mb-1">
                            <i class="bi {{ $p['icon'] }}"></i>
                            @unless($p['enabled'])<span class="coming-soon">Soon</span>@endunless
                        </div>
                        <div class="pname">{{ $p['name'] }}</div>
                        <div class="pblurb">{{ $p['blurb'] }}</div>
                    </div>
                </div>
            @endforeach
        </div>

        @include('it.automation.email-workflow.partials.connection-picker', [
            'category' => 'storage',
            'field'    => 'storage_connection_id',
            'selected' => $workflow->storage_connection_id,
            'items'    => $connections['storage'],
            'formId'   => 'ewfStep3',
        ])

        <div class="mb-3 mt-3">
            <label class="form-label">Destination folder (paste a Google Drive folder link or ID)</label>
            <input type="text" name="folder_ref" form="ewfStep3" class="form-control"
                   placeholder="https://drive.google.com/drive/folders/…"
                   value="{{ old('folder_ref', data_get($storage,'folder_ref')) }}">
            <div class="form-text">
                Paste a link to a folder the connected Google account can edit. You can also type a folder
                <em>name</em> instead — the automation will create it in that account's Drive and reuse it.<br>
                <i class="bi bi-info-circle"></i>
                If a pasted link is rejected as “not accessible”, the Drive account was authorized before this
                app requested full Drive access — press <strong>Connect</strong> on the Drive connection again to
                re-consent.
            </div>
        </div>
        <div class="form-check form-switch mb-3">
            <input class="form-check-input" type="checkbox" name="monthly_subfolders" form="ewfStep3" value="1" id="monthlySub"
                   {{ data_get($storage,'monthly_subfolders') ? 'checked' : '' }}>
            <label class="form-check-label" for="monthlySub">Organize into sub-folders by month (YYYY-MM)</label>
        </div>
        <div class="mb-3">
            <label class="form-label">Filename template</label>
            <input type="text" name="filename_template" form="ewfStep3" class="form-control" style="max-width:420px;"
                   value="{{ $filenameTemplate }}">
            <div class="form-text">Placeholders: <code>{{ $phDate }}</code>, <code>{{ $phName }}</code></div>
        </div>

        <div class="d-flex justify-content-end mt-4">
            <button type="submit" form="ewfStep3" class="btn btn-primary">Save &amp; continue <i class="bi bi-arrow-right ms-1"></i></button>
        </div>
    @endif

    {{-- ════════════════ STEP 4 — Log Destination ════════════════ --}}
    @if($step === 4 && $workflow->exists)
        {{-- Carrier form (token/method/step). Inputs associate via form="ewfStep4";
             picker sits outside so no <form> nests. --}}
        <form id="ewfStep4" method="POST" action="{{ route('it.automation.email-workflow.update', $workflow->id) }}">
            @csrf @method('PUT')
            <input type="hidden" name="step" value="4">
        </form>

        <div class="section-header"><h6>4 · Log destination &amp; column mapping</h6></div>

        <label class="form-label">Choose a log provider</label>
        <div class="row g-2 mb-3">
            @foreach(\App\Support\Automation\ProviderRegistry::forCategory('log') as $p)
                <div class="col-md-6 col-lg-3">
                    <div class="prov-card {{ $p['enabled'] ? '' : 'disabled' }}">
                        <div class="d-flex justify-content-between align-items-start mb-1">
                            <i class="bi {{ $p['icon'] }}"></i>
                            @unless($p['enabled'])<span class="coming-soon">Soon</span>@endunless
                        </div>
                        <div class="pname">{{ $p['name'] }}</div>
                        <div class="pblurb">{{ $p['blurb'] }}</div>
                    </div>
                </div>
            @endforeach
        </div>

        @include('it.automation.email-workflow.partials.connection-picker', [
            'category' => 'log',
            'field'    => 'log_connection_id',
            'selected' => $workflow->log_connection_id,
            'items'    => $connections['log'],
            'formId'   => 'ewfStep4',
        ])

        <div class="mb-3 mt-3">
            <label class="form-label">Destination sheet (paste a Google Sheet link or ID)</label>
            <input type="text" name="target_ref" form="ewfStep4" class="form-control"
                   placeholder="https://docs.google.com/spreadsheets/d/…"
                   value="{{ old('target_ref', data_get($log,'target_ref')) }}">
        </div>
        <div class="form-check form-switch mb-3">
            <input class="form-check-input" type="checkbox" name="partition_by_month" form="ewfStep4" value="1" id="partMonth"
                   {{ data_get($log,'partition_by_month') ? 'checked' : '' }}>
            <label class="form-check-label" for="partMonth">One tab per month</label>
        </div>

        <label class="form-label">Columns</label>
        <p class="text-muted small">Each captured document becomes a row. Map each column to a data source. A hidden idempotency key (Message ID + file name) prevents duplicates.</p>
        <div id="colMapWrap">
            @foreach((array) data_get($log,'columns', []) as $col)
                <div class="col-map-row">
                    <input type="text" name="col_label[]" form="ewfStep4" class="form-control form-control-sm" value="{{ $col['label'] ?? '' }}" placeholder="Column name">
                    <select name="col_source[]" form="ewfStep4" class="form-select form-select-sm" style="max-width:220px;">
                        @foreach(['email.date'=>'Email · Date','email.from'=>'Email · From','email.subject'=>'Email · Subject','email.message_id'=>'Email · Message ID','attachment.name'=>'Attachment · File name','storage.url'=>'Storage · File link','parsed.amount'=>'Parsed · Amount','parsed.description'=>'Parsed · Description'] as $val=>$lbl)
                            <option value="{{ $val }}" {{ ($col['source'] ?? '')===$val ? 'selected':'' }}>{{ $lbl }}</option>
                        @endforeach
                    </select>
                    <button type="button" class="btn btn-sm btn-outline-danger col-remove"><i class="bi bi-x"></i></button>
                </div>
            @endforeach
        </div>
        <button type="button" class="btn btn-sm btn-outline-secondary" id="addColBtn"><i class="bi bi-plus-lg me-1"></i>Add column</button>

        <div class="d-flex justify-content-end mt-4">
            <button type="submit" form="ewfStep4" class="btn btn-primary">Save &amp; continue <i class="bi bi-arrow-right ms-1"></i></button>
        </div>
    @endif

    {{-- ════════════════ STEP 5 — Schedule ════════════════ --}}
    @if($step === 5 && $workflow->exists)
        <form method="POST" action="{{ route('it.automation.email-workflow.update', $workflow->id) }}">
            @csrf @method('PUT')
            <input type="hidden" name="step" value="5">
            <input type="hidden" name="action" value="finish">

            <div class="section-header"><h6>5 · Schedule &amp; activate</h6></div>

            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Timezone</label>
                    <select name="timezone" class="form-select">
                        @foreach($tzList as $tz)
                            <option value="{{ $tz }}" {{ $workflow->timezone===$tz ? 'selected':'' }}>{{ $tz }}</option>
                        @endforeach
                    </select>
                </div>
                {{-- Show the FORMAT, never a prose description. The hint here used
                     to read "Default: daily 19:00 local"; typed back into a field
                     labelled "cron" it saved a schedule the scheduler silently
                     skipped forever. Both fields are now validated server-side. --}}
                <div class="col-md-4">
                    <label class="form-label">Capture schedule (cron)</label>
                    <input type="text" name="capture_cron" class="form-control"
                           placeholder="0 19 * * *" spellcheck="false"
                           value="{{ old('capture_cron', $workflow->capture_cron ?: '0 19 * * *') }}">
                    <div class="form-text">
                        Cron syntax, e.g. <code>0 19 * * *</code> = 19:00 daily. Runs in the timezone above.
                    </div>
                    @error('capture_cron')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-4">
                    <label class="form-label">Reconcile schedule (cron)</label>
                    <input type="text" name="reconcile_cron" class="form-control"
                           placeholder="0 7 * * *" spellcheck="false"
                           value="{{ old('reconcile_cron', $workflow->reconcile_cron ?: '0 7 * * *') }}">
                    <div class="form-text">
                        Cron syntax, e.g. <code>0 7 * * *</code> = 07:00 daily — the double-check sweep.
                    </div>
                    @error('reconcile_cron')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                </div>
            </div>

            <div class="form-check form-switch mt-3">
                <input class="form-check-input" type="checkbox" name="first_sweep_on_activate" value="1" id="firstSweep"
                       {{ $workflow->first_sweep_on_activate ? 'checked' : '' }}>
                <label class="form-check-label" for="firstSweep">Run an immediate first sweep (load history) when activated</label>
            </div>

            {{-- Name the outstanding items. The old copy just said "connect an email
                 source, storage folder and log sheet", which left the operator to
                 guess which of the five was actually unmet. --}}
            @php $missing = $workflow->missingRequirements(); @endphp
            @if($missing)
                <div class="alert alert-warning mt-3 mb-0 small">
                    <div class="mb-1">
                        <i class="bi bi-exclamation-triangle me-1"></i>
                        <strong>{{ count($missing) }} thing{{ count($missing) === 1 ? '' : 's' }}</strong>
                        still to do before this workflow can be switched <strong>Active</strong>:
                    </div>
                    <ul class="mb-0 ps-4">
                        @foreach($missing as $item)
                            <li>{{ ucfirst($item) }}</li>
                        @endforeach
                    </ul>
                </div>
            @else
                <div class="alert alert-success mt-3 mb-0 small">
                    <i class="bi bi-check-circle me-1"></i>
                    Everything is configured — save, then switch this workflow <strong>Active</strong>
                    (or press <i class="bi bi-play-fill"></i> on the list to run it once now).
                </div>
            @endif

            <div class="d-flex justify-content-end mt-4">
                <button type="submit" class="btn btn-success"><i class="bi bi-check-lg me-1"></i> Save &amp; finish</button>
            </div>
        </form>
    @endif

    </div>
</div>
@endsection

@push('scripts')
<script nonce="{{ $cspNonce ?? '' }}">
(function () {
    // ── Step 4: dynamic column map rows ──
    var addBtn = document.getElementById('addColBtn');
    var wrap = document.getElementById('colMapWrap');
    function bindRemove(btn) {
        btn.addEventListener('click', function () { this.closest('.col-map-row').remove(); });
    }
    document.querySelectorAll('.col-remove').forEach(bindRemove);
    if (addBtn && wrap) {
        addBtn.addEventListener('click', function () {
            var row = document.createElement('div');
            row.className = 'col-map-row';
            var sources = [
                ['email.date','Email · Date'],['email.from','Email · From'],
                ['email.subject','Email · Subject'],['email.message_id','Email · Message ID'],
                ['attachment.name','Attachment · File name'],['storage.url','Storage · File link'],
                ['parsed.amount','Parsed · Amount'],['parsed.description','Parsed · Description']
            ];
            var inp = document.createElement('input');
            inp.type='text'; inp.name='col_label[]'; inp.className='form-control form-control-sm'; inp.placeholder='Column name';
            inp.setAttribute('form','ewfStep4');   // associate with the step-4 carrier form
            var sel = document.createElement('select');
            sel.name='col_source[]'; sel.className='form-select form-select-sm'; sel.style.maxWidth='220px';
            sel.setAttribute('form','ewfStep4');
            sources.forEach(function (s) {
                var o=document.createElement('option'); o.value=s[0]; o.textContent=s[1]; sel.appendChild(o);
            });
            var rm = document.createElement('button');
            rm.type='button'; rm.className='btn btn-sm btn-outline-danger col-remove';
            rm.innerHTML='<i class="bi bi-x"></i>';
            row.appendChild(inp); row.appendChild(sel); row.appendChild(rm);
            wrap.appendChild(row);
            bindRemove(rm);
        });
    }

    // ── Step 2: Test rules (AJAX, read-only preview) ──
    var testBtn = document.getElementById('testRulesBtn');
    if (testBtn) {
        testBtn.addEventListener('click', function () {
            var form = document.getElementById('rulesForm');
            var data = new FormData(form);
            data.set('_method', 'POST'); // route is POST, not PUT
            var btn = this;
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Testing…';
            fetch("{{ $workflow->exists ? route('it.automation.email-workflow.test-rules', $workflow->id) : '' }}", {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                body: data
            })
            .then(function (r) { return r.json(); })
            .then(function (json) { renderTest(json); })
            .catch(function () { renderTestError(); })
            .finally(function () {
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-search me-1"></i> Test rules';
            });
        });
    }

    // ── Step 2: load the supplier-invoice preset into the form ──
    // Fills the form only — nothing is stored until the operator reviews and
    // saves, so this can never silently rewrite live detection rules.
    var presetBtn = document.getElementById('loadInvoicePresetBtn');
    if (presetBtn) {
        var PRESET = @json(\App\Models\EmailWorkflow::SUPPLIER_INVOICE_RULES);
        presetBtn.addEventListener('click', function () {
            var form = document.getElementById('rulesForm');
            if (!form) { return; }

            function set(name, value) {
                var el = form.querySelector('[name="' + name + '"]');
                if (el) { el.value = value; }
            }
            function check(name, on) {
                var el = form.querySelector('[name="' + name + '"]');
                if (el) { el.checked = !!on; }
            }

            check('subject_enabled', PRESET.subject.enabled);
            set('subject_mode', PRESET.subject.mode);
            set('subject_keywords', PRESET.subject.keywords.join(', '));

            check('body_enabled', PRESET.body.enabled);
            set('body_mode', PRESET.body.mode);
            set('body_keywords', PRESET.body.keywords.join(', '));

            set('combine_subject_body', PRESET.combine_subject_body);

            check('attachment_required', PRESET.attachment.required);
            form.querySelectorAll('[name="attachment_types[]"]').forEach(function (box) {
                box.checked = PRESET.attachment.types.indexOf(box.value) !== -1;
            });
            set('attachment_mode', PRESET.attachment.filename_mode);
            set('attachment_keywords', PRESET.attachment.filename_keywords.join(', '));

            set('capture_logic', PRESET.capture_logic);

            presetBtn.innerHTML = '<i class="bi bi-check2 me-1"></i> Preset loaded — review, then Save';
            presetBtn.classList.remove('btn-outline-secondary');
            presetBtn.classList.add('btn-outline-success');
        });
    }

    function escHtml(s) {
        var d = document.createElement('div'); d.textContent = (s == null ? '' : String(s)); return d.innerHTML;
    }
    function renderTest(json) {
        var wrap = document.getElementById('testResultWrap');
        var out = document.getElementById('testResult');
        document.getElementById('testNote').textContent = json.note || '';
        out.innerHTML = '';
        (json.results || []).forEach(function (r) {
            var amount = r.amount != null ? (escHtml(r.currency || '') + ' ' + escHtml(r.amount)) : '—';
            var atts = (r.attachments || []).map(escHtml).join(', ') || '—';
            var badge = r.matched
                ? '<span class="badge bg-success">Match</span>'
                : '<span class="badge bg-secondary">Skip</span>';
            var reasons = (r.reasons || []).map(escHtml).join(' · ');
            var div = document.createElement('div');
            div.className = 'row-item';
            div.innerHTML =
                '<div style="min-width:60px;">' + badge + '</div>' +
                '<div style="flex:1;">' +
                    '<div class="fw-semibold">' + escHtml(r.subject) + '</div>' +
                    '<div class="text-muted">' + escHtml(r.from) + '</div>' +
                    (reasons ? '<div class="text-muted">' + reasons + '</div>' : '') +
                    '<div class="text-muted">Attachments: ' + atts + ' · Amount: ' + amount + '</div>' +
                '</div>';
            out.appendChild(div);
        });
        wrap.classList.remove('d-none');
    }
    function renderTestError() {
        var wrap = document.getElementById('testResultWrap');
        var out = document.getElementById('testResult');
        out.innerHTML = '<div class="row-item text-danger">Could not run the test. Please try again.</div>';
        wrap.classList.remove('d-none');
    }
})();
</script>
@endpush
