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
        'report_card_template', 'narrative_style', 'watermark_enabled',
    ];

    protected function casts(): array
    {
        return [
            'watermark_enabled' => 'boolean',
        ];
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }
}
