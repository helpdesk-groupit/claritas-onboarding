<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex,nofollow">
    <title>Claim Form — {{ $claim->claim_number }}</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

    <style nonce="{{ $cspNonce ?? '' }}">
        body { background: #f1f5f9; font-family: 'Segoe UI', sans-serif; color: #1e293b; }
        .claim-toolbar { max-width: 900px; margin: 18px auto 0; display: flex; gap: 8px; align-items: center; }
        .claim-form { max-width: 900px; margin: 16px auto; background: #fff; padding: 26px 30px; box-shadow: 0 1px 4px rgba(0,0,0,.08); font-size: .85rem; }
        .attachments { max-width: 900px; margin: 16px auto; }
        .attachment-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px 16px; margin-bottom: 14px; page-break-inside: avoid; }
        .attachment-card img { max-width: 100%; max-height: 640px; display: block; margin: 8px auto 0; border: 1px solid #e2e8f0; }

        @media print {
            body { background: #fff !important; }
            .d-print-none { display: none !important; }
            .claim-form, .attachments { box-shadow: none !important; max-width: 100% !important; margin: 0 !important; padding: 0 !important; }
            .attachment-card { border: none; padding: 0; }
            .attachments { page-break-before: always; }
            @page { margin: 12mm; }
        }
    </style>
</head>
<body>
    <div class="claim-toolbar d-print-none">
        <a href="{{ route('user.claims.reports') }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Back to reports</a>
        <button class="btn btn-sm btn-primary" id="printBtn"><i class="bi bi-printer me-1"></i>Print / Save as PDF</button>
        @if($claim->statusBadge())<span class="badge bg-{{ $claim->statusBadge()['class'] }} align-self-center">{{ $claim->statusBadge()['label'] }}</span>@endif
    </div>

    <div class="claim-form">
        @include('partials.claim-letterhead', [
            'company' => $company,
            'employee' => $claim->employee,
            'event' => $claim->event,
            'showRules' => true,
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
                @foreach($items as $item)
                <tr>
                    <td class="text-nowrap">{{ $item->expense_date->format('jS M Y') }}</td>
                    <td>{{ $item->description }}@if($item->isRejected()) <span class="badge bg-danger">REJECTED</span>@endif</td>
                    <td>{{ $item->project_client ?: 'N/A' }}</td>
                    <td>{{ $item->category->gl_code ? $item->category->gl_code.': ' : '' }}{{ strtoupper($item->category->name ?? '') }}</td>
                    <td class="text-end">RM{{ number_format($item->amount, 2) }}</td>
                    <td class="text-end">{{ $item->gst_amount > 0 ? 'RM'.number_format($item->gst_amount, 2) : '-' }}</td>
                    <td class="text-end">RM{{ number_format($item->total_with_gst, 2) }}</td>
                </tr>
                @endforeach
                @for($i = $items->count(); $i < 14; $i++)
                <tr><td>&nbsp;</td><td></td><td></td><td></td><td></td><td></td><td class="text-end">-</td></tr>
                @endfor
            </tbody>
            <tfoot>
                <tr class="fw-bold text-end">
                    <td colspan="4"></td>
                    <td>{{ number_format($items->sum('amount'), 2) }}</td>
                    <td>{{ number_format($items->sum('gst_amount'), 2) }}</td>
                    <td>{{ number_format($items->sum('total_with_gst'), 2) }}</td>
                </tr>
            </tfoot>
        </table>

        @include('partials.claim-signoffs', ['claim' => $claim, 'approver' => $approver])
    </div>

    {{-- ── Supporting documents (shared partial — same section the manager review shows) ── --}}
    @php $hasAttachments = $items->filter(fn ($it) => $it->receipt_path)->count() > 0; @endphp
    @if($hasAttachments)
    <div class="attachments">
        @include('partials.claim-attachments', ['items' => $items])
    </div>
    @endif

    <script nonce="{{ $cspNonce ?? '' }}">
        document.getElementById('printBtn')?.addEventListener('click', function () { window.print(); });
    </script>
</body>
</html>
