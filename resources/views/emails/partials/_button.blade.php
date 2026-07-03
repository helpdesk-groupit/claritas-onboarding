{{--
  Bulletproof email button — renders reliably in Outlook (Word engine), Gmail,
  Apple Mail, Zoho, etc. Uses a table with a solid bgcolor + padding on the <td>
  (Outlook ignores <head> classes, CSS gradients, and padding on <a>). Rounded
  in clients that support border-radius; a clean solid rectangle in Outlook.

  Params:
    $url    — link target (required)
    $label  — button text (required)
    $color  — solid background colour (optional; defaults to brand blue)
--}}
@php $btnColor = $color ?? '#2563eb'; @endphp
<table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:18px auto;">
    <tr>
        <td align="center" bgcolor="{{ $btnColor }}" style="border-radius:8px; padding:13px 32px; mso-padding-alt:13px 32px;">
            <a href="{{ $url }}" target="_blank"
               style="font-family:'Segoe UI',Arial,sans-serif; font-size:15px; font-weight:bold; line-height:1; color:#ffffff; text-decoration:none; display:inline-block; background-color:{{ $btnColor }}; border-radius:8px;">{{ $label }}</a>
        </td>
    </tr>
</table>
