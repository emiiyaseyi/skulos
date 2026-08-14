{{-- Email-safe HTML: inline styles only, no external CSS/JS/images, since
     most mail clients strip <style> blocks and remote assets get blocked
     by default (both of which also hurt spam scoring). Keep this the one
     shared shell every transactional email renders through. --}}
<!doctype html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>{{ $subject ?? 'SKULOS' }}</title>
</head>
<body style="margin:0;padding:0;background-color:#EEF1F6;font-family:Arial,Helvetica,sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#EEF1F6;padding:32px 16px;">
  <tr>
    <td align="center">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:520px;background-color:#FFFFFF;border-radius:14px;overflow:hidden;border:1px solid #E2E6ED;">
        <tr>
          <td align="center" style="background-color:#0B1420;padding:24px 32px;text-align:center;">
            <span style="font-family:Arial,Helvetica,sans-serif;font-weight:bold;font-size:20px;color:#FFFFFF;letter-spacing:-0.3px;">SK<span style="color:#E8A33D;">U</span>LOS</span>
          </td>
        </tr>
        <tr>
          <td style="padding:32px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
              <tr><td style="font-size:15px;line-height:24px;color:#1A2333;">
                {!! $body !!}
              </td></tr>
            </table>
          </td>
        </tr>
        <tr>
          <td style="padding:20px 32px;background-color:#F7F8FA;border-top:1px solid #E2E6ED;">
            <p style="margin:0;font-size:12px;line-height:20px;color:#7C8698;">
              @php($support = app(\App\Services\Platform\SupportContactService::class)->effective())
              This message was sent by SKULOS, the Education Operating System for Modern Schools.
              Questions any time: <a href="mailto:{{ $support['email'] }}" style="color:#7C8698;">{{ $support['email'] }}</a>
              or {{ $support['phone'] }}.
            </p>
          </td>
        </tr>
      </table>
    </td>
  </tr>
</table>
</body>
</html>
