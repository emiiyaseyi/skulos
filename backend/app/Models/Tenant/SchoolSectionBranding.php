<?php

namespace App\Models\Tenant;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A section's own logo/colour override, replacing the school's default
 * for that section only. See the migration for the full "own override
 * replaces the fallback" reasoning. Either column can be null — a
 * section can override just one of the two.
 */
class SchoolSectionBranding extends Model
{
    use Auditable;

    protected $connection = 'tenant';

    protected $fillable = [
        'section_id', 'logo_path', 'brand_color',
        'report_card_template', 'narrative_style', 'watermark_enabled', 'layout_override', 'tracks_physical_stats_start',
        'watermark_mode', 'watermark_path', 'watermark_text', 'field_overrides',
    ];

    protected function casts(): array
    {
        return [
            'watermark_enabled' => 'boolean',
            'layout_override' => 'array',
            'tracks_physical_stats_start' => 'boolean',
            'field_overrides' => 'array',
        ];
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }
}
