<?php

namespace App\Console\Commands;

use App\Models\School;
use App\Models\Tenant\SchoolClass;
use App\Models\Tenant\Subject;
use App\Services\Tenancy\TenantManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A one-off, this-school-only subject list for Potter's Prowess Model School
 * (user directive 2026-09-24): Nursery, Basic 1 to 5, JSS 1 to 3 and SSS 1 to
 * 3 each get their own list. Nursery to JSS offer one shared list per class
 * (every student takes all of it, all mandatory). SSS students do NOT all
 * take the same subjects, so those classes only get the subjects made
 * available, with English and Mathematics mandatory and the rest optional;
 * the admin team decides what is mandatory and which stream offers what.
 *
 * Safe by design: it PLANS first and changes nothing without --apply, it
 * never deletes a subject (scores cascade-delete with a subject), a subject
 * that leaves a class is only detached from that class, and per-stream SSS
 * lists the admin already built are left alone.
 */
class SetupPpmsSubjects extends Command
{
    protected $signature = 'ppms:setup-subjects
        {--slug=ppms : The school slug}
        {--apply : Actually make the changes (without it this only prints the plan)}
        {--nursery= : Exact class names for the Nursery list, comma separated (default: names starting "Nursery 1" or "Nursery 2")}
        {--basic= : Exact class names for the Basic list (default: "Basic 1" to "Basic 5")}
        {--jss= : Exact class names for the JSS list (default: "JSS 1" to "JSS 3")}
        {--sss= : Exact class names for the SSS list (default: "SSS 1" to "SSS 3")}';

    protected $description = "Sets Potter's Prowess Model School's subject lists per class group (Nursery, Basic, JSS, SSS); prints a plan unless --apply is given";

    /** Spelling corrected from the supplied lists where they were plainly typos (Agricultural, Geography, Programming). */
    protected const LISTS = [
        'nursery' => [
            'ENGLISH LANGUAGE', 'PHONICS & VERBAL REASONING', 'PHONETICS', 'FRENCH LANGUAGE', 'YORUBA LANGUAGE', 'BASIC SCIENCE',
            'ICT', 'PHYSICAL & HEALTH EDUCATION', 'GENERAL & VOCATIONAL STUDIES', 'MATHEMATICS', 'RHYMES', 'QUANTITATIVE REASONING',
            'CIVICS & CULTURAL STUDIES', 'MUSIC & CREATIVE ARTS', 'HANDWRITING SKILL', 'MORALS', 'PRACTICAL LIFE',
        ],
        'basic' => [
            'ENGLISH LANGUAGE', 'PHONICS & VERBAL REASONING', 'PHONETICS', 'FRENCH LANGUAGE', 'YORUBA LANGUAGE', 'BASIC SCIENCE',
            'ICT', 'PHYSICAL & HEALTH EDUCATION', 'GENERAL & VOCATIONAL STUDIES', 'MATHEMATICS', 'QUANT/M. MATH', 'CIVICS & CULTURAL STUDIES',
            'MUSIC & CREATIVE ARTS', 'HAND WRITING SKILLS', 'CRS', 'MORAL & ETHICS STUDIES',
        ],
        'jss' => [
            'ENGLISH LANGUAGE', 'MATHEMATICS', 'BASIC SCIENCE', 'BASIC TECHNOLOGY', 'YORUBA LANGUAGE', 'CIVIC EDUCATION', 'SOCIAL STUDIES',
            'CULTURAL AND CREATIVE ART', 'PRE-VOCATIONAL STUDIES', 'ICT', 'CODING & ROBOTICS', 'BUSINESS STUDIES', 'CRS', 'HISTORY',
        ],
        'sss' => [
            'ENGLISH LANGUAGE', 'MATHEMATICS', 'AGRICULTURAL SCIENCE', 'DIGITAL TECHNOLOGY', 'CATERING AND CRAFT PRACTICE', 'BIOLOGY', 'CHEMISTRY',
            'CHRISTIAN RELIGIOUS STUDIES', 'FINANCIAL ACCOUNTING', 'FOOD AND NUTRITION', 'FURTHER MATHEMATICS', 'GEOGRAPHY', 'GOVERNMENT',
            'NIGERIAN HISTORY', 'BEAUTY AND COSMETOLOGY', 'CITIZENSHIP AND HERITAGE STUDIES', 'PHYSICS', 'SOLAR PHOTOVOLTAIC INSTALLATION',
            'TECHNICAL DRAWING', 'VISUAL ARTS', 'PROGRAMMING & ROBOTICS', 'COMMERCE', 'ECONOMICS', 'MARKETING', 'FASHION DESIGN AND GARMENT MAKING',
        ],
    ];

    /** The Basic report card's subject groups, in the order the school's own sheet shows them (used for the grouped Cognitive Domain table). */
    protected const BASIC_GROUPS = [
        'Languages' => ['ENGLISH LANGUAGE', 'PHONICS & VERBAL REASONING', 'PHONETICS', 'FRENCH LANGUAGE', 'YORUBA LANGUAGE'],
        'Maths & Sciences' => ['BASIC SCIENCE', 'ICT', 'PHYSICAL & HEALTH EDUCATION', 'GENERAL & VOCATIONAL STUDIES', 'MATHEMATICS', 'QUANT/M. MATH', 'CIVICS & CULTURAL STUDIES'],
        'Arts & Culture' => ['MUSIC & CREATIVE ARTS', 'HAND WRITING SKILLS', 'CRS', 'MORAL & ETHICS STUDIES'],
    ];

    protected const DEFAULT_MATCH = [
        'nursery' => '/^nursery\s*[12]$/i',
        'basic' => '/^basic\s*[1-5]$/i',
        'jss' => '/^jss\s*[1-3]$/i',
        'sss' => '/^sss\s*[1-3]$/i',
    ];

    public function handle(TenantManager $tenants): int
    {
        $school = School::where('slug', $this->option('slug'))->first();
        if (! $school) {
            $this->error("No school found with slug \"{$this->option('slug')}\".");

            return self::FAILURE;
        }
        $apply = (bool) $this->option('apply');

        return $tenants->connectTo($school, function () use ($apply) {
            $catalog = Subject::all()->keyBy(fn (Subject $s) => $this->key($s->name));
            $nextSequence = (int) Subject::max('sequence');
            $plans = [];
            $unmatched = [];

            foreach (SchoolClass::orderBy('section_id')->orderBy('sequence')->get() as $class) {
                $group = $this->groupFor($class->name);
                if (! $group) {
                    $unmatched[] = $class->name;

                    continue;
                }
                $plans[] = compact('class', 'group');
            }

            $this->info('Classes and the list they get:');
            $this->table(['Class', 'Subject list'], array_map(fn ($p) => [$p['class']->name, strtoupper($p['group'])], $plans));
            if ($unmatched) {
                $this->warn('Left alone (no list matched): '.implode(', ', $unmatched).'. If any of these should get a list, re-run with --nursery="Exact A,Exact B" (or --basic / --jss / --sss).');
            }

            $create = [];
            foreach ($plans as $p) {
                foreach (self::LISTS[$p['group']] as $raw) {
                    $k = $this->key($raw);
                    if (! $catalog->has($k) && ! isset($create[$k])) {
                        $create[$k] = $this->display($raw);
                    }
                }
            }
            $this->newLine();
            $this->info(count($create).' new subject(s) to create: '.($create ? implode(', ', $create) : 'none'));

            $rows = [];
            $work = [];
            foreach ($plans as $p) {
                $class = $p['class'];
                $wanted = collect(self::LISTS[$p['group']])->map(fn ($r) => $this->key($r))->all();
                $current = $class->subjects()->get();
                $currentKeys = $current->mapWithKeys(fn ($s) => [$this->key($s->name) => $s]);
                $toAttach = array_values(array_diff($wanted, $currentKeys->keys()->all()));
                $toDetach = $current->filter(fn ($s) => ! in_array($this->key($s->name), $wanted, true));
                $armIds = $class->arms()->pluck('id')->all();
                $withScores = $toDetach->filter(fn ($s) => $armIds && DB::connection('tenant')->table('scores')->whereIn('class_arm_id', $armIds)->where('subject_id', $s->id)->exists());
                $streamRows = $armIds ? DB::connection('tenant')->table('class_arm_subjects')->whereIn('class_arm_id', $armIds)->count() : 0;

                $rows[] = [
                    $class->name, count($wanted), count($toAttach), $toDetach->count(),
                    $withScores->isEmpty() ? '-' : $withScores->pluck('name')->implode(', '),
                    $streamRows ? "{$streamRows} stream row(s) kept" : '-',
                ];
                $work[] = compact('class', 'p', 'toAttach', 'toDetach');
            }
            $this->newLine();
            $this->table(['Class', 'Will offer', 'Add', 'Remove from class', 'Removed subjects that have scores (scores are kept)', 'Own stream lists'], $rows);

            if (! $apply) {
                $this->newLine();
                $this->warn('Nothing changed. This was only the plan. Check it, then run again with --apply.');

                return self::SUCCESS;
            }

            DB::connection('tenant')->transaction(function () use (&$catalog, &$nextSequence, $create, $work) {
                foreach ($create as $k => $name) {
                    $catalog[$k] = Subject::create(['name' => $name, 'sequence' => ++$nextSequence]);
                }
                foreach ($work as $w) {
                    $class = $w['class'];
                    $isSss = $w['p']['group'] === 'sss';
                    foreach ($w['toAttach'] as $k) {
                        // English and Mathematics are mandatory for everyone; the rest of SSS is optional until the admin team sets it.
                        $mandatory = ! $isSss || in_array($k, ['englishlanguage', 'mathematics'], true);
                        $class->subjects()->attach($catalog[$k]->id, ['is_mandatory' => $mandatory, 'is_examinable' => true]);
                    }
                    if ($w['toDetach']->isNotEmpty()) {
                        $class->subjects()->detach($w['toDetach']->pluck('id')->all());
                    }
                }

                // Basic subjects get their group label and the sheet's own order.
                // They keep the same set of sequence numbers they already
                // held (just reassigned in the sheet's order), so no other
                // subject list is shuffled around them.
                if (collect($work)->contains(fn ($w) => $w['p']['group'] === 'basic')) {
                    $ordered = [];
                    foreach (self::BASIC_GROUPS as $category => $names) {
                        foreach ($names as $raw) {
                            $ordered[] = [$category, $catalog[$this->key($raw)]];
                        }
                    }
                    $slots = collect($ordered)->map(fn ($o) => (int) $o[1]->sequence)->sort()->values();
                    foreach ($ordered as $i => [$category, $subject]) {
                        $subject->update(['category' => $category, 'sequence' => $slots[$i]]);
                    }
                }
            });

            $this->newLine();
            $this->info('Done. No subject was deleted and no score was touched; only the class subject lists changed.');

            return self::SUCCESS;
        });
    }

    protected function groupFor(string $className): ?string
    {
        foreach (self::DEFAULT_MATCH as $group => $pattern) {
            $explicit = $this->option($group);
            if ($explicit !== null && $explicit !== '') {
                $names = array_map(fn ($n) => mb_strtolower(trim($n)), explode(',', $explicit));
                if (in_array(mb_strtolower(trim($className)), $names, true)) {
                    return $group;
                }

                continue;
            }
            if (preg_match($pattern, trim($className))) {
                return $group;
            }
        }

        return null;
    }

    protected function key(string $name): string
    {
        return preg_replace('/[^a-z0-9]/', '', mb_strtolower($name));
    }

    protected function display(string $raw): string
    {
        $upper = mb_strtoupper(trim($raw));
        if (in_array($upper, ['ICT', 'I.C.T.', 'CRS', 'C.R.S.'], true)) {
            return str_replace('.', '', $upper);
        }

        return preg_replace_callback('/(?<!^)\s(And|Of|The)\s/', fn ($m) => ' '.mb_strtolower(trim($m[0])).' ', Str::title(mb_strtolower(trim($raw))));
    }
}
