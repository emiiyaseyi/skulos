<?php

namespace App\Models\Tenant;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How an assessment's essays are shared out among its layer-1
 * graders. See GradingDistributionService for what each mode does.
 */
class CbtGradingConfig extends Model
{
    use Auditable;

    protected $connection = 'tenant';

    protected $table = 'cbt_grading_configs';

    protected $fillable = ['assessment_id', 'mode', 'cap'];

    protected function casts(): array
    {
        return [
            'cap' => 'integer',
        ];
    }

    public const MODES = ['all', 'split', 'random', 'manual'];

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(CbtAssessment::class, 'assessment_id');
    }
}
