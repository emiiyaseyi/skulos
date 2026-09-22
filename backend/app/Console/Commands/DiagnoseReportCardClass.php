<?php

namespace App\Console\Commands;

use App\Models\School;
use App\Models\Tenant\SchoolClass;
use App\Services\ReportCards\ReportCardPreferences;
use App\Services\ReportCards\ReportCardService;
use App\Services\Tenancy\TenantManager;
use Illuminate\Console\Command;

/**
 * A throwaway diagnostic, not part of the product: what a specific
 * class's report card actually resolves to, printed as plain text (no
 * tinker/psysh involved, so there is no pager to get stuck in). Safe to
 * delete once the live "why is X missing on the PPMS preview" question
 * this was built to answer is settled.
 */
class DiagnoseReportCardClass extends Command
{
    protected $signature = 'diagnose:report-card-class {classId} {--slug=ppms}';

    protected $description = 'Prints exactly what a class\'s report card resolves to (template, watermark, tracking) for live debugging';

    public function handle(TenantManager $tenants): int
    {
        $school = School::where('slug', $this->option('slug'))->first();
        if (! $school) {
            $this->error("No school found with slug \"{$this->option('slug')}\".");

            return self::FAILURE;
        }

        return $tenants->connectTo($school, function () {
            $class = SchoolClass::with('section.branding')->find($this->argument('classId'));
            if (! $class) {
                $this->error('No class found with id '.$this->argument('classId').'.');

                return self::FAILURE;
            }

            $this->line('Class name: '.$class->name);
            $this->line('Section: '.($class->section?->name ?? 'none'));
            $this->line('Section watermark_enabled: '.var_export($class->section?->branding?->watermark_enabled, true));
            $this->line('Section watermark_mode: '.($class->section?->branding?->watermark_mode ?? 'null (defaults to logo)'));
            $this->line('Section narrative_style: '.($class->section?->branding?->narrative_style ?? 'null'));
            $this->line('Section tracks_physical_stats_start: '.var_export($class->section?->branding?->tracks_physical_stats_start, true));
            $this->line('Class resolvedNarrativeStyle(): '.$class->resolvedNarrativeStyle());
            $this->line('Class resolvedWatermarkEnabled(): '.var_export($class->resolvedWatermarkEnabled(), true));
            $this->line('Class resolvedTracksPhysicalStatsStart(): '.var_export($class->resolvedTracksPhysicalStatsStart(), true));

            $prefs = app(ReportCardPreferences::class);
            $this->line('Resolved template: '.$prefs->templateForClass($class));
            $this->line('Resolved tracksPhysicalStatsStartFor: '.var_export($prefs->tracksPhysicalStatsStartFor($class), true));
            $this->line('Field physical_stats on for this class: '.var_export($prefs->showsFor('physical_stats', $class), true));
            $this->line('Field stamp_signature on for this class: '.var_export($prefs->showsFor('stamp_signature', $class), true));

            $sample = app(ReportCardService::class)->sample($class);
            $this->line('Sample watermark_url: '.($sample['watermark_url'] ?? 'null'));
            $this->line('Sample watermark_text: '.($sample['watermark_text'] ?? 'null'));
            $this->line('Sample logo_url: '.($sample['logo_url'] ?? 'null'));
            $this->line('Sample physical.tracks_start: '.var_export($sample['physical']['tracks_start'] ?? null, true));

            return self::SUCCESS;
        });
    }
}
