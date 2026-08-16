{{--
  The demo school link and its test accounts (2026-08-16). Rendered on
  its own only when the trial/welcome letter's wording has been edited
  from Settings > Mail Templates: the standard letter already includes
  this block inline, but an edited one is free prose, and a school that
  cannot sign in to the demo has nothing to try.

  Table-based, inline styles only, same as every other mail here.
--}}
<p style="margin:22px 0 8px;font-size:15px;font-weight:bold;color:#0B1420;">Demo Access</p>
<p style="margin:0 0 14px;line-height:22px;">
  Demo School: <a href="{{ $demoUrl }}" style="color:#0B1420;font-weight:bold;">{{ $demoUrl }}</a>
</p>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#F7F8FA;border-radius:10px;margin:0 0 16px;">
  <tr><td style="padding:16px 18px;">
    <p style="margin:0 0 10px;font-size:12px;font-weight:bold;letter-spacing:.4px;text-transform:uppercase;color:#7C8698;">Test Accounts</p>
    @foreach($demoAccounts as $account)
      <p style="margin:0 0 6px;font-size:13px;">
        <strong>{{ $account['role'] }}:</strong> {{ $account['email'] }}
        <span style="color:#7C8698;">|</span> Password: {{ $account['password'] }}
      </p>
    @endforeach
  </td></tr>
</table>
