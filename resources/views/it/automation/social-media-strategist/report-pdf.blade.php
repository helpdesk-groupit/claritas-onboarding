<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        * { box-sizing: border-box; }
        body { font-family: DejaVu Sans, sans-serif; color: #1f2430; font-size: 11px; line-height: 1.55; margin: 0; }
        .cover { background: #14171D; color: #fff; padding: 90px 44px; }
        .cover .kicker { color: #C084FC; font-size: 12px; letter-spacing: 3px; font-weight: bold; }
        .cover h1 { font-size: 30px; margin: 8px 0 6px; }
        .cover .meta { color: #9AA3B2; font-size: 12px; }
        .wrap { padding: 32px 40px; }
        h2 { font-size: 16px; color: #14171D; margin: 0 0 4px; padding-bottom: 4px; border-bottom: 2px solid #7C3AED; }
        .live { display: inline-block; font-size: 8px; font-weight: bold; letter-spacing: .4px; color: #166534; background: #dcfce7; border-radius: 8px; padding: 1px 6px; vertical-align: middle; margin-left: 6px; }
        .section { margin-bottom: 22px; page-break-inside: avoid; }
        .content { white-space: pre-wrap; color: #2a2f3a; margin-top: 8px; }
        .foot { margin-top: 26px; padding-top: 10px; border-top: 1px solid #e2e8f0; color: #94a3b8; font-size: 9px; }
    </style>
</head>
<body>
    <div class="cover">
        <div class="kicker">SOCIAL MEDIA STRATEGY</div>
        <h1>{{ $strategy->clientName() }}</h1>
        <div class="meta">{{ $strategy->intake('industry') }} &nbsp;·&nbsp; {{ optional($strategy->generated_at)->format('d M Y') ?? now()->format('d M Y') }} &nbsp;·&nbsp; Strategist OS</div>
    </div>

    <div class="wrap">
        @foreach($sections as $s)
            <div class="section">
                <h2>{{ $s->title ?: (\App\Models\SocialStrategy::SECTIONS[$s->section_key]['label'] ?? $s->section_key) }}@if($s->is_live_sourced)<span class="live">LIVE-SOURCED</span>@endif</h2>
                <div class="content">{{ $s->content }}</div>
            </div>
        @endforeach

        <div class="foot">
            Items tagged [ASSUMPTION] or [VERIFY BEFORE LAUNCH] require confirmation before going live. Final creative in regulated verticals requires qualified legal sign-off.
        </div>
    </div>
</body>
</html>
