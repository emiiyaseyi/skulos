{{--
  The default body of the trial approval email (2026-08-16, supplied by
  the platform owner). Kept as its own view rather than a string in the
  Mailable because it is long and genuinely structured — headings and
  lists — and this way it stays readable and editable.

  The admin's own per-request note (review_note on the trial request)
  is rendered ABOVE this, so a reviewer can still say something
  specific to that school without losing any of the standard content.

  Table-based, inline styles only: same constraint as every other mail
  here, since most clients strip <style> blocks.
--}}
@php($h = 'margin:22px 0 8px;font-size:15px;font-weight:bold;color:#0B1420;')
@php($p = 'margin:0 0 14px;line-height:22px;')
@php($li = 'margin:0 0 4px;line-height:20px;')

<p style="{{ $p }}">Dear {{ $schoolName }},</p>

<p style="{{ $p }}">Thank you for your interest in SKULOS.</p>

{{-- $intro lets a school created directly by the platform owner reuse
     this same letter: it did not ask for a trial, someone set it up for
     them, and only this opening sentence differs. Sharing one letter
     keeps the two from drifting apart. --}}
@php($defaultIntro = "We are pleased to approve your school for the SKULOS trial. The trial will give your team the opportunity to explore the platform, test the different workflows, and provide feedback that will help us tailor the solution to your school's needs.")
<p style="{{ $p }}">{{ $intro ?? $defaultIntro }}</p>

@if(! empty($planName))
  <p style="{{ $p }}"><strong>Your plan:</strong> {{ $planName }}</p>
@endif

<p style="{{ $h }}">Demo Access</p>
<p style="{{ $p }}">
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

<p style="{{ $p }}">You may explore the different user roles and workflows available on the platform.</p>

<p style="{{ $h }}">Information to Prepare for Your School Setup</p>
<p style="{{ $p }}">To make the setup process smooth and allow us to configure SKULOS around your
school's structure, kindly have the following information ready:</p>

<p style="{{ $h }}">School Information</p>
<ul style="margin:0 0 14px;padding-left:20px;">
  <li style="{{ $li }}">School name and logo</li>
  <li style="{{ $li }}">School motto or slogan</li>
  <li style="{{ $li }}">Full school address</li>
  <li style="{{ $li }}">Official email address</li>
  <li style="{{ $li }}">Phone numbers</li>
  <li style="{{ $li }}">School website, if applicable</li>
</ul>

<p style="{{ $h }}">Academic Structure</p>
<ul style="margin:0 0 14px;padding-left:20px;">
  <li style="{{ $li }}">Academic sections operated by the school</li>
  <li style="{{ $li }}">Classes and arms</li>
  <li style="{{ $li }}">Subjects offered under each class</li>
  <li style="{{ $li }}">School calendar and important dates</li>
  <li style="{{ $li }}">School houses and clubs, where applicable</li>
</ul>

<p style="{{ $h }}">Student Information</p>
<ul style="margin:0 0 14px;padding-left:20px;">
  <li style="{{ $li }}">Student admission or serial number</li>
  <li style="{{ $li }}">Firstname</li>
  <li style="{{ $li }}">Lastname</li>
  <li style="{{ $li }}">Gender</li>
  <li style="{{ $li }}">Date of birth</li>
  <li style="{{ $li }}">Class and arm</li>
  <li style="{{ $li }}">Session of admission</li>
  <li style="{{ $li }}">Parent or guardian details</li>
  <li style="{{ $li }}">House</li>
  <li style="{{ $li }}">Other relevant student information maintained by the school</li>
</ul>

<p style="{{ $h }}">Staff Information</p>
<ul style="margin:0 0 14px;padding-left:20px;">
  <li style="{{ $li }}">Staff ID</li>
  <li style="{{ $li }}">Firstname</li>
  <li style="{{ $li }}">Lastname</li>
  <li style="{{ $li }}">Gender</li>
  <li style="{{ $li }}">Role or position</li>
  <li style="{{ $li }}">Subjects taught</li>
  <li style="{{ $li }}">Classes assigned</li>
  <li style="{{ $li }}">Class teacher or subject teacher</li>
  <li style="{{ $li }}">Email</li>
  <li style="{{ $li }}">Phone number</li>
</ul>

<p style="{{ $h }}">Assessment and Results</p>
<ul style="margin:0 0 14px;padding-left:20px;">
  <li style="{{ $li }}">Current assessment structure</li>
  <li style="{{ $li }}">Continuous Assessment format</li>
  <li style="{{ $li }}">Examination structure</li>
  <li style="{{ $li }}">Grading system</li>
  <li style="{{ $li }}">Report card or sample result template</li>
  <li style="{{ $li }}">Existing teacher or class teacher comment formats, if available</li>
</ul>

<p style="{{ $h }}">Parents and Communication</p>
<ul style="margin:0 0 14px;padding-left:20px;">
  <li style="{{ $li }}">Parent or guardian contact information</li>
  <li style="{{ $li }}">Preferred communication channels</li>
  <li style="{{ $li }}">Any specific communication or approval workflows</li>
</ul>

<p style="{{ $h }}">Other Information</p>
<ul style="margin:0 0 14px;padding-left:20px;">
  <li style="{{ $li }}">Admission requirements and process</li>
  <li style="{{ $li }}">Fee structure, if you would like to test the finance module</li>
  <li style="{{ $li }}">Timetable structure</li>
  <li style="{{ $li }}">School policies or workflows you would like SKULOS to accommodate</li>
</ul>

<p style="{{ $p }}">You do not need to prepare everything before the trial begins. We can work
through the requirements together and prioritise the information needed for the initial setup.</p>

<p style="{{ $p }}">We can also schedule a session to present SKULOS to your team, walk through the
major features, answer your questions, and discuss how the platform can be configured to suit your
school's operations.</p>

<p style="{{ $h }}">Scheduling Your Demo</p>
<p style="{{ $p }}">To arrange the session, please reply with:</p>
<ul style="margin:0 0 14px;padding-left:20px;">
  <li style="{{ $li }}">A preferred date and time, and an alternative if possible</li>
  <li style="{{ $li }}">The full address of the school, if the session is to hold on site</li>
  <li style="{{ $li }}">Roughly how many people will attend, and their roles</li>
  <li style="{{ $li }}">Whether you would prefer to meet in person or online</li>
</ul>

<p style="{{ $p }}">We look forward to working with you and receiving your feedback during the trial.</p>

<p style="{{ $p }}">Kind regards,<br />SKUL Operating System</p>
