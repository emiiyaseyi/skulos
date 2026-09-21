<?php

namespace App\Console\Commands;

use App\Models\School;
use App\Models\Tenant\ClassNarrativeTerm;
use App\Models\Tenant\ReportCardSkillArea;
use App\Models\Tenant\SchoolClass;
use App\Models\Tenant\SchoolSectionBranding;
use App\Models\Tenant\Section;
use App\Models\Tenant\Setting;
use App\Models\Tenant\Term;
use App\Services\Tenancy\TenantManager;
use App\Support\TermLabel;
use Illuminate\Console\Command;

/**
 * The general, any-school version of what SetupPpmsSectionReportCard
 * applied to Potter's Prowess Model School (user directive 2026-09-21,
 * asked again for greenfield.skulosapp.com): turns a school's own
 * Nursery/Early Years section over to the "Key Assessment Areas"
 * narrative report card style, with an optional term-wording change.
 * Deliberately does NOT touch a school's own address/phone/website --
 * that was real PPMS-specific contact data, not part of "the feature",
 * so it has no place in a command meant to be run against any school.
 *
 * Idempotent (firstOrCreate/updateOrCreate/plain str_replace rename
 * throughout), safe to re-run.
 */
class EnableSkillsNarrativeSection extends Command
{
    protected $signature = 'report-card:enable-skills-section
        {slug : The school slug to apply this to}
        {--section= : Exact section name, auto-detected if omitted}
        {--class= : Exact class name, auto-detected if omitted}
        {--term-label= : If given, renames the current session\'s terms and sets the school\'s own "Term" wording (e.g. Trimester); omitted leaves term wording untouched}
        {--no-seed-catalog : Skip seeding config(\'skulos.default_report_card_skill_areas\') -- use when the school already has, or wants to type, its own Key Assessment Areas list}';

    protected $description = 'Turns a school\'s own Nursery/Early Years section over to the Key Assessment Areas narrative report card style';

    public function handle(TenantManager $tenants): int
    {
        $school = School::where('slug', $this->argument('slug'))->first();
        if (! $school) {
            $this->error("No school found with slug \"{$this->argument('slug')}\".");

            return self::FAILURE;
        }

        return $tenants->connectTo($school, function () use ($school) {
            $currentTerm = Term::current()->with('session')->first();

            if ($termLabel = $this->option('term-label')) {
                Setting::put('term_label', $termLabel);
                $this->info("Term wording set to \"{$termLabel}\".");

                if ($currentTerm?->session) {
                    $renamed = 0;
                    foreach (Term::where('academic_session_id', $currentTerm->session->id)->get() as $term) {
                        $newName = str_replace(' Term ', ' '.TermLabel::current().' ', $term->name);
                        if ($newName !== $term->name) {
                            $term->update(['name' => $newName]);
                            $renamed++;
                        }
                    }
                    $this->info("Renamed {$renamed} term(s) in {$currentTerm->session->name} to use \"{$termLabel}\".");
                } else {
                    $this->warn('No current term/session found, skipped renaming terms.');
                }
            }

            $sectionName = $this->option('section');
            $section = $sectionName
                ? Section::where('name', $sectionName)->first()
                : Section::where('name', 'like', '%nursery%')
                    ->orWhere('name', 'like', '%kindergarten%')
                    ->orWhere('name', 'like', '%creche%')
                    ->orWhere('name', 'like', '%reception%')
                    ->first();

            if (! $section) {
                $this->error('Could not find a Nursery/Early Years section. Known sections: '.Section::pluck('name')->implode(', ').'. Re-run with --section="Exact Name".');

                return self::FAILURE;
            }
            $this->info("Using section \"{$section->name}\".");

            $className = $this->option('class');
            $class = $className
                ? SchoolClass::where('section_id', $section->id)->where('name', $className)->first()
                : SchoolClass::where('section_id', $section->id)
                    ->where(fn ($q) => $q->where('name', 'like', '%kg%1%')
                        ->orWhere('name', 'like', '%kg%one%')
                        ->orWhere('name', 'like', '%kindergarten 1%')
                        ->orWhere('name', 'like', '%creche%')
                        ->orWhere('name', 'like', '%reception%'))
                    ->first();

            if (! $class) {
                $this->warn('Could not confidently identify a class to switch into Narrative Evaluation (known classes under this section: '.
                    SchoolClass::where('section_id', $section->id)->pluck('name')->implode(', ').
                    '). Skipping the Narrative Evaluation toggle -- turn it on manually from Academic > Narrative Evaluation for the right class; the rest of this setup still applies to the section.');
            } elseif ($currentTerm) {
                ClassNarrativeTerm::firstOrCreate(['school_class_id' => $class->id, 'term_id' => $currentTerm->id]);
                $this->info("Narrative Evaluation turned on for \"{$class->name}\", {$currentTerm->name}.");
            } else {
                $this->warn('No current term found, skipped the Narrative Evaluation toggle.');
            }

            SchoolSectionBranding::updateOrCreate(
                ['section_id' => $section->id],
                ['narrative_style' => 'skills', 'watermark_enabled' => true]
            );
            $this->info("Section \"{$section->name}\" set to the Key Assessment Areas narrative style, watermark on.");

            if (! $this->option('no-seed-catalog')) {
                $sequence = ReportCardSkillArea::max('sequence') ?? 0;
                $created = 0;
                foreach (config('skulos.default_report_card_skill_areas', []) as $category => $skills) {
                    foreach ($skills as $skill) {
                        $sequence++;
                        $area = ReportCardSkillArea::firstOrCreate(
                            ['category' => $category, 'name' => $skill],
                            ['sequence' => $sequence, 'is_active' => true]
                        );
                        if ($area->wasRecentlyCreated) {
                            $created++;
                        }
                    }
                }
                $this->info("{$created} Key Assessment Area(s) created from the starter catalog (already-existing entries left untouched).");
            }

            $this->info('Done. This applied only to '.$school->name.'; every other school is unaffected.');

            return self::SUCCESS;
        });
    }
}
