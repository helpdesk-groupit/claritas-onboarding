<!DOCTYPE html>
<html>
<head><meta charset="UTF-8">
<style>
  body { font-family:'Segoe UI',Arial,sans-serif; background:#f1f5f9; margin:0; padding:20px; }
  .email-wrap { max-width:600px; margin:0 auto; background:#fff; border-radius:12px; overflow:hidden; box-shadow:0 2px 12px rgba(0,0,0,0.08); }
  .header { padding:30px; text-align:center; }
  .header h1 { color:#fff; margin:0; font-size:21px; font-weight:700; }
  .header p { color:rgba(255,255,255,0.85); margin:6px 0 0; font-size:14px; }
  .body { padding:30px; }
  table.kv { width:100%; border-collapse:collapse; margin:12px 0; font-size:14px; }
  table.kv td { padding:8px; border-bottom:1px solid #f1f5f9; color:#1e293b; }
  table.kv td:first-child { color:#64748b; width:40%; }
  .footer { background:#f8fafc; padding:18px 30px; text-align:center; font-size:12px; color:#94a3b8; border-top:1px solid #e2e8f0; }
</style></head>
<body>
<div class="email-wrap">
  <div class="header" style="background:{{ $daysLeft <= 0 ? 'linear-gradient(135deg,#7f1d1d,#dc2626)' : 'linear-gradient(135deg,#1e3a5f,#2563eb)' }};">
    <h1>{{ $daysLeft <= 0 ? 'Claude API key expires TODAY' : 'Claude API key expiring soon' }}</h1>
    <p>
      @if($daysLeft <= 0)
        Rotation is due today
      @else
        {{ $daysLeft }} day{{ $daysLeft === 1 ? '' : 's' }} remaining
      @endif
    </p>
  </div>
  <div class="body">
    <p style="color:#475569;font-size:15px;line-height:1.6;">
      The Anthropic API key configured on the <strong>Claude API</strong> settings page
      is due for rotation under company policy (keys are retired
      {{ config('claude.key_expiry.days', 90) }} days after they're set). It powers
      receipt scanning, e-waste document reading, vendor document insight and the
      Social Media Strategist — once it expires, those AI features stop working
      until a new key is saved.
    </p>
    <table class="kv">
      <tr><td>Key</td><td>{{ $keyHistory->displayLabel() }} <span style="color:#94a3b8;">({{ $keyHistory->masked_key }})</span></td></tr>
      <tr><td>Set on</td><td>{{ fmt_date($keyHistory->started_at) }}</td></tr>
      <tr><td>Expires</td><td><strong>{{ fmt_date($keyHistory->expiresAt()) }}</strong></td></tr>
    </table>
    <p style="color:#475569;font-size:15px;line-height:1.6;">
      Generate a new key at <a href="https://console.anthropic.com/settings/keys" target="_blank" rel="noopener">console.anthropic.com</a>
      and save it on the settings page below — saving a new key automatically starts
      a fresh {{ config('claude.key_expiry.days', 90) }}-day reminder cycle.
    </p>
    @include('emails.partials._button', ['url' => route('superadmin.claude-api.index'), 'label' => 'Open Claude API settings →', 'color' => $daysLeft <= 0 ? '#dc2626' : '#2563eb'])
  </div>
  <div class="footer">Automated message from the Claude API key expiry reminder.</div>
</div>
</body>
</html>
