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
    @unless(request()->boolean('embed'))
    {{-- Standalone toolbar — hidden when the report is embedded in a modal iframe (?embed=1). --}}
    <div class="claim-toolbar d-print-none">
        <a href="{{ route('user.claims.index') }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Back to My Claims</a>
        <button class="btn btn-sm btn-primary" id="printBtn"><i class="bi bi-printer me-1"></i>Print</button>
        <a class="btn btn-sm btn-outline-danger" href="{{ route('user.claims.pdf', $claim) }}"><i class="bi bi-file-earmark-pdf me-1"></i>Download PDF</a>
        @foreach($claim->stageBadges() as $sb)<span class="badge bg-{{ $sb['class'] }} {{ $sb['class'] === 'warning' ? 'text-dark' : '' }} align-self-center">{{ $sb['label'] }}</span>@endforeach
    </div>
    @endunless

    <div class="claim-form">
        @include('partials.claim-report-form', [
            'claim' => $claim,
            'company' => $company,
            'items' => $items,
            'approver' => $approver,
            'padRows' => false,
            'showAttachments' => true,
        ])
    </div>

    <script nonce="{{ $cspNonce ?? '' }}">
        document.getElementById('printBtn')?.addEventListener('click', function () { window.print(); });
    </script>
</body>
</html>
