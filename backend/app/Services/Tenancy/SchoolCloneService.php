<?php

namespace App\Services\Tenancy;

use App\Models\School;
use App\Models\Tenant\Arm;
use App\Models\Tenant\AssessmentComponent;
use App\Models\Tenant\ClassArm;
use App\Models\Tenant\GradingBand;
use App\Models\Tenant\GradingScale;
use App\Models\Tenant\House;
use App\Models\Tenant\SchoolClass;
use App\Models\Tenant\Section;
use App\Models\Tenant\Setting;
use App\Models\Tenant\Subject;

/**
 * Clone a school's structural setup onto a freshly provisioned school (user
 * request 2026-07-09): sections/classes/arms/subjects (with their mandatory/
 * examinable flags), grading scales, CA components, houses, and look and
 * feel settings (dashboard/report card/branding). Deliberately does NOT
 * copy student, staff, parent, score, or attendance data — cloning is a
 * structure starting point for a new school, not a data duplicate.
 */
class SchoolCloneService
{
    public function __construct(protected TenantManager $tenants)
    {
    }

    public function clone(School $source, School $destination): void
    {
        $data = $this->readSource($source);
        $this->writeDestination($destination, $data);
        $this->copyBranding($source, $destination);
    }

    protected function readSource(School $source): array
    {
        $this->tenants->use($source);

        $data = [
            'sections' => Section::orderBy('sequence')->get(['id', 'name', 'sequence'])->toArray(),
            'classes' => SchoolClass::orderBy('sequence')->get(['id', 'section_id', 'name', 'sequence'])->toArray(),
            'arms' => Arm::get(['id', 'name'])->toArray(),
            'class_arms' => ClassArm::get(['id', 'school_class_id', 'arm_id', 'capacity'])->toArray(),
            'subjects' => Subject::get(['id', 'name', 'code'])->toArray(),
            'class_subject' => \Illuminate\Support\Facades\DB::connection('tenant')
                ->table('class_subject')->get(['school_class_id', 'subject_id', 'is_mandatory', 'is_examinable'])->toArray(),
            'grading_scales' => GradingScale::get(['id', 'name', 'is_default'])->toArray(),
            'grading_bands' => GradingBand::get(['id', 'grading_scale_id', 'min_score', 'max_score', 'grade', 'remark', 'points'])->toArray(),
            'assessment_components' => AssessmentComponent::get(['id', 'name', 'max_score', 'sequence'])->toArray(),
            'houses' => House::get(['id', 'name', 'color'])->toArray(),
            'dashboard_layout' => Setting::grab('dashboard_layout'),
            'dashboard_widgets' => Setting::grab('dashboard_widgets'),
            'report_card_template' => Setting::grab('report_card_template'),
            'report_card_fields' => Setting::grab('report_card_fields'),
        ];

        $this->tenants->forget();

        return $data;
    }

    protected function writeDestination(School $destination, array $data): void
    {
        $this->tenants->use($destination);

        $sectionIdMap = [];
        foreach ($data['sections'] as $row) {
            $new = Section::create(['name' => $row['name'], 'sequence' => $row['sequence']]);
            $sectionIdMap[$row['id']] = $new->id;
        }

        $classIdMap = [];
        foreach ($data['classes'] as $row) {
            $new = SchoolClass::create([
                'section_id' => $sectionIdMap[$row['section_id']] ?? null,
                'name' => $row['name'],
                'sequence' => $row['sequence'],
            ]);
            $classIdMap[$row['id']] = $new->id;
        }

        $armIdMap = [];
        foreach ($data['arms'] as $row) {
            $new = Arm::firstOrCreate(['name' => $row['name']]);
            $armIdMap[$row['id']] = $new->id;
        }

        $classArmIdMap = [];
        foreach ($data['class_arms'] as $row) {
            if (! isset($classIdMap[$row['school_class_id']])) {
                continue;
            }
            $new = ClassArm::create([
                'school_class_id' => $classIdMap[$row['school_class_id']],
                'arm_id' => $row['arm_id'] ? ($armIdMap[$row['arm_id']] ?? null) : null,
                'capacity' => $row['capacity'],
            ]);
            $classArmIdMap[$row['id']] = $new->id;
        }

        $subjectIdMap = [];
        foreach ($data['subjects'] as $row) {
            $new = Subject::firstOrCreate(['name' => $row['name']], ['code' => $row['code']]);
            $subjectIdMap[$row['id']] = $new->id;
        }

        foreach ($data['class_subject'] as $row) {
            $row = (array) $row;
            if (! isset($classIdMap[$row['school_class_id']], $subjectIdMap[$row['subject_id']])) {
                continue;
            }
            \Illuminate\Support\Facades\DB::connection('tenant')->table('class_subject')->insert([
                'school_class_id' => $classIdMap[$row['school_class_id']],
                'subject_id' => $subjectIdMap[$row['subject_id']],
                'is_mandatory' => $row['is_mandatory'],
                'is_examinable' => $row['is_examinable'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $scaleIdMap = [];
        foreach ($data['grading_scales'] as $row) {
            $new = GradingScale::create(['name' => $row['name'], 'is_default' => $row['is_default']]);
            $scaleIdMap[$row['id']] = $new->id;
        }

        foreach ($data['grading_bands'] as $row) {
            if (! isset($scaleIdMap[$row['grading_scale_id']])) {
                continue;
            }
            GradingBand::create([
                'grading_scale_id' => $scaleIdMap[$row['grading_scale_id']],
                'min_score' => $row['min_score'],
                'max_score' => $row['max_score'],
                'grade' => $row['grade'],
                'remark' => $row['remark'],
                'points' => $row['points'],
            ]);
        }

        foreach ($data['assessment_components'] as $row) {
            AssessmentComponent::firstOrCreate(
                ['name' => $row['name']],
                ['max_score' => $row['max_score'], 'sequence' => $row['sequence']]
            );
        }

        foreach ($data['houses'] as $row) {
            House::firstOrCreate(['name' => $row['name']], ['color' => $row['color']]);
        }

        if ($data['dashboard_layout']) {
            Setting::put('dashboard_layout', $data['dashboard_layout']);
        }
        if ($data['dashboard_widgets']) {
            Setting::put('dashboard_widgets', $data['dashboard_widgets']);
        }
        if ($data['report_card_template']) {
            Setting::put('report_card_template', $data['report_card_template']);
        }
        if ($data['report_card_fields']) {
            Setting::put('report_card_fields', $data['report_card_fields']);
        }

        $this->tenants->forget();
    }

    protected function copyBranding(School $source, School $destination): void
    {
        $destination->update([
            'brand_color' => $source->brand_color,
            'login_layout' => $source->login_layout,
            'login_box_side' => $source->login_box_side,
        ]);
    }
}
