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

    public function handle(TenantManager $tenants): int
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

        return $tenants->connectTo($school, function () use ($school) {
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

            $className = $this->option('class');
            $class = $className
                ? SchoolClass::where('section_id', $section->id)->where('name', $className)->first()
                : SchoolClass::where('section_id', $section->id)
                    ->where(fn ($q) => $q->where('name', 'like', '%kg%1%')->orWhere('name', 'like', '%kg%one%')->orWhere('name', 'like', '%kindergarten 1%'))
                    ->first();

            if (! $class) {
                $this->warn('Could not confidently identify the KG One class under this section (known classes: '.
                    SchoolClass::where('section_id', $section->id)->pluck('name')->implode(', ').
                    '). Skipping Narrative Evaluation toggle -- turn it on manually from Academic > Narrative Evaluation for the right class, the rest of this setup still applies to the whole section.');
            } elseif ($currentTerm) {
                ClassNarrativeTerm::firstOrCreate(['school_class_id' => $class->id, 'term_id' => $currentTerm->id]);
                $this->info("Narrative Evaluation turned on for \"{$class->name}\", {$currentTerm->name}.");
            }

            // 4. Section report card look: Key Assessment Areas style, watermark on.
            SchoolSectionBranding::updateOrCreate(
                ['section_id' => $section->id],
                ['narrative_style' => 'skills', 'watermark_enabled' => true]
            );
            $this->info("Section \"{$section->name}\" set to the Key Assessment Areas narrative style, watermark on.");

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
