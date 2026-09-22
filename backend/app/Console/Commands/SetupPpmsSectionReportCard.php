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
use App\Services\ReportCards\ReportCardLayout;
use App\Services\Tenancy\TenantManager;
use App\Support\TermLabel;
use Illuminate\Console\Command;

/**
 * A one-off, idempotent, this-school-only application of the general
 * per-section report card / watermark / term-label features (user
 * directive 2026-09-21) to Potter's Prowess Model School (skulosapp.com
 * slug "ppms"). Nothing here is a platform default -- every other school
 * is unaffected unless it deliberately opts into the same features
 * itself, the same as any other config change this codebase makes.
 *
 * Safe to re-run: every write below is a firstOrCreate/updateOrCreate or
 * a plain idempotent rename (skipped once already applied).
 */
class SetupPpmsSectionReportCard extends Command
{
    protected $signature = 'ppms:setup-report-card
        {--slug=ppms : The school slug to apply this to}
        {--section= : Exact section name, auto-detected if omitted}
        {--class= : Exact class name, auto-detected if omitted}';

    protected $description = "Applies PPMS's own Nursery/Kindergarten report card design (real school details, Key Assessment Areas, watermark, Trimester wording) using the general per-section report card features";

    public function handle(TenantManager $tenants, ReportCardLayout $layout): int
    {
        $school = School::where('slug', $this->option('slug'))->first();
        if (! $school) {
            $this->error("No school found with slug \"{$this->option('slug')}\".");

            return self::FAILURE;
        }

        // 1. Real school header details (user directive 2026-09-21).
        $school->address = 'ALONG LUFUWAPE OTERE ROAD, LUFUWAPE TOWN, OGUN STATE, NIGERIA.';
        $school->phone = '08122482932';
        $school->alternate_phone = '08166448805';
        $school->website = 'https://www.pottersprowess.com';
        $school->save();
        $this->info('School address/phone/website saved.');

        return $tenants->connectTo($school, function () use ($school, $layout) {
            // 2. Term wording: "Trimester" (user directive 2026-09-21).
            Setting::put('term_label', 'Trimester');
            $this->info('Term wording set to "Trimester".');

            // Rename every term in the CURRENT session from "...Term..."
            // to "...Trimester...", matching the reference report card's
            // own "THIRD TRIMESTER" wording. A plain str_replace, so a
            // term already renamed (re-running this command) is a no-op.
            $currentTerm = Term::current()->with('session')->first();
            if ($currentTerm?->session) {
                $renamed = 0;
                foreach (Term::where('academic_session_id', $currentTerm->session->id)->get() as $term) {
                    $newName = str_replace(' Term ', ' '.TermLabel::current().' ', $term->name);
                    if ($newName !== $term->name) {
                        $term->update(['name' => $newName]);
                        $renamed++;
                    }
                }
                $this->info("Renamed {$renamed} term(s) in {$currentTerm->session->name} to use \"Trimester\".");
            } else {
                $this->warn('No current term/session found, skipped renaming terms.');
            }

            // 3. Locate the Nursery/Kindergarten section and its KG One class.
            $sectionName = $this->option('section');
            $section = $sectionName
                ? Section::where('name', $sectionName)->first()
                : Section::where('name', 'like', '%nursery%')->orWhere('name', 'like', '%kindergarten%')->first();

            if (! $section) {
                $this->error('Could not find a Nursery/Kindergarten section. Known sections: '.Section::pluck('name')->implode(', ').'. Re-run with --section="Exact Name".');

                return self::FAILURE;
            }
            $this->info("Using section \"{$section->name}\".");

            // Every class in the section, not just one auto-detected
            // "KG One" pattern (user directive 2026-09-21, live: "the
            // creche, reception and KG classes of nursery does not use
            // student image on the report card" -- confirmed via
            // AskUserQuestion that ALL of them, not just KG 1, should be
            // in the same narrative Key Assessment Areas style). --class
            // still narrows to exactly one, for a section that genuinely
            // mixes narrative and scored classes.
            $className = $this->option('class');
            $classes = $className
                ? SchoolClass::where('section_id', $section->id)->where('name', $className)->get()
                : SchoolClass::where('section_id', $section->id)->get();

            if ($classes->isEmpty()) {
                $this->warn('No classes found under this section (known classes: '.
                    SchoolClass::where('section_id', $section->id)->pluck('name')->implode(', ').
                    '). Skipping the Narrative Evaluation toggle -- turn it on manually from Academic > Narrative Evaluation for the right class(es), the rest of this setup still applies to the section.');
            } elseif ($currentTerm) {
                $toggled = 0;
                foreach ($classes as $class) {
                    ClassNarrativeTerm::firstOrCreate(['school_class_id' => $class->id, 'term_id' => $currentTerm->id]);
                    $toggled++;
                }
                $this->info("Narrative Evaluation turned on for {$toggled} class(es) under \"{$section->name}\" (".$classes->pluck('name')->implode(', ')."), {$currentTerm->name}.");
            } else {
                $this->warn('No current term found, skipped the Narrative Evaluation toggle.');
            }

            // 4. Section report card look: Key Assessment Areas style,
            // watermark on, Beginning/End of Term physical tracking on,
            // Physical & Attendance and Stamp/Signature fields on (user
            // directive 2026-09-22, confirmed via AskUserQuestion: "Make
            // it/these per-section too" -- so none of this also turns
            // on for Primary/Secondary's own report cards).
            SchoolSectionBranding::updateOrCreate(
                ['section_id' => $section->id],
                [
                    'narrative_style' => 'skills', 'watermark_enabled' => true, 'tracks_physical_stats_start' => true,
                    'field_overrides' => ['physical_stats' => true, 'stamp_signature' => true],
                ]
            );
            $this->info("Section \"{$section->name}\" set to the Key Assessment Areas narrative style, watermark on, Beginning/End of Term tracking on, Physical & Attendance and Stamp/Signature fields on.");

            // The reference report card folds the stamp INTO the same
            // row as "Trimester Begins" rather than showing it as its
            // own separate block below (user reference report card,
            // 2026-09-22: "THIRD TRIMESTER BEGINS... SCHOOL STAMP", one
            // bordered row). _body-remarks.blade.php only renders the
            // stamp inline when 'signoff' is NOT also in this section's
            // own body order, so this section's own layout override
            // deliberately omits it -- enabled stays false so the
            // header/identity area keeps rendering on its normal,
            // non-custom-layout path, only the body order is overridden.
            $layout->setSectionOverride($section, [
                'enabled' => false,
                'zones' => ['header' => [], 'info' => []],
                'body_order' => ['physical_stats', 'subject_comments', 'remarks'],
                'header_align' => [],
                'body_align' => [],
                'body_columns' => [],
            ]);
            $this->info("Section \"{$section->name}\" body order set so the stamp shows inline next to \"Trimester Begins\", matching the reference report card.");

            // 5. PPMS's own Key Assessment Areas catalog, straight from
            // their real report card. School specific data, not a
            // platform default -- another school opting into 'skills'
            // starts with an empty catalog of its own to fill in.
            $catalog = [
                'Literacy & Phonics' => ['Letter Recognition & Sound', 'Letter Writing Skills'],
                'Numeracy Concepts' => ['Number Recognition', 'Number Writing Skills'],
                'Creative Art/Rhymes & Vocational Skills' => ['Drawing & Painting Skills', 'Knowledge Of Rhymes', 'Knowledge Of Vocations'],
                'Moral & Character Development' => ['Respect for Others', 'Attentiveness & Obedience'],
                'Practical Life & Nature Skills' => ['Independence Practice', 'Identification of Objects'],
                'Writing Skills' => ['Pencil & Tools Handling'],
                'Physical & Health Habit Skills' => ['Participation & Focus'],
            ];
            $sequence = 0;
            $created = 0;
            foreach ($catalog as $category => $skills) {
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
            $this->info("{$created} Key Assessment Area(s) created (catalog has ".array_sum(array_map('count', $catalog)).' total).');

            $this->info('Done. This applied only to '.$school->name.'; every other school is unaffected.');

            return self::SUCCESS;
        });
    }
}
