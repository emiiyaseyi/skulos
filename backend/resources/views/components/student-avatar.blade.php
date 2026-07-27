@props(['student', 'size' => '5.5rem'])
@php
  $gender = strtolower((string) ($student->gender ?? ''));
  $isFemale = $gender === 'female';
@endphp
<div style="width:{{ $size }};height:{{ $size }};border-radius:50%;overflow:hidden;flex-shrink:0;background:var(--panel-2,#eef1f5);border:1px solid var(--line)">
  @if($student->passport_path)
    <img src="{{ \Illuminate\Support\Facades\Storage::url($student->passport_path) }}" alt="{{ $student->fullName() }}" style="width:100%;height:100%;object-fit:cover;display:block" />
  @elseif($isFemale)
    <svg viewBox="0 0 100 100" role="img" aria-label="Female avatar placeholder" style="width:100%;height:100%;display:block">
      <rect width="100" height="100" fill="#f4e0ea" />
      <circle cx="50" cy="42" r="20" fill="#c98bab" />
      <path d="M28 40c0-14 10-24 22-24s22 10 22 24c0 4-1 8-2 10-2-10-8-6-8-16-6 6-20 6-26 0 0 10-6 6-8 16-1-2-2-6-2-10z" fill="#6b3550" />
      <path d="M18 96c2-20 14-32 32-32s30 12 32 32z" fill="#c98bab" />
    </svg>
  @else
    <svg viewBox="0 0 100 100" role="img" aria-label="Male avatar placeholder" style="width:100%;height:100%;display:block">
      <rect width="100" height="100" fill="#dfe7f2" />
      <circle cx="50" cy="42" r="20" fill="#7a97bd" />
      <path d="M30 40c0-13 9-22 20-22s20 9 20 22" fill="#3c526f" />
      <path d="M18 96c2-20 14-32 32-32s30 12 32 32z" fill="#7a97bd" />
    </svg>
  @endif
</div>
