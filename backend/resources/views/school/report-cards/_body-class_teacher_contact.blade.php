{{-- Its own standalone section, not merged into Physical & Attendance
     (user correction 2026-07-24: "the class teacher name and phone
     number should have its own stand alone section and not merged with
     the 'Physical & Attendance' section. it can then be placed by
     default below the 'Physical & Attendance' section"). Reuses the
     same .rc-id identity strip layout (label above, value below) rather
     than a full width two column .rc-traits table, which reads as an
     odd, sparse row for just 2 items. --}}
@if($show('class_teacher_contact'))
  <div class="rc-block">
    <h3>Class Teacher Contact</h3>
    <div class="rc-id" style="margin-bottom:0">
      <div><b>Class Teacher</b>{{ $data['physical']['class_teacher_name'] ?? 'N/A' }}</div>
      <div><b>Class Teacher's Phone</b>{{ $data['physical']['class_teacher_phone'] ?? 'N/A' }}</div>
    </div>
  </div>
@endif
