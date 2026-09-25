<?php

namespace App\Services\ReportCards;

use App\Models\Tenant\Attendance;
use App\Models\Tenant\BehaviouralTrait;
use App\Models\Tenant\AssessmentComponent;
use App\Models\Tenant\GradingScale;
use App\Models\Tenant\ReportCardReview;
use App\Models\Tenant\Staff;
use App\Models\Tenant\Student;
use App\Models\Tenant\StudentBehaviouralRating;
use App\Models\Tenant\StudentSkillRemark;
use App\Models\Tenant\StudentSubjectComment;
use App\Models\Tenant\StudentTermRemark;
use App\Models\Tenant\Subject;
use App\Models\Tenant\Term;
use App\Services\Results\ResultService;
use App\Services\Tenancy\TenantManager;

/**
 * Builds the data a report card view needs, whatever template/fields the
 * school has chosen. Nothing here assumes exactly 3 terms: it walks every
 * term up to and including the one being rendered, in sequence order, and
 * shows each earlier term's subject total as its own column plus a
 * cumulative average — the "third term brings in first and second term
 * scores" request generalised to any number of terms.
 */
class ReportCardService
{
    public function __construct(protected ResultService $results, protected TenantManager $tenants, protected ReportCardCumulativePolicy $cumulativePolicy, protected ReportCardPreferences $prefs)
    {
    }

    /**
     * Real report card data for one student, one term, computed from
     * whatever scores/attendance/remarks exist in the tenant database.
     */
    public function forStudent(Student $student, Term $term, ?GradingScale $scale = null, string $reportType = ReportCardReview::REPORT_TYPE_FULL_TERM): array
    {
        $classArm = $student->currentClassArm;
        // A class's own grading scale wins over its section's own scale,
        // which wins over the school wide default (user request
        // 2026-07-24, real bug live on greenfield: "i am viewing a
        // report card in sss and it is showing grade as excellent,
        // good etc which is not what we have under the grading
        // structure for the section... sss falls under secondary
        // school"). JSS and SSS sit under one shared "Secondary"
        // section (confirmed against greenfield's own real data), so a
        // section level scale alone cannot tell WAEC graded SSS apart
        // from NECO/BECE graded JSS, the class's own override is what
        // actually distinguishes them.
        $scale ??= $classArm?->schoolClass?->gradingScale
            ?? $classArm?->schoolClass?->section?->gradingScale
            ?? GradingScale::default();

        // A school configurable choice per term sequence (Examinations >
        // Term Calculation, 2026-07-24), not a blanket on/off: 'average'
        // brings in every earlier term's total and shows the average,
        // 'comparison' shows the same earlier totals with no average,
        // 'off' shows this term alone. When off, the extra per-subject
        // lookups for every earlier term are skipped entirely rather
        // than computed and then hidden by the view, a real saving for
        // a school that never wants this.
        $cumulativeMode = $this->cumulativePolicy->modeFor($term, $classArm?->school_class_id);
        $priorTerms = $cumulativeMode === ReportCardCumulativePolicy::MODE_OFF
            ? collect()
            : Term::where('academic_session_id', $term->academic_session_id)
                ->where('sequence', '<=', $term->sequence)
                ->orderBy('sequence')
                ->get();

        $subjectIds = \App\Models\Tenant\Score::where('student_id', $student->id)
            ->where('term_id', $term->id)
            ->distinct()
            ->pluck('subject_id');
        $subjects = Subject::whereIn('id', $subjectIds)->get()->keyBy('id');

        // Mid-term report cards (2026-08-07): only the components an
        // admin has opted into this report type count, both for the
        // scores summed and the grade band lookup (normalized inside
        // subjectResult() so a subset like CA1+CA2 summing to less than
        // 100 still grades sanely). component_columns is the canonical
        // ordered column list for the view, replacing the old "peek at
        // the first subject's own components" derivation.
        $reportComponents = AssessmentComponent::forReportType($term, $reportType);
        $componentIds = $reportComponents->pluck('id')->all();
        $componentColumns = $reportComponents->pluck('name')->all();

        $subjectRows = [];
        foreach ($subjectIds as $subjectId) {
            $current = $this->results->subjectResult($student->id, $subjectId, $term->id, $scale, $componentIds);

            $previousTerms = [];
            $cumulativeAverage = null;
            if ($cumulativeMode !== ReportCardCumulativePolicy::MODE_OFF) {
                $termTotals = [];
                foreach ($priorTerms as $t) {
                    $priorComponentIds = $t->id === $term->id
                        ? $componentIds
                        : AssessmentComponent::forReportType($t, $reportType)->pluck('id')->all();
                    $total = $t->id === $term->id
                        ? $current['total']
                        : $this->results->subjectResult($student->id, $subjectId, $t->id, $scale, $priorComponentIds)['total'];
                    $termTotals[] = $total;
                    if ($t->id !== $term->id) {
                        $previousTerms[$t->name] = $total;
                    }
                }
                if ($cumulativeMode === ReportCardCumulativePolicy::MODE_AVERAGE) {
                    $cumulativeAverage = count($termTotals) ? round(array_sum($termTotals) / count($termTotals), 2) : 0.0;
                }
            }

            $stats = $classArm
                ? $this->results->subjectStats($classArm->id, $subjectId, $term->id)
                : ['average' => 0.0, 'highest' => 0.0, 'lowest' => 0.0, 'positions' => []];

            $subjectRows[] = [
                'name' => $subjects[$subjectId]?->name ?? (string) $subjectId,
                'category' => $subjects[$subjectId]?->category,
                'components' => $current['components'],
                'total' => $current['total'],
                'grade' => $current['grade'],
                'remark' => $current['remark'],
                'position' => $stats['positions'][$student->id] ?? null,
                'class_average' => $stats['average'],
                'highest' => $stats['highest'],
                'lowest' => $stats['lowest'],
                'previous_terms' => $previousTerms,
                'cumulative_average' => $cumulativeAverage,
            ];
        }

        $overallTotal = array_sum(array_column($subjectRows, 'total'));
        $overallAverage = count($subjectRows) ? round($overallTotal / count($subjectRows), 2) : 0.0;
        // Overall Grade (user request 2026-07-24: "add a Grade to this
        // section which is based on the overall average gotten by the
        // student... it changes based on the section and grade set"),
        // the same section scoped $scale every per subject grade above
        // already uses.
        $overallGrade = $scale?->bandFor($overallAverage)?->grade;

        $overallPosition = null;
        $classSize = null;
        if ($classArm) {
            $broadsheet = $this->results->classBroadsheet($classArm->id, $term->id);
            $classSize = count($broadsheet);
            foreach ($broadsheet as $row) {
                if ($row['student_id'] === $student->id) {
                    $overallPosition = $row['position'];
                    break;
                }
            }
        }

        $attendance = ['present' => 0, 'total' => 0];
        if ($term->starts_on && $term->ends_on) {
            $base = Attendance::students()
                ->where('person_id', $student->id)
                ->whereBetween('date', [$term->starts_on->toDateString(), $term->ends_on->toDateString()]);
            $attendance['total'] = (clone $base)->count();
            $attendance['present'] = (clone $base)->where('status', 'present')->count();
        }

        $remark = StudentTermRemark::where('student_id', $student->id)->where('term_id', $term->id)->first();

        // Behavioural/psychomotor traits + physical stats + a per-subject
        // free-text comment (comments-only template mode), all entered by
        // the class teacher (ReportCardController::classEntry(), built
        // 2026-07-18 from 2 real report card templates supplied as
        // reference). Every trait always appears, even one this student
        // was never rated on ("Not yet entered"), so a school notices a
        // gap rather than a silently missing row.
        $traitRatings = StudentBehaviouralRating::where('student_id', $student->id)->where('term_id', $term->id)
            ->pluck('value', 'behavioural_trait_id');
        $traits = BehaviouralTrait::active()->orderBy('sequence')->get()->map(fn (BehaviouralTrait $t) => [
            'name' => $t->name,
            'type' => $t->type,
            'domain' => $t->domain,
            'value' => $traitRatings[$t->id] ?? null,
        ]);

        $subjectComments = StudentSubjectComment::where('student_id', $student->id)->where('term_id', $term->id)
            ->with('subject')->get()
            ->mapWithKeys(fn (StudentSubjectComment $c) => [$c->subject?->name ?? (string) $c->subject_id => $c->comment]);

        // Per-class-arm stamp/signature, falling back to the school-wide
        // default when the class teacher never uploaded their own (user
        // request 2026-07-18: "uploaded for all the school's sections and
        // classes but each class teacher can decide to change it").
        $school = $this->tenants->current();
        $stampPath = $classArm?->stamp_path ?: $school?->stamp_path;
        $signaturePath = $classArm?->signature_path ?: $school?->signature_path;
        $classTeacher = $classArm?->class_teacher_staff_id ? Staff::find($classArm->class_teacher_staff_id) : null;

        // The logo printed is the STUDENT'S OWN section's logo, falling
        // back to the school-wide default (user request 2026-09-09: a
        // school with, say, a separate Nursery & Primary identity and a
        // separate Secondary one wants each report card to carry the
        // logo for the class it actually falls under, not always the
        // one school-wide mark). Same fallback shape as stamp/signature
        // just above.
        $section = $classArm?->schoolClass?->section;
        $logoPath = ($section && $school ? $section->resolvedLogoPath($school) : null) ?: $school?->logo_path;

        // Watermark (user directive 2026-09-21: "the section logo is
        // also used as a watermark for the section report cards", then
        // extended to class level the same day: "one can tick the
        // section or classes"; 2026-09-22: "make it possible for the
        // school to upload another or add a text"), opt-in per class or
        // section, off by default everywhere. The section's own
        // resolvedWatermark() decides WHAT shows (its resolved logo by
        // default, a separately uploaded image, or plain text) --
        // watermark_enabled here only decides whether it shows at all,
        // same as before this existed.
        $watermarkOn = $classArm?->schoolClass?->resolvedWatermarkEnabled() ?? false;
        $watermark = $watermarkOn ? $section?->resolvedWatermark($school) : null;
        $watermarkPath = $watermark && $watermark['type'] === 'image' ? $watermark['value'] : null;
        $watermarkText = $watermark && $watermark['type'] === 'text' ? $watermark['value'] : null;

        // Key Assessment Areas (user directive 2026-09-21): only built
        // when this class (falling back to its section) has actually
        // opted into the 'skills' narrative style, so a school that
        // never uses this pays no extra query for it. Grouped by
        // category, in catalog order, every configured skill always
        // appears even if this student was never given a remark for it
        // ("Not yet entered" in the view), same convention
        // BehaviouralTrait traits already use.
        $skillAreas = [];
        if (($classArm?->schoolClass?->resolvedNarrativeStyle() ?? 'comments') === 'skills') {
            $skillRemarks = StudentSkillRemark::where('student_id', $student->id)->where('term_id', $term->id)
                ->pluck('remark', 'skill_area_id');
            foreach (\App\Models\Tenant\ReportCardSkillArea::active()->orderBy('sequence')->get() as $area) {
                $skillAreas[$area->category][] = [
                    'name' => $area->name,
                    'remark' => $skillRemarks[$area->id] ?? null,
                ];
            }
        }

        return [
            'identity' => [
                'name' => $student->fullName(),
                'admission_number' => $student->admission_number,
                'gender' => $student->gender,
                'date_of_birth' => $student->date_of_birth?->format('d M Y'),
                'age' => $student->date_of_birth?->age,
                'class' => $classArm?->label(),
                'house' => $student->house?->name,
                'class_teacher' => $classTeacher?->fullName(),
                'photo_url' => \App\Support\TenantAsset::url($student->passport_path),
            ],
            'term' => ['name' => $term->name, 'session' => $term->session?->name],
            'report_type' => $reportType,
            'component_columns' => $componentColumns,
            // Marks obtainable per component, for the grouped Cognitive Domain table's "Marks Obtainable" row.
            'component_max' => $reportComponents->mapWithKeys(fn ($c) => [$c->name => (float) $c->max_score])->all(),
            // This student's resolved grading scale (class, then section, then school default), highest band first, so the
            // Cognitive Domain's Overall Performance can show its own grade with the same scale the subject grades use.
            'grading_bands' => $scale ? $scale->bands->map(fn ($b) => ['grade' => $b->grade, 'min' => (float) $b->min_score, 'remark' => $b->remark])->values()->all() : [],
            // Every subject this class offers, scored or not, so the grouped
            // Cognitive Domain table lists the whole class list like the
            // school's paper sheet instead of only subjects with a score.
            // "mandatory": a subject is mandatory if the class list OR this
            // stream's own list says so. The grouped table always lists a
            // mandatory subject (blank until scored) but only lists an
            // optional one the student actually has a score for, so an SSS
            // student is not shown the subjects they never took.
            'offered_subjects' => $classArm
                ? (function () use ($classArm) {
                    $mandatory = [];
                    foreach ([$classArm->schoolClass?->subjects ?? collect(), $classArm->subjects] as $list) {
                        foreach ($list as $s) {
                            $mandatory[$s->id] = ($mandatory[$s->id] ?? false) || (bool) ($s->pivot->is_mandatory ?? true);
                        }
                    }

                    return $classArm->offeredSubjects()->map(fn ($s) => ['name' => $s->name, 'category' => $s->category, 'mandatory' => $mandatory[$s->id] ?? true])->values()->all();
                })()
                : [],
            'cumulative_mode' => $cumulativeMode,
            'subjects' => $subjectRows,
            'subject_comments' => $subjectComments,
            'attendance' => $attendance,
            'overall' => [
                'total' => $overallTotal,
                'average' => $overallAverage,
                'grade' => $overallGrade,
                'position' => $overallPosition,
                'class_size' => $classSize,
            ],
            'remarks' => [
                'overall' => $remark?->overall_remark,
                'class_teacher' => $remark?->class_teacher_remark,
                'principal' => $remark?->principal_remark,
                // A per-student date still wins; otherwise the term's own
                // "next term begins" date the admin sets with the term dates;
                // otherwise the start date of the next term if it exists.
                'next_term_begins_on' => ($remark?->next_term_begins_on ?? $term->next_term_begins_on ?? $this->nextTermStart($term))?->format('d M Y'),
            ],
            'physical' => [
                'height' => $remark?->height,
                'weight' => $remark?->weight,
                'height_start' => $remark?->height_start,
                'weight_start' => $remark?->weight_start,
                'tracks_start' => $this->prefs->tracksPhysicalStatsStartFor($classArm?->schoolClass),
                // The student's real assigned club wins; the old
                // free-text value typed on the term remark is the
                // fallback so report cards for terms recorded before
                // clubs existed still read exactly as they did.
                'club' => $student->club?->name ?: $remark?->club,
                'clubs_enabled' => (bool) \App\Models\Tenant\Setting::grab('clubs_enabled', false),
                'total_school_days' => $term->total_school_days,
                'times_absent' => $attendance['total'] - $attendance['present'],
                'class_teacher_name' => $classTeacher?->fullName(),
                'class_teacher_phone' => $classTeacher?->phone,
            ],
            'traits' => $traits,
            'narrative_style' => $classArm?->schoolClass?->resolvedNarrativeStyle() ?? 'comments',
            'skill_areas' => $skillAreas,
            'logo_url' => \App\Support\TenantAsset::url($logoPath),
            'watermark_url' => \App\Support\TenantAsset::url($watermarkPath),
            'watermark_text' => $watermarkText,
            'stamp_url' => \App\Support\TenantAsset::url($stampPath),
            'signature_url' => \App\Support\TenantAsset::url($signaturePath),
            // Who the signature under the signoff belongs to (user
            // request 2026-07-27): the student's own section's signer,
            // defaulting to Head Teacher/Principal, admin-overridable
            // per section under Report Settings.
            'signer_name' => ($classArm?->schoolClass?->section)?->signerLabel() ?? 'Head Teacher',
            'trend' => $this->overallTrend($subjectRows, $term->name, $overallAverage),
        ];
    }

    /**
     * This one student's own overall average, one point per term
     * (Score Trend Chart, user request 2026-07-19: "add option to add
     * text, charts etc to the report card"), averaged ACROSS subjects
     * for each term name every subject's own previous_terms already
     * carries, then the current term's own already-computed overall
     * average as the final point. Term order follows whichever subject
     * happened to have the most terms of history, since every subject
     * in the same student/term is built from the same $priorTerms
     * sequence in forStudent() and should agree, this only guards
     * against an edge case where one subject's Score rows genuinely
     * start later than another's.
     *
     * @param array $subjectRows same shape forStudent() builds above
     * @return array<string, float> term name => average, in term order
     */
    protected function overallTrend(array $subjectRows, string $currentTermName, float $currentOverallAverage): array
    {
        $longest = collect($subjectRows)->sortByDesc(fn ($s) => count($s['previous_terms']))->first();
        $termNames = $longest ? array_keys($longest['previous_terms']) : [];

        $trend = [];
        foreach ($termNames as $termName) {
            $totals = collect($subjectRows)->pluck("previous_terms.{$termName}")->filter(fn ($v) => $v !== null);
            if ($totals->isNotEmpty()) {
                $trend[$termName] = round($totals->avg(), 1);
            }
        }
        $trend[$currentTermName] = $currentOverallAverage;

        return $trend;
    }

    /** When the next term (in date order, across sessions) starts, if it has been created with a start date. */
    protected function nextTermStart(Term $term): ?\Carbon\CarbonInterface
    {
        $after = $term->ends_on ?? $term->starts_on;

        return $after
            ? Term::whereNotNull('starts_on')->where('starts_on', '>', $after->toDateString())->orderBy('starts_on')->first()?->starts_on
            : null;
    }

    /**
     * Static illustrative data, shaped identically to forStudent(), used
     * only so an admin (or, since 2026-09-21, a class teacher previewing
     * their own class) can see what a template/field combination will
     * actually look like before any real scores exist. Given a real
     * $class, this reflects that class's OWN resolved report card look
     * (template/narrative style/watermark, falling through its section
     * to the school default exactly like forStudent() does for a real
     * student) rather than the generic illustrative defaults below --
     * see ReportCardController::previewClass().
     */
    public function sample(?\App\Models\Tenant\SchoolClass $class = null): array
    {
        $section = $class?->section;
        $narrativeStyle = $class?->resolvedNarrativeStyle() ?? 'comments';
        $watermarkOn = $class?->resolvedWatermarkEnabled() ?? false;
        $school = $this->tenants->current();
        $logoPath = ($section && $school ? $section->resolvedLogoPath($school) : null) ?: $school?->logo_path;
        $logoUrl = \App\Support\TenantAsset::url($logoPath);
        $watermark = $watermarkOn ? $section?->resolvedWatermark($school) : null;
        $watermarkUrl = $watermark && $watermark['type'] === 'image' ? \App\Support\TenantAsset::url($watermark['value']) : null;
        $watermarkText = $watermark && $watermark['type'] === 'text' ? $watermark['value'] : null;

        // The school's own real current term/session (user directive
        // 2026-09-21: "the preview should match the current term"),
        // never the old hardcoded "Third Term 2025/2026" illustrative
        // value -- falls back to that same illustrative label only for
        // a school with no term set up at all yet (a genuinely fresh,
        // unconfigured school), so the preview never shows a blank term.
        $currentTerm = Term::current()->with('session')->first();
        $termName = $currentTerm?->name ?? 'Third Term';
        $termSession = $currentTerm?->session?->name ?? '2025/2026';

        // Every configured Key Assessment Area shows a genuinely
        // illustrative sample remark (user directive 2026-09-22: "for
        // the preview sample, add sample CLASS TEACHER'S REMARK too" --
        // a direct reversal of the earlier "Not yet entered" choice,
        // this screen is already plainly marked "(sample)" throughout,
        // so a filled-out preview reads as intended rather than as an
        // empty, not-yet-scored real report). Deliberately generic, not
        // tailored to any one skill's real name (a school's own skill
        // catalog varies), cycling through a short list so the preview
        // does not read as one remark copy-pasted down the whole table.
        $skillAreas = [];
        if ($narrativeStyle === 'skills') {
            $sampleSkillRemarks = [
                'Shows strong progress in this area.',
                'Doing well, with growing confidence.',
                'Needs a little more practice here.',
                'Excellent effort and understanding.',
            ];
            $i = 0;
            foreach (\App\Models\Tenant\ReportCardSkillArea::active()->orderBy('sequence')->get() as $area) {
                $skillAreas[$area->category][] = ['name' => $area->name, 'remark' => $sampleSkillRemarks[$i++ % count($sampleSkillRemarks)]];
            }
        }

        // A class that has its own subject list previews with THAT list,
        // real subject names and groups, the school's real assessment
        // columns, and sample scores (so the grouped Cognitive Domain
        // table can be checked before any real score exists). A class
        // with no list keeps the four generic illustrative rows below.
        $sampleSubjects = null;
        $sampleComponents = [['name' => 'First CA', 'max' => 20.0], ['name' => 'Second CA', 'max' => 20.0], ['name' => 'Exam', 'max' => 60.0]];
        $offered = $class ? $class->subjects()->get() : collect();
        if ($offered->isNotEmpty()) {
            $real = $currentTerm ? AssessmentComponent::forReportType($currentTerm, ReportCardReview::REPORT_TYPE_FULL_TERM) : collect();
            if ($real->isNotEmpty()) {
                $sampleComponents = $real->map(fn ($c) => ['name' => $c->name, 'max' => (float) $c->max_score])->values()->all();
            }
            $maxTotal = max(1.0, array_sum(array_column($sampleComponents, 'max')));
            $sampleSubjects = [];
            // A class with optional subjects (SSS) previews like one real
            // student: every mandatory subject plus the first few optional
            // ones, not all 25 at once.
            $optionalTaken = 0;
            $previewed = $offered->values()->filter(function ($s) use (&$optionalTaken) {
                return (bool) ($s->pivot->is_mandatory ?? true) || $optionalTaken++ < 8;
            })->values();
            foreach ($previewed as $i => $subject) {
                $parts = [];
                foreach ($sampleComponents as $ci => $c) {
                    $parts[$c['name']] = (int) round($c['max'] * (0.55 + (($i * 7 + $ci * 3) % 40) / 100));
                }
                $total = (int) round(array_sum($parts) / $maxTotal * 100);
                $grade = $total >= 70 ? 'A' : ($total >= 60 ? 'B' : ($total >= 50 ? 'C' : ($total >= 40 ? 'D' : 'F')));
                $sampleSubjects[] = [
                    'name' => $subject->name, 'category' => $subject->category, 'components' => $parts, 'total' => $total, 'grade' => $grade,
                    'remark' => ['A' => 'Excellent', 'B' => 'Very Good', 'C' => 'Good', 'D' => 'Fair', 'F' => 'Poor'][$grade],
                    'position' => 1 + ($i % 5), 'class_average' => round($total - 8 + ($i * 3 % 10), 1), 'highest' => min(100, $total + 6), 'lowest' => max(0, $total - 35),
                    'previous_terms' => [], 'cumulative_average' => null,
                ];
            }
        }
        $sampleTotal = $sampleSubjects ? array_sum(array_column($sampleSubjects, 'total')) : null;
        $realTraits = BehaviouralTrait::active()->whereNotNull('domain')->orderBy('sequence')->get();
        $sampleTraits = $realTraits->isNotEmpty()
            ? $realTraits->values()->map(fn ($t, $i) => ['name' => $t->name, 'type' => $t->type, 'domain' => $t->domain, 'value' => $t->type === 'score' ? 4 : ['Excellent', 'Very Good', 'Good'][$i % 3]])->all()
            : null;

        return [
            'identity' => [
                'name' => 'Ada Oluwaseyi Adamu (sample)', 'admission_number' => 'ADM/2025/014', 'gender' => 'Female',
                'date_of_birth' => '12 Mar 2013', 'age' => 13, 'class' => $class?->name ?? 'JSS 2 A', 'house' => 'Red House',
                'class_teacher' => 'Mrs. F. Adigun', 'photo_url' => null,
            ],
            'term' => ['name' => $termName, 'session' => $termSession],
            'report_type' => ReportCardReview::REPORT_TYPE_FULL_TERM,
            'component_columns' => $sampleSubjects ? array_column($sampleComponents, 'name') : ['First CA', 'Second CA', 'Exam'],
            'component_max' => $sampleSubjects ? array_column($sampleComponents, 'max', 'name') : ['First CA' => 20.0, 'Second CA' => 20.0, 'Exam' => 60.0],
            // The class's own scale when it has one; otherwise a plain illustrative A to F so the preview still shows an overall grade.
            'grading_bands' => (function () use ($class) {
                $scale = $class?->gradingScale ?? $class?->section?->gradingScale ?? \App\Models\Tenant\GradingScale::default();
                $bands = $scale ? $scale->bands->map(fn ($b) => ['grade' => $b->grade, 'min' => (float) $b->min_score, 'remark' => $b->remark])->values()->all() : [];

                return $bands ?: [
                    ['grade' => 'A', 'min' => 70.0, 'remark' => 'Excellent'], ['grade' => 'B', 'min' => 60.0, 'remark' => 'Very Good'],
                    ['grade' => 'C', 'min' => 50.0, 'remark' => 'Good'], ['grade' => 'D', 'min' => 40.0, 'remark' => 'Fair'], ['grade' => 'F', 'min' => 0.0, 'remark' => 'Poor'],
                ];
            })(),
            // No subject list on this class (e.g. the Layout Designer's generic preview): group the four illustrative rows so the grouped section still shows something full.
            'offered_subjects' => $offered->isNotEmpty()
                ? $offered->map(fn ($s) => ['name' => $s->name, 'category' => $s->category, 'mandatory' => (bool) ($s->pivot->is_mandatory ?? true)])->values()->all()
                : [
                    ['name' => 'English Studies', 'category' => 'Languages'],
                    ['name' => 'Mathematics', 'category' => 'Maths & Sciences'],
                    ['name' => 'Basic Science', 'category' => 'Maths & Sciences'],
                    ['name' => 'Civic Education', 'category' => 'Arts & Culture'],
                ],
            'cumulative_mode' => $sampleSubjects ? ReportCardCumulativePolicy::MODE_OFF : ReportCardCumulativePolicy::MODE_AVERAGE,
            'subjects' => $sampleSubjects ?? [
                ['name' => 'Mathematics', 'components' => ['First CA' => 18, 'Second CA' => 17, 'Exam' => 50], 'total' => 85, 'grade' => 'A', 'remark' => 'Distinction', 'position' => 1, 'class_average' => 68.4, 'highest' => 92, 'lowest' => 31, 'previous_terms' => ['First Term' => 78.0, 'Second Term' => 81.0], 'cumulative_average' => 81.33],
                ['name' => 'English Studies', 'components' => ['First CA' => 15, 'Second CA' => 16, 'Exam' => 48], 'total' => 79, 'grade' => 'B', 'remark' => 'Upper Credit / Very Good', 'position' => 3, 'class_average' => 65.1, 'highest' => 88, 'lowest' => 28, 'previous_terms' => ['First Term' => 74.0, 'Second Term' => 76.0], 'cumulative_average' => 76.33],
                ['name' => 'Basic Science', 'components' => ['First CA' => 17, 'Second CA' => 18, 'Exam' => 45], 'total' => 80, 'grade' => 'A', 'remark' => 'Distinction', 'position' => 2, 'class_average' => 60.7, 'highest' => 90, 'lowest' => 22, 'previous_terms' => ['First Term' => 70.0, 'Second Term' => 75.0], 'cumulative_average' => 75.0],
                ['name' => 'Civic Education', 'components' => ['First CA' => 14, 'Second CA' => 15, 'Exam' => 40], 'total' => 69, 'grade' => 'B', 'remark' => 'Upper Credit / Very Good', 'position' => 5, 'class_average' => 62.0, 'highest' => 85, 'lowest' => 30, 'previous_terms' => ['First Term' => 65.0, 'Second Term' => 67.0], 'cumulative_average' => 67.0],
            ],
            'attendance' => ['present' => 58, 'total' => 62],
            'overall' => $sampleSubjects
                ? ['total' => $sampleTotal, 'average' => round($sampleTotal / count($sampleSubjects), 2), 'grade' => 'B', 'position' => 2, 'class_size' => 28]
                : ['total' => 313, 'average' => 78.25, 'grade' => 'A', 'position' => 2, 'class_size' => 28],
            'remarks' => [
                'overall' => 'A good performance this term, keep it up.',
                'class_teacher' => 'A hardworking and consistent student this term. Keep it up.',
                'principal' => 'Impressive result. Well done.',
                'next_term_begins_on' => $currentTerm?->next_term_begins_on?->format('d M Y') ?? '08 Sep 2026',
            ],
            'subject_comments' => [
                'Mathematics' => 'Ada shows strong number sense and works confidently with little guidance.',
                'English Studies' => 'Reads with good expression. Should practice writing longer sentences.',
            ],
            'physical' => [
                'height' => '148cm', 'weight' => '38kg', 'height_start' => '145cm', 'weight_start' => '36kg',
                'tracks_start' => $this->prefs->tracksPhysicalStatsStartFor($class), 'club' => 'Debate Club',
                'total_school_days' => 62, 'times_absent' => 4,
                'class_teacher_name' => 'Mrs. F. Adigun', 'class_teacher_phone' => '0803 123 4567',
            ],
            'traits' => $sampleTraits ?? [
                ['name' => 'Punctuality', 'type' => 'remark', 'domain' => 'affective', 'value' => 'Excellent'],
                ['name' => 'Neatness', 'type' => 'remark', 'domain' => 'affective', 'value' => 'Very Good'],
                ['name' => 'Honesty', 'type' => 'remark', 'domain' => 'affective', 'value' => 'Excellent'],
                ['name' => 'Cooperation', 'type' => 'remark', 'domain' => 'affective', 'value' => 'Good'],
                ['name' => 'Handling of Tools', 'type' => 'score', 'domain' => 'psychomotor', 'value' => 4],
                ['name' => 'Sports', 'type' => 'score', 'domain' => 'psychomotor', 'value' => 5],
            ],
            'narrative_style' => $narrativeStyle,
            'skill_areas' => $skillAreas,
            'logo_url' => $logoUrl,
            'stamp_url' => null,
            'watermark_url' => $watermarkUrl,
            'watermark_text' => $watermarkText,
            'signature_url' => null,
            'signer_name' => $section?->signerLabel() ?? 'Head Teacher',
            'trend' => ['First Term' => 74.3, 'Second Term' => 76.75, 'Third Term' => 78.25],
        ];
    }
}
