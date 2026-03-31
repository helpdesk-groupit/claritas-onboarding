<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Onboarding Invitation</title>
</head>
@php
    $__logoUrl = null;
    $__logoCompany = \App\Models\Company::where('name', $companyName ?? '')->first();
    if ($__logoCompany?->logo_path) {
        $__logoUrl = asset('storage/' . $__logoCompany->logo_path);
    }
@endphp
<body style="margin:0;padding:0;background:#f1f5f9;font-family:Arial,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;padding:32px 0;">
  <tr>
    <td align="center">
      <table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.08);">

        <!-- Header -->
        <tr>
          <td style="background:linear-gradient(135deg,#0052CC,#2684FE);padding:32px 40px;">
            @if(!empty($__logoUrl))
        <div style="margin-bottom:12px;text-align:center;"><img src="{{ $__logoUrl }}" alt="Company Logo"
                         width="160" height="auto"
                         style="display:block;width:160px;height:auto;border:0;outline:none;text-decoration:none;margin:0 auto;"></div>
        @endif
        <h1 style="margin:0;color:#ffffff;font-size:22px;font-weight:700;">
              Employee Portal
            </h1>
            <p style="margin:6px 0 0;color:#bfdbfe;font-size:14px;">Onboarding Invitation</p>
          </td>
        </tr>

        <!-- Body -->
        <tr>
          <td style="padding:36px 40px;">
            <p style="margin:0 0 16px;font-size:15px;color:#374151;">Hello,</p>
            <p style="margin:0 0 16px;font-size:15px;color:#374151;">
              <strong>{{ $senderName }}</strong> from <strong>{{ $companyName }}</strong> has invited you to complete your personal details
              as part of your onboarding process.
            </p>
            <p style="margin:0 0 24px;font-size:15px;color:#374151;">
              Please click the button below to fill in your personal information. You will be asked to verify
              your email address (<strong>{{ $inviteEmail }}</strong>) before proceeding.
            </p>

            <!-- CTA Button -->
            <table cellpadding="0" cellspacing="0" style="margin:0 0 24px;">
              <tr>
                <td style="background:#2684FE;border-radius:8px;">
                  <!--[if mso]>
                  <v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word"
                    href="{{ $inviteUrl }}" style="height:48px;v-text-anchor:middle;width:220px;" arcsize="17%" fillcolor="#2684FE" strokecolor="#2684FE">
                    <w:anchorlock/>
                    <center style="color:#ffffff;font-family:Arial,sans-serif;font-size:15px;font-weight:700;">
                      Complete My Details
                    </center>
                  </v:roundrect>
                  <![endif]-->
                  <a href="{{ $inviteUrl }}"
                     style="display:inline-block;padding:14px 28px;color:#ffffff;font-size:15px;font-weight:700;text-decoration:none;border-radius:8px;mso-hide:all;">
                    Complete My Details
                  </a>
                </td>
              </tr>
            </table>

            <p style="margin:0 0 8px;font-size:13px;color:#6b7280;">
              <strong>Note:</strong> This link will expire in <strong>24 hours</strong>. If you did not expect this email,
              please ignore it or contact your HR team.
            </p>
            <p style="margin:0;font-size:12px;color:#9ca3af;word-break:break-all;">
              Or copy this link: {{ $inviteUrl }}
            </p>
          </td>
        </tr>

        <!-- Footer -->
        <tr>
          <td style="background:#f8fafc;padding:20px 40px;border-top:1px solid #e5e7eb;">
            <p style="margin:0;font-size:12px;color:#9ca3af;text-align:center;">
              Official communication — {{ $companyName }}
            </p>
          </td>
        </tr>

      </table>
    </td>
  </tr>
</table>
</body>
</html>