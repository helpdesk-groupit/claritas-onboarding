<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta name="x-apple-disable-message-reformatting">
  <title>Happy Birthday, {{ $greetingName }}!</title>

  <!--[if mso]>
  <style type="text/css">
    table, td, div, h1, p { font-family: Arial, sans-serif !important; }
  </style>
  <![endif]-->

  {{-- Animations: light up on every modern client; Outlook desktop ignores
       @keyframes and falls back to the static layout, which still looks great. --}}
  <style type="text/css">
    @keyframes bw-pulse {
      0%, 100% { transform: scale(1) rotate(0deg); }
      30%      { transform: scale(1.10) rotate(-3deg); }
      60%      { transform: scale(1.04) rotate(3deg); }
    }
    @keyframes bw-float {
      0%, 100% { transform: translateY(0); }
      50%      { transform: translateY(-9px); }
    }
    @keyframes bw-wave {
      0%, 100% { transform: rotate(-8deg); }
      50%      { transform: rotate(8deg); }
    }
    @keyframes bw-twinkle {
      0%, 100% { opacity: 1; transform: scale(1); }
      50%      { opacity: 0.45; transform: scale(0.85); }
    }
    @keyframes bw-glow {
      0%, 100% { box-shadow: 0 0 0 0 {{ $theme['primary'] }}55; }
      50%      { box-shadow: 0 0 28px 6px {{ $theme['primary'] }}55; }
    }
    @keyframes bw-shimmer {
      0%   { background-position: -200% 0; }
      100% { background-position:  200% 0; }
    }

    .bw-card        { animation: bw-glow 3s ease-in-out infinite; }
    .bw-cake        { display: inline-block; animation: bw-pulse 1.8s ease-in-out infinite; transform-origin: center bottom; }
    .bw-confetti    { display: inline-block; animation: bw-float 2.4s ease-in-out infinite; }
    .bw-confetti-1  { animation-delay: 0s;    }
    .bw-confetti-2  { animation-delay: 0.18s; }
    .bw-confetti-3  { animation-delay: 0.36s; }
    .bw-confetti-4  { animation-delay: 0.54s; }
    .bw-confetti-5  { animation-delay: 0.72s; }
    .bw-celebrate   { display: inline-block; animation: bw-wave 1.6s ease-in-out infinite; transform-origin: center; }
    .bw-star        { display: inline-block; animation: bw-twinkle 1.4s ease-in-out infinite; }
    .bw-star-2      { animation-delay: 0.7s; }

    .bw-headline {
      background: linear-gradient(90deg, {{ $theme['accent'] }} 0%, {{ $theme['primary'] }} 50%, {{ $theme['accent'] }} 100%);
      background-size: 200% auto;
      -webkit-background-clip: text;
              background-clip: text;
      -webkit-text-fill-color: transparent;
              color: transparent;
      animation: bw-shimmer 4s linear infinite;
    }

    /* Respect accessibility preference: kill all motion */
    @media (prefers-reduced-motion: reduce) {
      .bw-card, .bw-cake, .bw-confetti, .bw-celebrate, .bw-star, .bw-headline {
        animation: none !important;
      }
      .bw-headline { color: {{ $theme['accent'] }} !important; -webkit-text-fill-color: {{ $theme['accent'] }} !important; }
    }

    /* Mobile: smaller card paddings */
    @media only screen and (max-width: 620px) {
      .bw-shell { width: 100% !important; }
      .bw-pad   { padding-left: 22px !important; padding-right: 22px !important; }
      .bw-cake  { font-size: 56px !important; }
    }
  </style>
</head>
<body style="margin:0; padding:0; background-color:{{ $theme['bg'] }}; font-family:Arial, Helvetica, sans-serif; -webkit-text-size-adjust:100%; -ms-text-size-adjust:100%;">

  {{-- Preheader (inbox preview text, hidden in the email body) --}}
  <div style="display:none; max-height:0; overflow:hidden; mso-hide:all; font-size:1px; line-height:1px; color:{{ $theme['bg'] }};">
    Wishing you the happiest of birthdays from everyone at {{ $companyName }}! &zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;
  </div>

  {{-- Outer full-bleed background --}}
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="{{ $theme['bg'] }}" style="background-color:{{ $theme['bg'] }}; width:100%;">
    <tr>
      <td align="center" valign="top" style="padding:30px 10px;">

        {{-- Card shell --}}
        <table role="presentation" class="bw-shell bw-card" width="600" cellpadding="0" cellspacing="0" border="0" bgcolor="#ffffff" style="width:600px; max-width:600px; background-color:#ffffff; border:3px solid {{ $theme['primary'] }}; border-radius:14px;">

          {{-- Top decorative band — gradient with solid bgcolor fallback for Outlook --}}
          <tr>
            <td bgcolor="{{ $theme['primary'] }}" align="center"
                style="background-color:{{ $theme['primary'] }};
                       background-image:linear-gradient(135deg, {{ $theme['primary'] }} 0%, {{ $theme['accent'] }} 100%);
                       padding:18px 20px;">
              <p style="margin:0; color:#ffffff; font-family:'Trebuchet MS', Arial, sans-serif; font-size:14px; font-weight:bold; letter-spacing:3px; text-transform:uppercase;">
                <span class="bw-celebrate">&#127881;</span> &nbsp; A Special Day &nbsp; <span class="bw-celebrate">&#127881;</span>
              </p>
            </td>
          </tr>

          @if(!empty($logoUrl))
          {{-- Company logo --}}
          <tr>
            <td bgcolor="#ffffff" align="center" class="bw-pad" style="background-color:#ffffff; padding:28px 30px 0;">
              <img src="{{ $logoUrl }}" alt="{{ $companyName }} logo"
                   width="160" height="auto"
                   style="display:block; width:160px; max-width:160px; height:auto; border:0; outline:none; text-decoration:none; margin:0 auto;">
            </td>
          </tr>
          @endif

          {{-- Animated cake centerpiece --}}
          <tr>
            <td bgcolor="#ffffff" align="center" class="bw-pad" style="background-color:#ffffff; padding:{{ !empty($logoUrl) ? '24px' : '40px' }} 30px 10px;">
              <span class="bw-cake" style="font-size:72px; line-height:1; font-family:Arial, sans-serif;">{!! $theme['cake'] !!}</span>
            </td>
          </tr>

          {{-- Shimmering gradient headline --}}
          <tr>
            <td align="center" class="bw-pad" style="padding:6px 30px 4px;">
              <h1 style="margin:0; font-family:Georgia, 'Times New Roman', serif; font-size:30px; font-weight:bold; line-height:1.3; color:{{ $theme['accent'] }};">
                <span style="display:inline-block;">&#127874;</span>
                <span class="bw-headline" style="color:{{ $theme['accent'] }};">Happy Birthday, {{ $greetingName }}!</span>
                <span style="display:inline-block;">&#127874;</span>
              </h1>
            </td>
          </tr>

          {{-- Floating confetti row — each emoji bobs on its own delay --}}
          <tr>
            <td align="center" class="bw-pad" style="padding:18px 30px 22px;">
              @php
                $emojis = preg_split('/\s+/', trim($theme['confetti']));
              @endphp
              <p style="margin:0; font-size:26px; line-height:1; letter-spacing:8px; font-family:Arial, sans-serif;">
                @foreach($emojis as $i => $emj)
                  <span class="bw-confetti bw-confetti-{{ $i + 1 }}">{!! $emj !!}</span>
                @endforeach
              </p>
            </td>
          </tr>

          {{-- Message body --}}
          <tr>
            <td align="center" class="bw-pad" style="padding:0 40px 8px;">
              <p style="margin:0 0 16px; color:#374151; font-family:Arial, Helvetica, sans-serif; font-size:15px; line-height:1.75;">
                Today is all about celebrating <strong style="color:{{ $theme['accent'] }};">you</strong> and the wonderful energy you bring to everyone around you. Your kindness, dedication, and presence truly make a difference, and we are grateful to have you as part of our journey.
              </p>
              <p style="margin:0 0 16px; color:#374151; font-family:Arial, Helvetica, sans-serif; font-size:15px; line-height:1.75;">
                May your birthday be filled with happiness, laughter, love, and all the little moments that make life beautiful. As you begin another year, may it bring you good health, exciting opportunities, meaningful memories, and endless reasons to smile.
              </p>
              <p style="margin:0; color:#374151; font-family:Arial, Helvetica, sans-serif; font-size:15px; line-height:1.75;">
                Thank you for being such a valued part of our team. We hope today reminds you of how appreciated you truly are.
              </p>
            </td>
          </tr>

          {{-- Sign-off --}}
          <tr>
            <td align="center" class="bw-pad" style="padding:28px 30px 32px;">
              <p style="margin:0; color:#9ca3af; font-family:Arial, Helvetica, sans-serif; font-size:13px; font-style:italic;">
                With warmest wishes and heartfelt appreciation,
              </p>
              <p style="margin:6px 0 0; color:{{ $theme['accent'] }}; font-family:Georgia, 'Times New Roman', serif; font-size:18px; font-weight:bold;">
                The {{ $companyName }} Family
              </p>
            </td>
          </tr>

          {{-- Bottom band with twinkling stars --}}
          <tr>
            <td bgcolor="{{ $theme['primary'] }}" align="center"
                style="background-color:{{ $theme['primary'] }};
                       background-image:linear-gradient(135deg, {{ $theme['accent'] }} 0%, {{ $theme['primary'] }} 100%);
                       padding:14px 20px;">
              <p style="margin:0; color:#ffffff; font-family:'Trebuchet MS', Arial, sans-serif; font-size:13px; letter-spacing:1px;">
                <span class="bw-star">&#127775;</span> &nbsp; Wishing you a year ahead filled with joy, success, and beautiful moments &nbsp; <span class="bw-star bw-star-2">&#127775;</span>
              </p>
            </td>
          </tr>

        </table>

        {{-- Footer note --}}
        <table role="presentation" class="bw-shell" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px; max-width:600px;">
          <tr>
            <td align="center" style="padding:16px 20px 0;">
              <p style="margin:0; color:#9ca3af; font-family:Arial, Helvetica, sans-serif; font-size:11px; line-height:1.5;">
                This is an automated birthday greeting from the Employee Portal. Please do not reply to this email.
              </p>
            </td>
          </tr>
        </table>

      </td>
    </tr>
  </table>

</body>
</html>
