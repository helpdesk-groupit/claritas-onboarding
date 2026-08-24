@php
    use Illuminate\Support\Facades\Storage;
    $imgData = function ($disk, $path) {
        try {
            if (! $path || ! Storage::disk($disk)->exists($path)) return null;
            $mime = Storage::disk($disk)->mimeType($path);
            if (! str_starts_with((string) $mime, 'image/')) return null;
            return 'data:'.$mime.';base64,'.base64_encode(Storage::disk($disk)->get($path));
        } catch (\Throwable $e) { return null; }
    };
    $logo = $company && $company->logo_path ? $imgData('public', $company->logo_path) : null;
    $deadlineDay = \App\Models\ExpenseClaimPolicy::forCompany($claim->resolvedCompany())->submission_deadline_day ?? 20;
    $appr = ($approver ?? null) ?? $claim->managerApprover ?? $claim->manager ?? null;
    $mgrDone = $claim->manager_approved_at && in_array($claim->status, ['manager_approved','hr_approved','paid']);
    $hrDone = $claim->hr_approved_at && in_array($claim->status, ['hr_approved','paid']);
    $apprDetails = collect([$appr?->designation, $appr?->department, $appr?->company])->filter()->implode(' · ');
    $hrAppr = $claim->hrApprover ?? null; $hrEmp = $hrAppr?->employee ?? null;
    $hrName = $hrEmp?->full_name ?? $hrAppr?->name;
    $hrDetails = collect([$hrEmp?->designation, $hrEmp?->department, $hrEmp?->company])->filter()->implode(' · ');
    $imageExt = ['jpg','jpeg','png','gif','webp'];
    // Resolved once by ClaimReportRenderer and keyed by storage path — tells this view
    // whether a non-image attachment (a PDF receipt) is being reproduced as real pages
    // after this form, or why not. Defaults to empty so the view still renders standalone
    // (e.g. rendered directly, without going through the renderer).
    $appendix = $appendix ?? [];
    // Group items sharing one attachment (same content hash) so a split statement embeds its
    // image ONCE with a summed total, instead of repeating the whole image on every row.
    $attGroups = [];
    foreach ($items as $gi) { $gk = $gi->receipt_hash ?: ('solo-'.$gi->id); $attGroups[$gk][] = $gi; }
    // Keep each group's rows contiguous (by date), groups ordered by earliest date, so a
    // statement's rows + its single attachment stay together (not interleaved by global date).
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
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    * { font-family: DejaVu Sans, sans-serif; }
    body { font-size: 10px; color: #1e293b; margin: 0; }
    .hdr { width: 100%; }
    .hdr td { vertical-align: top; }
    .co-name { font-weight: bold; font-size: 11px; }
    .co-addr { color: #555; font-size: 9px; }
    .title { text-align: center; font-size: 15px; font-weight: bold; letter-spacing: 1px; margin: 10px 0 6px; }
    .rules { color: #555; font-style: italic; font-size: 8.5px; line-height: 1.5; margin: 0 0 8px; }
    .meta td { padding: 2px 0; font-size: 10px; }
    .meta .lbl { font-weight: bold; width: 90px; }
    .meta .val { border-bottom: 1px solid #cbd5e1; }
    table.items { width: 100%; border-collapse: collapse; margin-top: 6px; font-size: 9px; }
    table.items th, table.items td { border: 1px solid #94a3b8; padding: 4px 5px; }
    table.items th { background: #f1f5f9; text-align: center; font-size: 8.5px; }
    .r { text-align: right; } .c { text-align: center; }
    .tot td { font-weight: bold; }
    .sign { width: 100%; margin-top: 22px; font-size: 9.5px; }
    .sign td { vertical-align: top; width: 50%; padding-right: 10px; }
    .ok { color: #15803d; } .muted { color: #94a3b8; font-style: italic; } .bad { color: #b91c1c; }
    .note { font-size: 8px; color: #94a3b8; font-style: italic; margin-top: 10px; }
    .att-title { font-size: 11px; font-weight: bold; margin: 0 0 6px; }
    .att { border: 1px solid #e2e8f0; padding: 6px; margin-bottom: 8px; page-break-inside: avoid; }
    .att .cap { font-size: 9px; font-weight: bold; margin-bottom: 4px; }
    .att img { max-width: 100%; max-height: 560px; }
</style>
</head>
<body>
    <table class="hdr">
        <tr>
            <td>
                <div class="co-name">{{ $company->name ?? ($claim->resolvedCompany() ?? 'Company') }}</div>
                @if($company && $company->address)<div class="co-addr">{{ $company->address }}</div>@endif
            </td>
            <td style="text-align:right; width:160px;">
                @if($logo)<img src="{{ $logo }}" style="max-height:46px; max-width:150px;">@endif
            </td>
        </tr>
    </table>

    <div class="title">EXPENSES CLAIMS FORM</div>
    <div class="rules">
        - All supporting documents should be submitted with form for approval.<br>
        - Your supporting documents should be arrange and attached accordingly on an A4 paper.<br>
        - All claims submission should be signed (incl. yourself) and approved by your Reporting Manager prior to submitting to HR.<br>
        - Submission of claims to be submitted to HR/Finance by the {{ $deadlineDay }}th of the month for processing. Any late submission will be process next month.
    </div>

    <table class="meta">
        <tr><td class="lbl">Name :</td><td class="val">{{ $claim->employee->full_name }}</td><td style="width:60px;"></td><td class="lbl" style="width:50px;">Date :</td><td class="val">{{ ($claim->submitted_at ?? \Carbon\Carbon::create($claim->year, $claim->month, 1))->format('jS F Y') }}</td></tr>
        <tr><td class="lbl">Department :</td><td class="val">{{ $claim->employee->department ?? '—' }}</td><td></td><td></td><td></td></tr>
        <tr><td class="lbl">Event :</td><td class="val">{{ $claim->event ?: '—' }}</td><td></td><td></td><td></td></tr>
    </table>

    <table class="items">
        <thead>
            <tr>
                <th>Date</th><th>Expense Description</th><th>Project/Client</th><th>Expense Type</th>
                <th>RM<br>(w/o SST)</th><th>RM<br>(SST)</th><th>Total<br>(w/ SST)</th>
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
                <td class="c">{{ $item->expense_date->format('d/m/Y') }}</td>
                <td>{{ $item->description }}</td>
                <td>{{ $item->project_client ?: 'N/A' }}</td>
                <td>{{ $item->category->gl_code ? $item->category->gl_code.': ' : '' }}{{ strtoupper($item->category->name ?? '') }}</td>
                <td class="r">RM{{ number_format($item->amount, 2) }}</td>
                <td class="r">{{ $item->gst_amount > 0 ? 'RM'.number_format($item->gst_amount, 2) : '-' }}</td>
                <td class="r">RM{{ number_format($item->total_with_gst, 2) }}</td>
            </tr>
            @if($shared)
                {{-- Shared statement → embed the image ONCE with a summed total, after the last row. --}}
                @if($groupLastId[$gk] === $item->id)
                @php
                    $g0 = $grp[0]; $gAtts = $g0->attachmentPaths(); $gOc = $g0->ocr_details;
                    $gCount = count($grp); $gSum = collect($grp)->sum('total_with_gst');
                    $gAmt = collect($grp)->sum('amount'); $gGst = collect($grp)->sum('gst_amount');
                    $gDatesC = collect($grp)->map(fn ($x) => $x->expense_date)->filter();
                    $gMin = $gDatesC->min(); $gMax = $gDatesC->max();
                    $gDateLabel = ($gMin && $gMax && $gMin->format('Y-m-d') !== $gMax->format('Y-m-d')) ? $gMin->format('j M Y').' – '.$gMax->format('j M Y') : ($gMin?->format('j M Y') ?? '—');
                @endphp
                {{-- Subtotal for this bulk attachment (sum of its rows). --}}
                <tr style="background:#f1f5f9;font-weight:bold;">
                    <td colspan="4" class="r">Subtotal — {{ $gCount }} transactions</td>
                    <td class="r">RM{{ number_format($gAmt, 2) }}</td>
                    <td class="r">{{ $gGst > 0 ? 'RM'.number_format($gGst, 2) : '-' }}</td>
                    <td class="r">RM{{ number_format($gSum, 2) }}</td>
                </tr>
                <tr><td colspan="7" style="background:#fafafa;padding:6px 8px;">
                    <table style="width:100%;border:0;border-collapse:collapse;"><tr style="border:0;">
                        <td style="width:62%;vertical-align:top;border:0;padding:0 8px 0 0;">
                            @if(count($gAtts) > 0)
                            <div class="muted" style="margin-bottom:3px;">Attachment for {{ $gCount }} transactions ({{ strtoupper($item->category->name ?? '') }})</div>
                            @foreach($gAtts as $attachment)
                            @php $aext = strtolower(pathinfo($attachment, PATHINFO_EXTENSION)); $adata = in_array($aext, $imageExt) ? $imgData('local', $attachment) : null; $ax = $appendix[$attachment] ?? null; @endphp
                            @if($adata)<img src="{{ $adata }}" style="max-width:100%;max-height:480px;display:block;margin:3px 0;">@else<div class="muted">Attachment: {{ strtoupper($aext) }} file (not embeddable in this PDF)@if($ax && $ax['appendable']) — reproduced in full on the pages after this form@elseif($ax && $ax['reason']) ({{ $ax['reason'] }})@endif.</div>@endif
                            @endforeach
                            @else<div class="muted">No attachment.</div>@endif
                            @php $gSupp = collect($grp)->flatMap(fn ($x) => $x->supportingPaths())->unique()->values(); @endphp
                            @if($gSupp->count() > 0)
                            <div class="muted" style="margin:6px 0 3px;">Supporting documents</div>
                            @foreach($gSupp as $sp)
                            @php $sext = strtolower(pathinfo($sp, PATHINFO_EXTENSION)); $sdata = in_array($sext, $imageExt) ? $imgData('local', $sp) : null; $sx = $appendix[$sp] ?? null; @endphp
                            @if($sdata)<img src="{{ $sdata }}" style="max-width:100%;max-height:300px;display:block;margin:3px 0;">@else<div class="muted">Supporting: {{ strtoupper($sext) }} file (not embeddable)@if($sx && $sx['appendable']) — reproduced in full on the pages after this form@elseif($sx && $sx['reason']) ({{ $sx['reason'] }})@endif.</div>@endif
                            @endforeach
                            @endif
                        </td>
                        <td style="width:38%;vertical-align:top;border:0;padding:0;">
                            <div style="font-weight:bold;text-transform:uppercase;margin-bottom:3px;color:#475569;">Receipt details</div>
                            <div><strong>Company:</strong> {{ $gOc['company'] ?? '—' }}</div>
                            <div><strong>Item:</strong> {{ $gCount }} transactions
                                @foreach($grp as $gItem)
                                <div style="margin-left:8px;">• {{ $gItem->ocr_details['item_description'] ?? $gItem->description }} (RM{{ number_format($gItem->amount, 2) }})</div>
                                @endforeach
                            </div>
                            <div><strong>Date:</strong> {{ $gDateLabel }}</div>
                            <div><strong>Who paid:</strong> {{ $gOc['paid_by'] ?? '—' }}</div>
                            <div><strong>Total paid:</strong> RM {{ number_format($gSum, 2) }} (sum of {{ $gCount }})</div>
                        </td>
                    </tr></table>
                </td></tr>
                @endif
            @else
                {{-- Solo item → its own attachment + receipt details below it (unchanged). --}}
                @php $atts = $item->attachmentPaths(); $supp = $item->supportingPaths(); @endphp
                @if(count($atts) > 0 || ! empty($oc) || count($supp) > 0)
                <tr><td colspan="7" style="background:#fafafa;padding:6px 8px;">
                    <table style="width:100%;border:0;border-collapse:collapse;"><tr style="border:0;">
                        <td style="width:62%;vertical-align:top;border:0;padding:0 8px 0 0;">
                            @if(count($atts) > 0)
                            <div class="muted" style="margin-bottom:3px;">Attachment for: {{ $item->description }}</div>
                            @foreach($atts as $attachment)
                            @php $aext = strtolower(pathinfo($attachment, PATHINFO_EXTENSION)); $adata = in_array($aext, $imageExt) ? $imgData('local', $attachment) : null; $ax = $appendix[$attachment] ?? null; @endphp
                            @if($adata)<img src="{{ $adata }}" style="max-width:100%;max-height:420px;display:block;margin:3px 0;">@else<div class="muted">Attachment: {{ strtoupper($aext) }} file (not embeddable in this PDF)@if($ax && $ax['appendable']) — reproduced in full on the pages after this form@elseif($ax && $ax['reason']) ({{ $ax['reason'] }})@endif.</div>@endif
                            @endforeach
                            @else
                            <div class="muted">No attachment.</div>
                            @endif
                            @if(count($supp) > 0)
                            <div class="muted" style="margin:6px 0 3px;">Supporting documents</div>
                            @foreach($supp as $sp)
                            @php $sext = strtolower(pathinfo($sp, PATHINFO_EXTENSION)); $sdata = in_array($sext, $imageExt) ? $imgData('local', $sp) : null; $sx = $appendix[$sp] ?? null; @endphp
                            @if($sdata)<img src="{{ $sdata }}" style="max-width:100%;max-height:300px;display:block;margin:3px 0;">@else<div class="muted">Supporting: {{ strtoupper($sext) }} file (not embeddable)@if($sx && $sx['appendable']) — reproduced in full on the pages after this form@elseif($sx && $sx['reason']) ({{ $sx['reason'] }})@endif.</div>@endif
                            @endforeach
                            @endif
                        </td>
                        <td style="width:38%;vertical-align:top;border:0;padding:0;">
                            @if(! empty($oc))
                            <div style="font-weight:bold;text-transform:uppercase;margin-bottom:3px;color:#475569;">Receipt details</div>
                            <div><strong>Company:</strong> {{ $oc['company'] ?? '—' }}</div>
                            <div><strong>Item:</strong> {{ $oc['item_description'] ?? '—' }}</div>
                            <div><strong>Date:</strong> {{ $oc['date'] ?? '—' }}</div>
                            {{-- Printed only when the receipt states a period it pays for — it is
                                 the whole explanation for a receipt dated outside the claim month
                                 (a season pass paid in advance), and this PDF is the copy of record
                                 the approver signs, so it has to carry that explanation too. --}}
                            @if(! empty($oc['period_start']) && ! empty($oc['period_end']))
                            <div><strong>Covers:</strong> {{ fmt_date($oc['period_start']) }} - {{ fmt_date($oc['period_end']) }}@if(($oc['period_source'] ?? null) === 'manual') (entered by hand)@endif</div>
                            @endif
                            <div><strong>Who paid:</strong> {{ $oc['paid_by'] ?? '—' }}</div>
                            <div><strong>Total paid:</strong> {{ isset($oc['total']) && $oc['total'] !== '' ? 'RM '.number_format((float) $oc['total'], 2) : '—' }}</div>
                            @if(! empty($oc['calculation']))<div style="margin-top:2px;"><strong>Calculation:</strong> {{ $oc['calculation'] }}</div>@endif
                            @endif
                        </td>
                    </tr></table>
                </td></tr>
                @endif
            @endif
            @endforeach
        </tbody>
        <tfoot>
            <tr class="tot"><td colspan="4" class="r">GRAND TOTAL</td>
                <td class="r">{{ number_format($items->sum('amount'), 2) }}</td>
                <td class="r">{{ number_format($items->sum('gst_amount'), 2) }}</td>
                <td class="r">{{ number_format($items->sum('total_with_gst'), 2) }}</td>
            </tr>
        </tfoot>
    </table>

    {{-- Digital sign-offs --}}
    <table class="sign">
        <tr>
            <td>
                <div><strong>Approving Manager :-</strong> {{ $appr->full_name ?? '—' }}</div>
                @if($apprDetails)<div class="muted">{{ $apprDetails }}</div>@endif
                <div>@if($mgrDone)<span class="ok">Approved digitally — {{ $claim->manager_approved_at->format('d/m/Y, g:ia') }}</span>@elseif($claim->status==='manager_rejected')<span class="bad">Returned to staff</span>@else<span class="muted">Awaiting approval</span>@endif</div>
            </td>
            <td>
                <div><strong>Checked by :-</strong> {{ $hrDone ? ($hrName ?? '(HR)') : '(HR)' }}</div>
                @if($hrDone && $hrDetails)<div class="muted">{{ $hrDetails }}</div>@endif
                <div>@if($hrDone)<span class="ok">Approved digitally — {{ $claim->hr_approved_at->format('d/m/Y, g:ia') }}</span>@elseif($claim->status==='hr_rejected')<span class="bad">Rejected</span>@else<span class="muted">Pending HR</span>@endif</div>
            </td>
        </tr>
    </table>
    <div class="note">Digitally approved — each sign-off is the recorded system action (name + timestamp), held in the claim's audit trail. No physical signature required.</div>
    {{-- Image attachments are shown inline under each item above. Non-image (PDF) attachments
         are reproduced as real pages after this form by ClaimReportRenderer's post-dompdf
         merge (see app/Services/ClaimReportRenderer.php) — nothing in this Blade file adds them. --}}
</body>
</html>
