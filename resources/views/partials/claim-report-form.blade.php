{{--
    Shared "Expenses Claims Form" report body — letterhead + itemised table + digital
    sign-offs. Used by the printable report (user.claims.report-print) AND the HR claim
    detail page so HR sees the claim AS a report rather than a bare item list (#11).

    Vars:
      $claim    — App\Models\ExpenseClaim
      $company  — App\Models\Company|null (resolved via Company::forName)
      $items    — Collection<ExpenseClaimItem> (already scoped, e.g. per-approver)
      $approver — Employee|null (the routed manager, for the sign-off block)
      $padRows  — bool (default true): pad the table to a fixed row count to mimic the
                  paper form; pass false for a tidy on-screen list.
--}}
@php
    $padRows = $padRows ?? true;
    $imageExt = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $showAtt = $showAttachments ?? true;
    // Group items that share ONE uploaded attachment (same content hash = the same statement
    // image split into several rows). A group of >1 shows the attachment ONCE with a summed
    // "Total paid"; a solo item keeps its own attachment + receipt-details block under it.
    $attGroups = [];
    foreach ($items as $gi) {
        $gk = $gi->receipt_hash ?: ('solo-'.$gi->id);
        $attGroups[$gk][] = $gi;
    }
    // Keep each group's rows CONTIGUOUS (sorted by date), with groups ordered by their
    // earliest date — so a statement's rows + its single attachment stay together instead of
    // being interleaved with unrelated items (e.g. a medical receipt) by global date.
    $orderedGroups = collect($attGroups)
        ->map(fn ($g) => collect($g)->sortBy(fn ($x) => $x->expense_date?->timestamp ?? 0)->values())
        ->sortBy(fn ($g) => $g->first()->expense_date?->timestamp ?? 0)
        ->values();
    $orderedItems = $orderedGroups->flatMap(fn ($g) => $g)->values();
    $groupLastId = [];
    foreach ($orderedGroups as $g) {
        $gk = $g->first()->receipt_hash ?: ('solo-'.$g->first()->id);
        $groupLastId[$gk] = $g->last()->id;
    }
@endphp
@include('partials.claim-letterhead', [
    'company' => $company,
    'employee' => $claim->employee,
    'event' => $claim->event,
    'showRules' => $showRules ?? true,
    'claimDate' => $claim->submitted_at ?? \Carbon\Carbon::create($claim->year, $claim->month, 1),
])

<table class="table table-bordered align-middle" style="font-size:.78rem;">
    <thead class="text-center">
        <tr>
            <th>Date</th>
            <th>Expense Description</th>
            <th>Project/Client Name</th>
            <th>Expense Type</th>
            <th>RM<br>(w/o SST)</th>
            <th>RM<br>(SST)</th>
            <th>Total<br>(w/ SST)</th>
        </tr>
    </thead>
    <tbody>
        @foreach($orderedItems as $item)
        @php
            $gk = $item->receipt_hash ?: ('solo-'.$item->id);
            $grp = $attGroups[$gk];
            $shared = count($grp) > 1;
            $oc = $item->ocr_details;
        @endphp
        <tr>
            <td class="text-nowrap">{{ $item->expense_date->format('jS M Y') }}</td>
            <td>{{ $item->description }}@if($item->isRejected()) <span class="badge bg-danger">REJECTED</span>@endif</td>
            <td>{{ $item->project_client ?: 'N/A' }}</td>
            <td>{{ $item->category->gl_code ? $item->category->gl_code.': ' : '' }}{{ strtoupper($item->category->name ?? '') }}</td>
            <td class="text-end">RM{{ number_format($item->amount, 2) }}</td>
            <td class="text-end">{{ $item->gst_amount > 0 ? 'RM'.number_format($item->gst_amount, 2) : '-' }}</td>
            <td class="text-end">RM{{ number_format($item->total_with_gst, 2) }}</td>
        </tr>
        @if($reviewReject ?? false)
        {{-- Reviewer flags/comments right on the report (only while rejecting). Non-empty = flagged. --}}
        <tr class="review-flag-row d-print-none">
            <td></td>
            <td colspan="6" style="background:#fff7ed;">
                <div class="d-flex align-items-center gap-2">
                    <i class="bi bi-flag text-danger"></i>
                    <input type="text" class="form-control form-control-sm report-item-comment" data-item-id="{{ $item->id }}"
                           maxlength="1000" placeholder="Comment if this item needs fixing — the employee sees this (optional)">
                </div>
            </td>
        </tr>
        @endif
        @if($item->reject_comment)
        {{-- Reviewer's flag/comment on this specific item (shown to the employee). --}}
        <tr>
            <td></td>
            <td colspan="6" style="background:#fff7ed;color:#9a3412;font-size:.8rem;padding:6px 8px;">
                <i class="bi bi-flag-fill me-1"></i><strong>Flagged:</strong> {{ $item->reject_comment }}
            </td>
        </tr>
        @endif
        @if($showAtt && $shared)
            {{-- Shared statement → ONE attachment + a summed Total paid, after the group's last row. --}}
            @if($groupLastId[$gk] === $item->id)
            @php
                $g0 = $grp[0];
                $gAtts = $g0->attachmentPaths();
                $gOc = $g0->ocr_details;
                $gCount = count($grp);
                $gAmt = collect($grp)->sum('amount');
                $gGst = collect($grp)->sum('gst_amount');
                $gSum = collect($grp)->sum('total_with_gst');
                $gDatesC = collect($grp)->map(fn ($x) => $x->expense_date)->filter();
                $gMin = $gDatesC->min();
                $gMax = $gDatesC->max();
                $gDateLabel = ($gMin && $gMax && $gMin->format('Y-m-d') !== $gMax->format('Y-m-d'))
                    ? $gMin->format('j M Y').' – '.$gMax->format('j M Y')
                    : ($gMin?->format('j M Y') ?? '—');
            @endphp
            {{-- Subtotal for this bulk attachment (sum of its rows). --}}
            <tr class="fw-semibold" style="background:#f1f5f9;">
                <td colspan="4" class="text-end">Subtotal — {{ $gCount }} transactions</td>
                <td class="text-end">RM{{ number_format($gAmt, 2) }}</td>
                <td class="text-end">{{ $gGst > 0 ? 'RM'.number_format($gGst, 2) : '-' }}</td>
                <td class="text-end">RM{{ number_format($gSum, 2) }}</td>
            </tr>
            <tr class="attachment-row">
                <td colspan="7" style="background:#fafafa;page-break-inside:avoid;padding:8px 12px;">
                    <table style="width:100%;border:0;border-collapse:collapse;"><tr style="border:0;">
                        <td style="width:62%;vertical-align:top;border:0;padding:0 12px 0 0;">
                            @if(count($gAtts) > 0)
                            <div class="small text-muted mb-1"><i class="bi bi-paperclip me-1"></i>Attachment for {{ $gCount }} transactions ({{ strtoupper($item->category->name ?? '') }})</div>
                            @foreach($gAtts as $att)
                            @php $ext = strtolower(pathinfo($att, PATHINFO_EXTENSION)); @endphp
                            @if(! \Illuminate\Support\Facades\Storage::disk('local')->exists($att))
                            <div class="small text-danger mb-1"><i class="bi bi-exclamation-triangle me-1"></i>Attachment file is missing — it may have been moved or deleted on the server.</div>
                            @elseif(in_array($ext, $imageExt))
                            <img src="{{ route('secure.file', $att) }}" alt="Attachment" style="max-width:100%;max-height:520px;display:block;margin:0 0 6px;border:1px solid #e2e8f0;">
                            @elseif($ext === 'pdf')
                            {{--
                                On screen the browser's own viewer shows this PDF perfectly, so
                                the iframe stays. The marker is for the DOWNLOAD: dompdf cannot
                                embed a PDF, so report-pdf.blade.php prints a sentence where
                                every other line shows a picture. The nonce'd block at the foot
                                of this file rasterises the pages and posts them back, and only
                                for a PDF that has none yet — see App\Support\ClaimPdfPreview.
                            --}}
                            <iframe src="{{ route('secure.file', $att) }}" title="Attachment PDF" style="width:100%;height:600px;border:1px solid #e2e8f0;display:block;margin:0 0 6px;" class="d-print-none" loading="lazy"
                                @if(config('claims.pdf_preview.enabled', true) && count(\App\Support\ClaimPdfPreview::existing($att)) === 0) data-claim-pdf-path="{{ $att }}" data-claim-pdf-src="{{ route('secure.file', $att) }}" @endif></iframe>
                            @else
                            <div class="mb-1"><i class="bi bi-file-earmark text-secondary me-1"></i><a href="{{ route('secure.file', $att) }}" target="_blank">Open attachment ({{ strtoupper($ext) }})</a></div>
                            @endif
                            @endforeach
                            @else
                            <div class="small text-muted">No attachment.</div>
                            @endif
                            @php $gSupp = collect($grp)->flatMap(fn ($x) => $x->supportingPaths())->unique()->values(); @endphp
                            @if($gSupp->count() > 0)
                            <div class="small text-muted mt-2 mb-1"><i class="bi bi-folder me-1"></i>Supporting documents</div>
                            @foreach($gSupp as $sp)
                            @php $sext = strtolower(pathinfo($sp, PATHINFO_EXTENSION)); @endphp
                            @if(in_array($sext, $imageExt))
                            <img src="{{ route('secure.file', $sp) }}" alt="Supporting document" style="max-width:100%;max-height:320px;display:block;margin:0 0 6px;border:1px solid #e2e8f0;">
                            @else
                            <div class="mb-1"><i class="bi bi-file-earmark text-secondary me-1"></i><a href="{{ route('secure.file', $sp) }}" target="_blank">Open supporting document ({{ strtoupper($sext) }})</a></div>
                            @endif
                            @endforeach
                            @endif
                        </td>
                        <td style="width:38%;vertical-align:top;border:0;padding:0;">
                            <div class="small">
                                <div class="fw-semibold mb-1" style="text-transform:uppercase;letter-spacing:.04em;color:#475569;">Receipt details</div>
                                <div style="margin-bottom:2px;"><strong>Company:</strong> {{ $gOc['company'] ?? '—' }}</div>
                                <div style="margin-bottom:2px;"><strong>Item:</strong> {{ $gCount }} transactions
                                    @foreach($grp as $gItem)
                                    <div class="ms-2">• {{ $gItem->ocr_details['item_description'] ?? $gItem->description }} <span class="text-muted">(RM{{ number_format($gItem->amount, 2) }})</span></div>
                                    @endforeach
                                </div>
                                <div style="margin-bottom:2px;"><strong>Date:</strong> {{ $gDateLabel }}</div>
                                <div style="margin-bottom:2px;"><strong>Who paid:</strong> {{ $gOc['paid_by'] ?? '—' }}</div>
                                <div><strong>Total paid:</strong> RM {{ number_format($gSum, 2) }} <span class="text-muted">(sum of {{ $gCount }})</span></div>
                            </div>
                        </td>
                    </tr></table>
                </td>
            </tr>
            @endif
        @elseif($showAtt)
            {{-- Solo item → its own attachment + receipt details below it (unchanged). --}}
            @php $atts = $item->attachmentPaths(); $supp = $item->supportingPaths(); @endphp
            @if(count($atts) > 0 || ! empty($oc) || count($supp) > 0)
            <tr class="attachment-row">
                <td colspan="7" style="background:#fafafa;page-break-inside:avoid;padding:8px 12px;">
                    <table style="width:100%;border:0;border-collapse:collapse;"><tr style="border:0;">
                        <td style="width:62%;vertical-align:top;border:0;padding:0 12px 0 0;">
                            @if(count($atts) > 0)
                            <div class="small text-muted mb-1"><i class="bi bi-paperclip me-1"></i>Attachment for: {{ $item->description }}</div>
                            @foreach($atts as $att)
                            @php $ext = strtolower(pathinfo($att, PATHINFO_EXTENSION)); @endphp
                            @if(! \Illuminate\Support\Facades\Storage::disk('local')->exists($att))
                            <div class="small text-danger mb-1"><i class="bi bi-exclamation-triangle me-1"></i>Attachment file is missing — it may have been moved or deleted on the server.</div>
                            @elseif(in_array($ext, $imageExt))
                            <img src="{{ route('secure.file', $att) }}" alt="Attachment" style="max-width:100%;max-height:460px;display:block;margin:0 0 6px;border:1px solid #e2e8f0;">
                            @elseif($ext === 'pdf')
                            {{--
                                On screen the browser's own viewer shows this PDF perfectly, so
                                the iframe stays. The marker is for the DOWNLOAD: dompdf cannot
                                embed a PDF, so report-pdf.blade.php prints a sentence where
                                every other line shows a picture. The nonce'd block at the foot
                                of this file rasterises the pages and posts them back, and only
                                for a PDF that has none yet — see App\Support\ClaimPdfPreview.
                            --}}
                            <iframe src="{{ route('secure.file', $att) }}" title="Attachment PDF" style="width:100%;height:600px;border:1px solid #e2e8f0;display:block;margin:0 0 6px;" class="d-print-none" loading="lazy"
                                @if(config('claims.pdf_preview.enabled', true) && count(\App\Support\ClaimPdfPreview::existing($att)) === 0) data-claim-pdf-path="{{ $att }}" data-claim-pdf-src="{{ route('secure.file', $att) }}" @endif></iframe>
                            @else
                            <div class="mb-1"><i class="bi bi-file-earmark text-secondary me-1"></i><a href="{{ route('secure.file', $att) }}" target="_blank">Open attachment ({{ strtoupper($ext) }})</a></div>
                            @endif
                            @endforeach
                            @else
                            <div class="small text-muted">No attachment.</div>
                            @endif
                            @if(count($supp) > 0)
                            <div class="small text-muted mt-2 mb-1"><i class="bi bi-folder me-1"></i>Supporting documents</div>
                            @foreach($supp as $sp)
                            @php $sext = strtolower(pathinfo($sp, PATHINFO_EXTENSION)); @endphp
                            @if(in_array($sext, $imageExt))
                            <img src="{{ route('secure.file', $sp) }}" alt="Supporting document" style="max-width:100%;max-height:320px;display:block;margin:0 0 6px;border:1px solid #e2e8f0;">
                            @else
                            <div class="mb-1"><i class="bi bi-file-earmark text-secondary me-1"></i><a href="{{ route('secure.file', $sp) }}" target="_blank">Open supporting document ({{ strtoupper($sext) }})</a></div>
                            @endif
                            @endforeach
                            @endif
                        </td>
                        <td style="width:38%;vertical-align:top;border:0;padding:0;">
                            @if(! empty($oc))
                            <div class="small">
                                <div class="fw-semibold mb-1" style="text-transform:uppercase;letter-spacing:.04em;color:#475569;">Receipt details</div>
                                <div style="margin-bottom:2px;"><strong>Company:</strong> {{ $oc['company'] ?? '—' }}</div>
                                <div style="margin-bottom:2px;"><strong>Item:</strong> {{ $oc['item_description'] ?? '—' }}</div>
                                <div style="margin-bottom:2px;"><strong>Date:</strong> {{ $oc['date'] ?? '—' }}</div>
                                {{-- The period the receipt says it pays for. Printed ONLY when the
                                     receipt states one — it is the whole explanation for a receipt
                                     dated outside the claim month (a season pass paid in advance),
                                     so the approver has to be able to see it against the image. --}}
                                @if(! empty($oc['period_start']) && ! empty($oc['period_end']))
                                <div style="margin-bottom:2px;"><strong>Covers:</strong> {{ fmt_date($oc['period_start']) }} – {{ fmt_date($oc['period_end']) }}
                                    @if(($oc['period_source'] ?? null) === 'manual')
                                    <span class="text-warning-emphasis">(entered by hand)</span>
                                    @endif
                                </div>
                                @endif
                                <div style="margin-bottom:2px;"><strong>Who paid:</strong> {{ $oc['paid_by'] ?? '—' }}</div>
                                <div><strong>Total paid:</strong> {{ isset($oc['total']) && $oc['total'] !== '' ? 'RM '.number_format((float) $oc['total'], 2) : '—' }}</div>
                                @if(! empty($oc['calculation']))
                                <div style="margin-top:3px;"><strong>Calculation:</strong> {{ $oc['calculation'] }}</div>
                                @endif
                            </div>
                            @endif
                        </td>
                    </tr></table>
                </td>
            </tr>
            @endif
        @endif
        @endforeach
        @if($padRows)
            @for($i = $items->count(); $i < 14; $i++)
            <tr><td>&nbsp;</td><td></td><td></td><td></td><td></td><td></td><td class="text-end">-</td></tr>
            @endfor
        @endif
    </tbody>
    <tfoot>
        <tr class="fw-bold text-end" style="background:#e2e8f0;">
            <td colspan="4">Grand Total</td>
            <td>{{ number_format($items->sum('amount'), 2) }}</td>
            <td>{{ number_format($items->sum('gst_amount'), 2) }}</td>
            <td>{{ number_format($items->sum('total_with_gst'), 2) }}</td>
        </tr>
    </tfoot>
</table>

@include('partials.claim-signoffs', ['claim' => $claim, 'approver' => $approver])

{{-- Status log — visible in the system only; hidden when the report is printed. --}}
@php $statusLogs = $claim->logs()->orderByDesc('created_at')->get(); @endphp
@if($statusLogs->count() > 0)
<div class="d-print-none mt-4 pt-3 border-top">
    <h6 class="fw-semibold mb-2"><i class="bi bi-clock-history me-1 text-muted"></i>Status log <span class="text-muted small fw-normal">(system only — not included when printed)</span></h6>
    <ul class="list-unstyled small mb-0">
        @foreach($statusLogs as $log)
        <li class="mb-1 d-flex align-items-start gap-2">
            <span class="badge bg-{{ $log->badgeClass() }} flex-shrink-0">{{ $log->label() }}</span>
            <span>
                <span class="text-muted">{{ $log->created_at?->format('d/m/Y H:i') }}</span>@if($log->detail) — {{ $log->detail }}@endif
                @if($log->actor_name)<span class="text-muted">({{ $log->actor_name }})</span>@endif
            </span>
        </li>
        @endforeach
    </ul>
</div>
@endif

@once
@if(config('claims.pdf_preview.enabled', true))
{{--
    Rasterises PDF attachments so the DOWNLOADED report can show them inline.

    Why the browser: this host has no Imagick, Ghostscript or Poppler (confirmed on the live
    NAS), so nothing server-side can turn a PDF into an image — the same limitation that makes
    ClaimReceiptOcrService hand Anthropic a native `document` block. pdf.js is already vendored
    same-origin for the receipt scanner in user/claims/index.blade.php; this reuses the same two
    files and the same load pattern, so CSP needs no change (script-src 'self' covers both the
    script and its worker) and nothing new is downloaded from a CDN.

    Why here rather than at upload: this runs off the STORED file, so it needs nothing from the
    four upload paths in ExpenseClaimController and it backfills the PDF receipts already in
    production the first time somebody opens their claim. (The scan flow in
    user/claims/index.blade.php already rasterises a PDF for OCR and discards the images —
    capturing them there too would save this round trip for newly scanned receipts, but it
    would do nothing for anything already filed.)

    Pure progressive enhancement: if it never runs, the report reads exactly as it does today
    and the appended full-fidelity pages — which are the actual record — are untouched.
--}}
<script nonce="{{ $cspNonce ?? '' }}">
(function () {
    var targets = Array.prototype.slice.call(document.querySelectorAll('[data-claim-pdf-path]'));
    var meta = document.querySelector('meta[name="csrf-token"]');
    if (!targets.length || !meta) { return; }

    var ENDPOINT  = @json(route('user.claims.receipt-preview'));
    var MAX_PAGES = {{ (int) \App\Support\ClaimPdfPreview::maxPages() }};
    var MAX_EDGE  = 1400;
    var token     = meta.getAttribute('content');
    var pdfjs     = null;

    function loadPdfJs() {
        if (window.pdfjsLib) { return Promise.resolve(window.pdfjsLib); }
        if (pdfjs) { return pdfjs; }
        pdfjs = new Promise(function (resolve, reject) {
            var s = document.createElement('script');
            s.src = @json(asset('vendor/pdfjs/pdf.min.js'));
            s.onload = function () {
                if (!window.pdfjsLib) { return reject(new Error('pdfjsLib missing')); }
                window.pdfjsLib.GlobalWorkerOptions.workerSrc = @json(asset('vendor/pdfjs/pdf.worker.min.js'));
                resolve(window.pdfjsLib);
            };
            s.onerror = function () { reject(new Error('pdf.js load failed')); };
            document.head.appendChild(s);
        });
        return pdfjs;
    }

    function renderPage(pdf, n) {
        return pdf.getPage(n).then(function (page) {
            var base = page.getViewport({ scale: 1 });
            var scale = Math.min(MAX_EDGE / Math.max(base.width, base.height), 2);
            var viewport = page.getViewport({ scale: scale > 0 ? scale : 1 });
            var canvas = document.createElement('canvas');
            canvas.width = Math.max(1, Math.floor(viewport.width));
            canvas.height = Math.max(1, Math.floor(viewport.height));
            var ctx = canvas.getContext('2d');
            // A PDF page is transparent where blank; without this it rasterises to black.
            ctx.fillStyle = '#ffffff';
            ctx.fillRect(0, 0, canvas.width, canvas.height);
            return page.render({ canvasContext: ctx, viewport: viewport }).promise.then(function () {
                return new Promise(function (resolve) {
                    canvas.toBlob(function (blob) {
                        canvas.width = 0; canvas.height = 0; // release the backing store
                        resolve(blob);
                    }, 'image/jpeg', 0.72);
                });
            });
        });
    }

    function postPage(path, n, blob) {
        var body = new FormData();
        body.append('path', path);
        body.append('page', String(n));
        body.append('image', blob, 'page.jpg');
        body.append('_token', token);
        return fetch(ENDPOINT, {
            method: 'POST', body: body, credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (r) { return r.ok; });
    }

    function handle(el) {
        var path = el.getAttribute('data-claim-pdf-path');
        var src  = el.getAttribute('data-claim-pdf-src');
        el.removeAttribute('data-claim-pdf-path'); // claim it once
        if (!path || !src) { return Promise.resolve(); }

        return loadPdfJs().then(function (lib) {
            // A password-protected receipt is left alone rather than prompting: this is a
            // background courtesy on somebody else's claim, and the scan flow already has the
            // one place that may reasonably ask for a password.
            var task = lib.getDocument({ url: src, withCredentials: true });
            task.onPassword = function () { try { task.destroy(); } catch (e) {} };
            return task.promise;
        }).then(function (pdf) {
            var pages = Math.min(pdf.numPages, MAX_PAGES);
            var chain = Promise.resolve();
            for (var n = 1; n <= pages; n++) {
                (function (page) {
                    chain = chain.then(function (stop) {
                        if (stop) { return true; }
                        return renderPage(pdf, page).then(function (blob) {
                            if (!blob) { return true; }
                            // Stop at the first refusal: the server reads previews up to the
                            // first gap, so a hole would misrepresent which pages these are.
                            return postPage(path, page, blob).then(function (ok) { return !ok; });
                        });
                    });
                })(n);
            }
            return chain.then(function () { try { pdf.destroy(); } catch (e) {} });
        }).catch(function (e) {
            // Swallowed on purpose. A receipt that will not rasterise is not a broken claim —
            // the PDF is still attached, still downloadable, and still reproduced in full in
            // the report. Saying "failed" here would be false and alarming.
            if (window.console && console.debug) { console.debug('claim pdf preview skipped', e); }
        });
    }

    // One at a time, after the page has settled: a claim here can carry seven 1.4 MB receipts,
    // and this must never compete with what the reviewer is trying to read.
    function run() {
        targets.reduce(function (chain, el) {
            return chain.then(function () { return handle(el); });
        }, Promise.resolve());
    }
    if ('requestIdleCallback' in window) { requestIdleCallback(run, { timeout: 4000 }); }
    else { setTimeout(run, 1500); }
})();
</script>
@endif
@endonce
