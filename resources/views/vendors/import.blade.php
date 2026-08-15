@extends('layouts.app')
@section('title', 'Import Vendors')
@section('page-title', 'Import Vendors')

@section('content')
@include('partials.dashboard-widgets-style')
@include('partials.decommission-ui-style')
@include('partials.vendor-ui-style')

@php
    use App\Support\VendorImport\ColumnMapper;

    // How each column was decided, in the operator's terms. `header` needs no explanation —
    // the caption said so — but everything else does, because a mapping arrived at from the
    // DATA is a guess and has to look like one.
    $viaLabel = [
        'header' => ['icon' => 'bi-check-circle-fill', 'class' => 'text-success', 'text' => 'Matched the column heading'],
        'header-partial' => ['icon' => 'bi-exclamation-circle-fill', 'class' => 'text-warning', 'text' => 'Guessed from wording inside the heading — check this one'],
        'values' => ['icon' => 'bi-exclamation-circle-fill', 'class' => 'text-warning', 'text' => 'Guessed from the values in the column — check this one'],
        'operator' => ['icon' => 'bi-person-check-fill', 'class' => 'text-primary', 'text' => 'You chose this'],
    ];

    // Badges state the CONSEQUENCE, not a category. "Create" named a thing; "Will be
    // created" answers the question the operator actually has in front of this table.
    $actionBadge = [
        'create' => ['bg' => 'success', 'text' => 'Will be created'],
        'duplicate' => ['bg' => 'secondary', 'text' => 'Already registered'],
        'error' => ['bg' => 'danger', 'text' => 'Cannot import'],
    ];

    // The columns shown in the row table. Every mapped field would be far too wide, so this
    // is the identity plus the fields most often mis-mapped — the rest are still imported
    // and are listed under "everything else read from this row" per row.
    $previewFields = ['name', 'company_registration_no', 'pic_name', 'pic_email', 'pic_phone', 'vendor_types'];
    $mappedFields = array_keys(ColumnMapper::byField($mapping));
    $shownFields = array_values(array_intersect($previewFields, $mappedFields));
    $extraFields = array_values(array_diff($mappedFields, $shownFields));

    $importable = $counts['create'] + ($mode === 'update' ? $counts['duplicate'] : 0);
    $fieldTotal = count(ColumnMapper::FIELDS);

    // Everything the importer could not read, gathered up so it can be stated ONCE at the
    // top. Buried per-row it is only findable by scrolling every row, which on a long sheet
    // means it is not findable at all — and each of these lands in the vendor master as a
    // blank field nobody was told about.
    $attention = [];

    foreach ($rows as $attnRow) {
        $attnName = $attnRow['attributes']['name'] ?? '';

        if ($attnRow['error']) {
            $attention[] = ['line' => $attnRow['line'], 'name' => $attnName, 'text' => $attnRow['error'], 'bad' => true];
        }

        foreach ($attnRow['notes'] as $attnNote) {
            $attention[] = ['line' => $attnRow['line'], 'name' => $attnName, 'text' => $attnNote, 'bad' => false];
        }
    }

    // A long sheet can produce a note per row (a missing service-type column does exactly
    // that). Listing 200 of them would bury the handful that are specific, so the panel
    // shows the first dozen and says how many it did not print.
    $attentionShown = array_slice($attention, 0, 12);
    $attentionHidden = count($attention) - count($attentionShown);
@endphp

<div class="container-fluid px-0">
    <div class="card vnd-hero mb-3">
        <div class="card-body py-3 d-flex align-items-center justify-content-between flex-wrap gap-3">
            <div class="d-flex align-items-center gap-3">
                <span class="vnd-hero-icon"><i class="bi bi-file-earmark-arrow-up"></i></span>
                <div>
                    <h5 class="text-white mb-0 fw-bold">Check before importing</h5>
                    @php
                        $vndSource = $batch->original_filename.($sheet ? ' · sheet "'.$sheet.'"' : '');
                    @endphp
                    <small class="text-white-50 d-block">{{ $vndSource }}</small>
                    <small class="text-white-50 d-block mt-1">
                        <i class="bi bi-shield-check me-1"></i>Nothing has been saved yet. This page only shows what
                        the system read from your file.
                    </small>
                </div>
            </div>
            <form action="{{ route('vendors.import.discard', $batch->token) }}" method="POST" class="js-confirm"
                  data-confirm="Discard this uploaded list? Nothing has been imported yet, so nothing is lost — you would just need to upload the file again."
                  data-confirm-title="Cancel import"
                  data-confirm-ok="Discard"
                  data-confirm-variant="danger">
                @csrf
                <button class="btn btn-light btn-sm fw-semibold"><i class="bi bi-x-lg me-1"></i>Cancel import</button>
            </form>
        </div>
    </div>

    {{-- Where the operator is standing. The upload already happened and the import has not,
         which is the single fact this page most needs to communicate on sight. --}}
    <div class="vnd-imp-trail mb-3">
        <span class="vnd-imp-trail-step vnd-imp-trail-done">
            <span class="vnd-imp-trail-no"><i class="bi bi-check-lg"></i></span>Your file was read
        </span>
        <i class="bi bi-chevron-right vnd-imp-trail-sep"></i>
        <span class="vnd-imp-trail-step vnd-imp-trail-now">
            <span class="vnd-imp-trail-no">2</span>Check what it says — you are here
        </span>
        <i class="bi bi-chevron-right vnd-imp-trail-sep"></i>
        <span class="vnd-imp-trail-step">
            <span class="vnd-imp-trail-no">3</span>Vendors are created
        </span>
    </div>

    @if($truncated)
    <div class="alert alert-warning d-flex align-items-start gap-2 py-2 mb-3" style="font-size:13px;">
        <i class="bi bi-exclamation-triangle-fill mt-1"></i>
        <div>
            <strong>Only the first {{ \App\Support\VendorImport\SpreadsheetReader::MAX_ROWS }} rows were read.</strong>
            The rest of the file is not shown below and will not be imported &mdash; split the list and import it in parts.
        </div>
    </div>
    @endif

    @unless($headerConfident)
    <div class="alert alert-warning d-flex align-items-start gap-2 py-2 mb-3" style="font-size:13px;">
        <i class="bi bi-question-circle-fill mt-1"></i>
        <div>
            <strong>The heading row was not recognised.</strong>
            Row {{ $headerLine }} is being read as the headings, but almost none of them matched a known field.
            Pick the right row below, or map each column by hand.
        </div>
    </div>
    @endunless

    {{-- ── What pressing the button will do, in a sentence ─────────────────── --}}
    <div class="vnd-imp-outcome mb-3">
        <i class="bi bi-info-circle me-1"></i>
        Pressing <strong>Import</strong> at the bottom of this page will add
        <strong class="js-vnd-ready">{{ $importable === 1 ? '1 vendor' : $importable.' vendors' }}</strong>
        to the vendor directory.
        @if($counts['error'])
            {{ $counts['error'] === 1 ? '1 row' : $counts['error'].' rows' }} cannot be read and will be left out.
        @endif
        Until then nothing in this file has been saved &mdash; leaving this page changes nothing.
    </div>

    {{-- ── Counts ─────────────────────────────────────────────────────────── --}}
    <div class="row g-3 mb-3">
        <div class="col-6 col-lg-3">
            <div class="card dash-widget vnd-kpi">
                <div class="widget-header" style="background:linear-gradient(135deg,#22c55e,#15803d);">
                    <div class="d-flex align-items-center gap-3">
                        <div class="widget-icon"><i class="bi bi-plus-circle"></i></div>
                        <div>
                            <div class="widget-number">{{ $counts['create'] }}</div>
                            <div class="widget-label">New vendors</div>
                            <div class="vnd-kpi-note">Not in the directory yet</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card dash-widget vnd-kpi">
                <div class="widget-header" style="background:linear-gradient(135deg,#94a3b8,#64748b);">
                    <div class="d-flex align-items-center gap-3">
                        <div class="widget-icon"><i class="bi bi-files"></i></div>
                        <div>
                            <div class="widget-number">{{ $counts['duplicate'] }}</div>
                            <div class="widget-label">Already registered</div>
                            <div class="vnd-kpi-note">Same name is already on file</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card dash-widget vnd-kpi">
                <div class="widget-header" style="background:linear-gradient(135deg,{{ $counts['error'] ? '#ef4444,#b91c1c' : '#94a3b8,#64748b' }});">
                    <div class="d-flex align-items-center gap-3">
                        <div class="widget-icon"><i class="bi bi-exclamation-octagon"></i></div>
                        <div>
                            <div class="widget-number">{{ $counts['error'] }}</div>
                            <div class="widget-label">Cannot import</div>
                            <div class="vnd-kpi-note">Rows with no usable vendor name</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card dash-widget vnd-kpi">
                <div class="widget-header" style="background:linear-gradient(135deg,#0ea5e9,#0369a1);">
                    <div class="d-flex align-items-center gap-3">
                        <div class="widget-icon"><i class="bi bi-columns-gap"></i></div>
                        <div>
                            <div class="widget-number">{{ count($mappedFields) }}</div>
                            <div class="widget-label">Fields recognised</div>
                            <div class="vnd-kpi-note">of the {{ $fieldTotal }} this system stores</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ── What could not be read ─────────────────────────────────────────── --}}
    @if($attention)
    <div class="vnd-imp-attention mb-3">
        <div class="vnd-imp-attention-head">
            <i class="bi bi-exclamation-triangle-fill"></i>
            <span>{{ count($attention) === 1 ? '1 thing' : count($attention).' things' }} worth a look before you import</span>
        </div>
        <ul class="vnd-imp-attention-list">
            @foreach($attentionShown as $item)
                <li>
                    <i class="bi {{ $item['bad'] ? 'bi-x-octagon-fill' : 'bi-exclamation-triangle-fill' }} me-1"></i>
                    <a href="#vnd-row-{{ $item['line'] }}">Row {{ $item['line'] }}@if($item['name']) &mdash; {{ $item['name'] }}@endif</a>
                    &middot; {{ $item['text'] }}
                </li>
            @endforeach
            @if($attentionHidden > 0)
                <li class="fst-italic">&hellip; and {{ $attentionHidden }} more, shown under their own rows below.</li>
            @endif
        </ul>
    </div>
    @endif

    {{-- ── Step 1: the mapping ────────────────────────────────────────────── --}}
    <div class="card ewx-card mb-3">
        <div class="ewx-head">
            <span class="ewx-chip ewx-chip-slate"><i class="bi bi-diagram-3"></i></span>
            <div class="me-2">
                <span class="ewx-title">Step 1 &mdash; Check the columns</span>
                <span class="ewx-sub">Your headings are not this system's field names, so each column was matched to one. Correct anything it got wrong, then re-check.</span>
            </div>
        </div>
        <div class="card-body">
            <form method="GET" action="{{ route('vendors.import.preview', $batch->token) }}">
                <input type="hidden" name="mode" value="{{ $mode }}">

                <div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
                    <label for="headerLine" class="form-label mb-0 fw-semibold" style="font-size:13px;">Heading row</label>
                    <select name="header_line" id="headerLine" class="form-select form-select-sm" style="width:120px;">
                        @foreach($headerOptions as $line)
                            <option value="{{ $line }}" {{ $line === $headerLine ? 'selected' : '' }}>Row {{ $line }}</option>
                        @endforeach
                    </select>
                    <span class="text-muted" style="font-size:12px;">Rows above it are ignored; rows below it are the vendors.</span>
                </div>

                <div class="table-responsive">
                    <table class="table table-sm ewx-table align-middle mb-0">
                        <thead>
                            <tr>
                                <th class="ps-3" style="width:24%;">Column in your file</th>
                                <th style="width:28px;"></th>
                                <th style="width:24%;">Is stored as</th>
                                <th style="width:22%;">How it was matched</th>
                                <th>What is in that column</th>
                            </tr>
                        </thead>
                        <tbody>
                        @foreach($mapping as $index => $column)
                            @php
                                $via = $column['via'] ? ($viaLabel[$column['via']] ?? null) : null;
                                $letter = '';
                                $n = $index;
                                do { $letter = chr(65 + ($n % 26)).$letter; $n = intdiv($n, 26) - 1; } while ($n >= 0);
                                $examples = collect($rows)->pluck('cells.'.$index)->filter(fn ($v) => trim((string) $v) !== '')->take(3);
                            @endphp
                            <tr>
                                <td class="ps-3">
                                    <span class="badge rounded-pill bg-light text-dark border me-1">{{ $letter }}</span>
                                    <strong>{{ $column['header'] !== '' ? $column['header'] : '(no heading)' }}</strong>
                                </td>
                                <td class="text-center px-0"><i class="bi bi-arrow-right vnd-imp-arrow"></i></td>
                                <td>
                                    <select name="map[{{ $index }}]" class="form-select form-select-sm">
                                        <option value="">— Do not import this column —</option>
                                        @foreach(ColumnMapper::FIELDS as $key => $label)
                                            <option value="{{ $key }}" {{ $column['field'] === $key ? 'selected' : '' }}>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td style="font-size:12px;">
                                    @if($via)
                                        <span class="{{ $via['class'] }}"><i class="bi {{ $via['icon'] }} me-1"></i>{{ $via['text'] }}</span>
                                    @else
                                        <span class="text-muted">Not recognised &mdash; pick a field if this column is needed</span>
                                    @endif
                                </td>
                                <td class="text-muted" style="font-size:12px;">
                                    {{ $examples->map(fn ($v) => \Illuminate\Support\Str::limit(str_replace("\n", ' ', $v), 40))->implode(' · ') ?: '—' }}
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="d-flex align-items-center justify-content-between mt-3 flex-wrap gap-2">
                    <div class="text-muted" style="font-size:12px;">
                        @if($unmapped)
                            <i class="bi bi-info-circle me-1"></i>Your file has no column for:
                            {{ collect($unmapped)->map(fn ($f) => ColumnMapper::FIELDS[$f])->implode(', ') }}.
                            These stay blank &mdash; fill them in on each vendor's profile later.
                        @else
                            <i class="bi bi-check2-circle me-1"></i>Every field this system stores was found in your file.
                        @endif
                    </div>
                    <button class="btn btn-sm btn-outline-primary"><i class="bi bi-arrow-repeat me-1"></i>Re-check with these columns</button>
                </div>
            </form>
        </div>
    </div>

    {{-- ── Step 2: the rows ───────────────────────────────────────────────── --}}
    <form action="{{ route('vendors.import.commit', $batch->token) }}" method="POST">
        @csrf
        <input type="hidden" name="header_line" value="{{ $headerLine }}">
        {{-- The approved mapping travels with the confirmation, so the commit reads the file
             exactly the way this page did. The FILE is re-read server-side; only the mapping
             comes from here. --}}
        @foreach($mapping as $index => $column)
            <input type="hidden" name="map[{{ $index }}]" value="{{ $column['field'] }}">
        @endforeach

        <div class="card ewx-card">
            <div class="ewx-head">
                <span class="ewx-chip ewx-chip-slate"><i class="bi bi-list-check"></i></span>
                <div class="me-2">
                    <span class="ewx-title">Step 2 &mdash; Choose which vendors to import</span>
                    <span class="ewx-sub">One line per vendor found in your file. Untick anything that should not be registered; rows in red cannot be imported at all.</span>
                </div>
                <span class="ewx-count">{{ count($rows) }}</span>
            </div>

            <div class="card-body py-2 border-bottom {{ $counts['duplicate'] ? '' : 'vnd-imp-inert' }}">
                <div class="d-flex align-items-center gap-3 flex-wrap">
                    <label class="fw-semibold mb-0" style="font-size:13px;">If a vendor is already registered under the same name:</label>
                    <div class="form-check form-check-inline mb-0">
                        <input class="form-check-input" type="radio" name="mode" id="modeSkip" value="skip" {{ $mode === 'update' ? '' : 'checked' }}>
                        <label class="form-check-label" for="modeSkip" style="font-size:13px;">Leave it alone</label>
                    </div>
                    <div class="form-check form-check-inline mb-0">
                        <input class="form-check-input" type="radio" name="mode" id="modeUpdate" value="update" {{ $mode === 'update' ? 'checked' : '' }}>
                        <label class="form-check-label" for="modeUpdate" style="font-size:13px;">Fill in blanks from the sheet</label>
                    </div>
                    <span class="text-muted" style="font-size:12px;">
                        @if($counts['duplicate'])
                            <i class="bi bi-shield-check me-1"></i>Filling in only ADDS what the sheet has &mdash; it never blanks a field already on record.
                        @else
                            <i class="bi bi-dash-circle me-1"></i>No vendor in this file is registered yet, so this choice changes nothing here.
                        @endif
                    </span>
                </div>
            </div>

            <div class="card-body p-0">
                @if($rows === [])
                    <div class="ewx-empty"><i class="bi bi-inbox"></i>No rows below the heading row.</div>
                @else
                <div class="table-responsive">
                    <table class="table table-hover ewx-table align-middle">
                        <thead>
                            <tr>
                                <th class="ps-3" style="width:34px;">
                                    <input type="checkbox" class="form-check-input" id="vndImportAll" checked
                                           aria-label="Select or clear every importable row">
                                </th>
                                <th style="width:60px;">Row</th>
                                @foreach($shownFields as $field)
                                    <th>{{ ColumnMapper::FIELDS[$field] }}</th>
                                @endforeach
                                <th class="text-center" style="width:140px;">What will happen</th>
                            </tr>
                        </thead>
                        <tbody>
                        @foreach($rows as $row)
                            @php $badge = $actionBadge[$row['action']]; @endphp
                            <tr id="vnd-row-{{ $row['line'] }}" class="{{ $row['action'] === 'error' ? 'table-danger' : ($row['action'] === 'duplicate' ? 'vnd-row-off' : '') }}">
                                <td class="ps-3">
                                    @if($row['action'] !== 'error')
                                        <input type="checkbox" class="form-check-input js-vnd-import-row" name="rows[]"
                                               value="{{ $row['line'] }}" checked
                                               data-action="{{ $row['action'] }}"
                                               aria-label="Import row {{ $row['line'] }}">
                                    @endif
                                </td>
                                <td class="text-muted">{{ $row['line'] }}</td>

                                @foreach($shownFields as $field)
                                    <td style="font-size:13px;">
                                        @if($field === 'vendor_types')
                                            @forelse($row['attributes']['vendor_types'] ?? [] as $t)
                                                <span class="vnd-type vnd-type-{{ $t }}">{{ \App\Models\Vendor::TYPES[$t] ?? $t }}</span>
                                            @empty
                                                <span class="text-muted">—</span>
                                            @endforelse
                                        @else
                                            {{ $row['attributes'][$field] ?? '—' }}
                                        @endif
                                    </td>
                                @endforeach

                                <td class="text-center">
                                    <span class="badge rounded-pill bg-{{ $badge['bg'] }}">{{ $badge['text'] }}</span>
                                    @if($row['action'] === 'duplicate')
                                        {{-- Text kept in step with the mode radio by the script
                                             at the foot of the page: the radio does not reload,
                                             so a server-rendered consequence would still read
                                             "Will be skipped" on a submit that updates it. --}}
                                        <div class="vnd-pic-meta mt-1 js-vnd-dup-note">
                                            {{ $mode === 'update' ? 'Will be filled in' : 'Will be skipped' }}
                                        </div>
                                    @endif
                                </td>
                            </tr>

                            {{-- Everything the importer decided about this row, under the row it
                                 decided it about. Kept out of the columns above because these are
                                 exceptions: putting them in a column would make 200 clean rows
                                 carry a mostly-empty field, and bury the handful that matter.

                                 The WARNINGS stay visible; the rest of what was read is folded
                                 away. Printed side by side at the same size, the two were
                                 indistinguishable, and the sentence saying a field had been
                                 dropped read as one more item in a data dump. --}}
                            @php
                                // Fields whose values are sentences rather than identifiers. They take the
                                // full width below, where a 215px column would wrap an address to five
                                // lines beside four mostly-empty neighbours.
                                $wideFields = ['address', 'notes', 'sst_categories'];

                                $alsoRead = collect($extraFields)
                                    ->map(function ($f) use ($row) {
                                        $v = $row['attributes'][$f] ?? null;
                                        if ($v === null || $v === '' || $v === []) return null;
                                        if ($f === 'sst_categories') $v = collect((array) $v)->map(fn ($k) => \App\Models\Vendor::sstLabelFor($k))->implode(', ');
                                        if ($f === 'is_active') $v = $v ? 'Active' : 'Inactive';
                                        if ($f === 'industry') $v = \App\Models\Vendor::INDUSTRIES[$v] ?? $v;

                                        return [
                                            'field' => $f,
                                            'label' => ColumnMapper::FIELDS[$f],
                                            // Generous, not tight: this panel exists to be checked against
                                            // the sheet, and a value cut at 60 characters cannot be. Notes
                                            // run to 2000 in the column, which is the one case worth capping.
                                            'value' => \Illuminate\Support\Str::limit((string) $v, 300),
                                        ];
                                    })
                                    ->filter()
                                    ->values();
                                $hasMore = $alsoRead->isNotEmpty() && ! $row['error'];
                            @endphp

                            @if($row['error'] || $row['notes'] || $hasMore)
                            <tr class="{{ $row['action'] === 'error' ? 'table-danger' : '' }}">
                                <td></td>
                                <td colspan="{{ count($shownFields) + 2 }}" class="pt-0">
                                    @if($row['error'])
                                        <div class="vnd-imp-note vnd-imp-note-bad">
                                            <i class="bi bi-x-octagon-fill"></i><span>{{ $row['error'] }}</span>
                                        </div>
                                    @endif
                                    @foreach($row['notes'] as $note)
                                        <div class="vnd-imp-note">
                                            <i class="bi bi-exclamation-triangle-fill"></i><span>{{ $note }}</span>
                                        </div>
                                    @endforeach
                                    @if($hasMore)
                                        <button class="vnd-imp-more-btn" type="button"
                                                data-bs-toggle="collapse" data-bs-target="#vndMore{{ $row['line'] }}"
                                                aria-expanded="false" aria-controls="vndMore{{ $row['line'] }}">
                                            Show the other {{ $alsoRead->count() }} {{ $alsoRead->count() === 1 ? 'field' : 'fields' }} read from this row
                                        </button>
                                        <div class="collapse" id="vndMore{{ $row['line'] }}">
                                            <div class="vnd-imp-fields">
                                                @foreach($alsoRead as $item)
                                                    <div class="{{ in_array($item['field'], $wideFields, true) ? 'vnd-imp-field-wide' : '' }}">
                                                        <div class="vnd-label">{{ $item['label'] }}</div>
                                                        <div class="vnd-value">{{ $item['value'] }}</div>
                                                    </div>
                                                @endforeach
                                            </div>
                                        </div>
                                    @endif
                                </td>
                            </tr>
                            @endif
                        @endforeach
                        </tbody>
                    </table>
                </div>
                @endif
            </div>

            <div class="card-footer d-flex align-items-center justify-content-between flex-wrap gap-2">
                <div class="text-muted" style="font-size:13px;">
                    <i class="bi bi-check2-square me-1"></i>Step 3 &mdash;
                    <span class="js-vnd-ready">{{ $importable === 1 ? '1 vendor' : $importable.' vendors' }}</span>
                    ready to import from this file.
                </div>
                <button id="vndImportSubmit" class="btn btn-primary btn-sm fw-semibold" {{ $importable ? '' : 'disabled' }}>
                    <i class="bi bi-download me-1"></i>Import the ticked vendors
                </button>
            </div>
        </div>
    </form>
</div>

@include('partials.confirm-modal')

<script nonce="{{ $cspNonce ?? '' }}">
// CSP: no inline handlers anywhere on this page — everything is bound here.
//
// The job of this block is to stop the page STATING something the submit would not do.
// The tick boxes and the duplicate-mode radio both change the outcome without reloading,
// so the row consequences and both running counts are recomputed from what is on screen; a
// server-rendered figure would go stale the moment either is touched.
//
// The count appears twice — in the sentence at the top and in the footer — so it is written
// through a CLASS rather than an id. Two elements claiming one id is invalid markup, and the
// second would silently stop updating while still showing a number.
document.addEventListener('DOMContentLoaded', function () {
    var all = document.getElementById('vndImportAll');
    var boxes = Array.prototype.slice.call(document.querySelectorAll('.js-vnd-import-row'));
    var readouts = Array.prototype.slice.call(document.querySelectorAll('.js-vnd-ready'));
    var submit = document.getElementById('vndImportSubmit');
    var modes = Array.prototype.slice.call(document.querySelectorAll('input[name="mode"]'));
    var notes = Array.prototype.slice.call(document.querySelectorAll('.js-vnd-dup-note'));

    function updating() {
        return modes.some(function (m) { return m.checked && m.value === 'update'; });
    }

    function refresh() {
        var willUpdate = updating();

        notes.forEach(function (note) {
            note.textContent = willUpdate ? 'Will be filled in' : 'Will be skipped';
        });

        var count = boxes.filter(function (box) {
            return box.checked && (box.dataset.action === 'create' || willUpdate);
        }).length;

        readouts.forEach(function (readout) {
            readout.textContent = count === 1 ? '1 vendor' : count + ' vendors';
        });

        if (submit) { submit.disabled = count === 0; }

        if (all) {
            var checked = boxes.filter(function (b) { return b.checked; }).length;
            all.checked = boxes.length > 0 && checked === boxes.length;
            all.indeterminate = checked > 0 && checked < boxes.length;
        }
    }

    if (all) {
        all.addEventListener('change', function () {
            boxes.forEach(function (box) { box.checked = all.checked; });
            refresh();
        });
    }

    boxes.forEach(function (box) { box.addEventListener('change', refresh); });
    modes.forEach(function (mode) { mode.addEventListener('change', refresh); });

    refresh();
});
</script>
@endsection
