@php $s = '<svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">'; @endphp
@switch($name)
  @case('dashboard') {!! $s !!}<rect x="3" y="3" width="7" height="9"/><rect x="14" y="3" width="7" height="5"/><rect x="14" y="12" width="7" height="9"/><rect x="3" y="16" width="7" height="5"/></svg> @break
  @case('school') {!! $s !!}<path d="M3 21h18M5 21V9l7-5 7 5v12M9 21v-6h6v6"/></svg> @break
  @case('students') {!! $s !!}<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg> @break
  @case('staff') {!! $s !!}<rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg> @break
  @case('academics') {!! $s !!}<path d="M22 10L12 5 2 10l10 5 10-5z"/><path d="M6 12v5c0 1 3 3 6 3s6-2 6-3v-5"/></svg> @break
  @case('attendance') {!! $s !!}<path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg> @break
  @case('results') {!! $s !!}<path d="M3 3v18h18"/><rect x="7" y="12" width="3" height="6"/><rect x="12" y="8" width="3" height="10"/><rect x="17" y="5" width="3" height="13"/></svg> @break
  @case('comms') {!! $s !!}<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg> @break
  @case('calendar') {!! $s !!}<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg> @break
  @case('settings') {!! $s !!}<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg> @break
  @case('palette') {!! $s !!}<circle cx="13.5" cy="6.5" r="1.5"/><circle cx="17.5" cy="10.5" r="1.5"/><circle cx="8.5" cy="7.5" r="1.5"/><circle cx="6.5" cy="12.5" r="1.5"/><path d="M12 2a10 10 0 0 0 0 20c1.1 0 2-.9 2-2 0-.5-.2-1-.5-1.3-.3-.4-.5-.8-.5-1.2 0-1.1.9-2 2-2h2.4c2.5 0 4.6-2 4.6-4.5C22 6.5 17.5 2 12 2z"/></svg> @break
  @case('library') {!! $s !!}<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg> @break
  @case('health') {!! $s !!}<path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.6l-1-1a5.5 5.5 0 0 0-7.8 7.8l1 1L12 21l7.8-7.6 1-1a5.5 5.5 0 0 0 0-7.8z"/><path d="M8 12h2l1.5-3 2 6L15 12h2"/></svg> @break
  @case('assignments') {!! $s !!}<path d="M9 2h6a1 1 0 0 1 1 1v1h2a1 1 0 0 1 1 1v16a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1h2V3a1 1 0 0 1 1-1z"/><path d="M9 3h6v2H9z"/><path d="M8 12l2 2 4-4"/><path d="M8 17h6"/></svg> @break
  @case('backup') {!! $s !!}<path d="M20 16.6A5 5 0 0 0 18 7h-1.3A8 8 0 1 0 4 15.3"/><path d="M12 12v9"/><path d="m8 17 4 4 4-4"/></svg> @break
  @case('mail') {!! $s !!}<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 6-10 7L2 6"/></svg> @break
  @case('pages') {!! $s !!}<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 9h18"/><path d="M6.5 6.5h.01M9.5 6.5h.01"/></svg> @break
  @case('retention') {!! $s !!}<path d="M3.5 12a8.5 8.5 0 1 0 2.5-6"/><path d="M3 4v5h5"/><path d="M12 8v4.5l3 2"/></svg> @break
  @case('clock') {!! $s !!}<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.5 2"/></svg> @break
  @case('hostel') {!! $s !!}<path d="M2 20V6"/><path d="M2 11h19a1 1 0 0 1 1 1v8"/><path d="M2 17h20"/><path d="M6 11V8a2 2 0 0 1 2-2h3a2 2 0 0 1 2 2v3"/></svg> @break
  @case('transport') {!! $s !!}<path d="M3 12h18"/><path d="M4 6h16a1 1 0 0 1 1 1v9a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1z"/><path d="M8 6v11"/><circle cx="7" cy="19" r="1.6"/><circle cx="17" cy="19" r="1.6"/></svg> @break
  @case('inventory') {!! $s !!}<path d="M21 8a1.8 1.8 0 0 0-.9-1.6l-7-4a1.8 1.8 0 0 0-1.8 0l-7 4A1.8 1.8 0 0 0 3 8v8a1.8 1.8 0 0 0 .9 1.6l7 4a1.8 1.8 0 0 0 1.8 0l7-4A1.8 1.8 0 0 0 21 16z"/><path d="M3.3 7 12 12l8.7-5"/><path d="M12 22V12"/></svg> @break
  @case('medical') {!! $s !!}<rect x="3" y="8" width="18" height="13" rx="2"/><path d="M9 8V6a3 3 0 0 1 6 0v2"/><path d="M12 12v6"/><path d="M9 15h6"/></svg> @break
  @case('visitors') {!! $s !!}<rect x="5" y="3" width="14" height="18" rx="2"/><circle cx="12" cy="10" r="3"/><path d="M8 17.5c.6-1.8 2.1-2.7 4-2.7s3.4.9 4 2.7"/></svg> @break
  @case('discipline') {!! $s !!}<path d="M12 2 4.5 5v6c0 5 3.2 8.4 7.5 11 4.3-2.6 7.5-6 7.5-11V5z"/><path d="M9 12l2 2 4-4"/></svg> @break
  @case('billing') {!! $s !!}<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/><path d="M6 15h4"/></svg> @break
  @case('trial') {!! $s !!}<rect x="8" y="2.5" width="8" height="4" rx="1"/><path d="M9 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2h-3"/><path d="m9 13 2 2 4-4"/></svg> @break
  @case('archive') {!! $s !!}<rect x="3" y="4" width="18" height="4" rx="1"/><path d="M5 8v10a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8"/><path d="M10 13h4"/></svg> @break
  @case('support') {!! $s !!}<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="3.5"/><path d="m5.6 5.6 3.1 3.1M15.3 15.3l3.1 3.1M18.4 5.6l-3.1 3.1M8.7 15.3l-3.1 3.1"/></svg> @break
  @case('chat') {!! $s !!}<path d="M8 10h.01M12 10h.01M16 10h.01"/><path d="M4 8a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v6a2 2 0 0 1-2 2H9l-4 3.5V16H6a2 2 0 0 1-2-2z"/></svg> @break
  @case('roles') {!! $s !!}<path d="M12 2 4.5 5v6c0 5 3.2 8.4 7.5 11 4.3-2.6 7.5-6 7.5-11V5z"/><circle cx="12" cy="10" r="2.3"/><path d="M8.5 15.5c.7-1.6 2-2.3 3.5-2.3s2.8.7 3.5 2.3"/></svg> @break
  @case('search') {!! $s !!}<circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg> @break
  @case('layout') {!! $s !!}<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18"/><path d="M9 9v12"/></svg> @break
  @case('trend') {!! $s !!}<path d="M3 17l6-6 4 4 8-8"/><path d="M15 7h6v6"/></svg> @break
  {{--
    The icons below were added 2026-07-26 (user report, FEEDBACK LIST.docx,
    2026-07-25: "recheck all the menu icons, as I don't want similar icons
    representing items there only if it means same thing"). Each one gives
    a nav item that was silently sharing an icon with an unrelated screen
    its own distinct glyph; a shared icon is left alone only where the two
    labels genuinely point at the exact same real route (Staff Portal/
    Teacher Portal, Student Fields/Parent Settings/Staff Settings, etc.),
    matching the "only if it means same thing" carve-out in the report.
  --}}
  @case('compass') {!! $s !!}<circle cx="12" cy="12" r="9"/><path d="m15.5 8.5-1.8 5.2-5.2 1.8 1.8-5.2z"/></svg> @break
  @case('sliders') {!! $s !!}<path d="M4 6h16M4 12h10M4 18h7"/><circle cx="17" cy="6" r="2"/><circle cx="11" cy="12" r="2"/><circle cx="8" cy="18" r="2"/></svg> @break
  @case('field') {!! $s !!}<rect x="2" y="7" width="20" height="10" rx="2"/><path d="M6 12h.01M10 12h8"/></svg> @break
  @case('widgets') {!! $s !!}<rect x="3" y="3" width="10" height="7" rx="1.5"/><rect x="15" y="3" width="6" height="18" rx="1.5"/><rect x="3" y="12" width="10" height="9" rx="1.5"/></svg> @break
  @case('workload') {!! $s !!}<path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg> @break
  @case('approve') {!! $s !!}<circle cx="12" cy="12" r="9"/><path d="m8.5 12.5 2.5 2.5 4.5-5"/></svg> @break
  @case('schedule') {!! $s !!}<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 10h18M8 10v9M14 10v9"/></svg> @break
  @case('terms') {!! $s !!}<path d="M6 2h12M6 22h12"/><path d="M6 2c0 6 4 8 6 10-2 2-6 4-6 10M18 2c0 6-4 8-6 10 2 2 6 4 6 10"/></svg> @break
  @case('narrative') {!! $s !!}<rect x="4" y="3" width="16" height="18" rx="2"/><path d="M8 8h8M8 12h8M8 16h5"/></svg> @break
  @case('toggle') {!! $s !!}<rect x="2" y="7" width="20" height="10" rx="5"/><circle cx="16" cy="12" r="3"/></svg> @break
  @case('fee') {!! $s !!}<path d="M20.6 13.4 13.4 20.6a2 2 0 0 1-2.8 0L2 12V2h10l8.6 8.6a2 2 0 0 1 0 2.8z"/><path d="M7 7h.01"/></svg> @break
  @case('renew') {!! $s !!}<path d="M21 12a9 9 0 1 1-3-6.7"/><path d="M21 3v6h-6"/></svg> @break
  @case('history') {!! $s !!}<rect x="3" y="4" width="14" height="16" rx="2"/><path d="M6.5 9h7M6.5 13h5"/><circle cx="17.5" cy="17" r="3.2"/><path d="M17.5 15.5V17l1 .8"/></svg> @break
  @case('house') {!! $s !!}<path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V21h14V9.5"/></svg> @break
  @case('campus') {!! $s !!}<path d="M21 10c0 6-9 12-9 12s-9-6-9-12a9 9 0 1 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg> @break
  @case('builder') {!! $s !!}<rect x="4" y="4" width="7" height="16" rx="1.5"/><rect x="13" y="4" width="7" height="7" rx="1.5"/><rect x="13" y="13" width="7" height="7" rx="1.5"/></svg> @break
  @case('traits') {!! $s !!}<path d="m12 2.5 2.9 6 6.6.9-4.8 4.6 1.1 6.5-5.8-3.2-5.8 3.2 1.1-6.5-4.8-4.6 6.6-.9z"/></svg> @break
  @case('structure') {!! $s !!}<path d="M9 6h11M9 12h11M9 18h11"/><path d="M4 6h.01M4 12h.01M4 18h.01"/></svg> @break
  @case('broadsheet') {!! $s !!}<rect x="2" y="4" width="20" height="16" rx="2"/><path d="M2 9h20M2 14h20M9 4v16M15 4v16"/></svg> @break
  @case('scale') {!! $s !!}<path d="M12 3v18M7 21h10"/><path d="M5 7h6M13 7h6"/><path d="M5 7 2 13a3 3 0 0 0 6 0zM19 7l-3 6a3 3 0 0 0 6 0z"/></svg> @break
  @case('pencil') {!! $s !!}<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/></svg> @break
  @case('percent') {!! $s !!}<path d="M6 6l12 12"/><circle cx="8" cy="8" r="2"/><circle cx="16" cy="16" r="2"/></svg> @break
  @case('copies') {!! $s !!}<rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg> @break
  @case('inbox') {!! $s !!}<path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/></svg> @break
  @case('warning') {!! $s !!}<path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><path d="M12 9v4M12 17h.01"/></svg> @break
  @case('parents') {!! $s !!}<circle cx="9" cy="7" r="3"/><path d="M4 20a5 5 0 0 1 10 0"/><circle cx="18" cy="9" r="2"/><path d="M15 20a4 4 0 0 1 7-2.5"/></svg> @break
  @case('insight') {!! $s !!}<path d="M13 2 3 14h9l-1 8 10-12h-9z"/></svg> @break
  @case('stats') {!! $s !!}<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M8 17V11M12 17V7M16 17v-4"/></svg> @break
  @default {!! $s !!}<circle cx="12" cy="12" r="9"/></svg>
@endswitch
