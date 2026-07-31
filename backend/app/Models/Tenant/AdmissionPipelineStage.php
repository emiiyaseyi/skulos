<?php

namespace App\Models\Tenant;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

/**
 * One stage in a school's own admission pipeline, freely add/edit/
 * reorder-able (user directive 2026-07-31: "admin should be able to add
 * new, edit existing one or reorder the admission pipeline as peculiar
 * to the school"). See the creating migration's docblock for why a free
 * per-tenant table is safe here — nothing downstream gates on a stage's
 * identity, only its position in Applicant::pipelineChain().
 */
class AdmissionPipelineStage extends Model
{
    use Auditable;

    protected $connection = 'tenant';

    protected $fillable = ['label', 'sequence'];

    protected function casts(): array
    {
        return ['sequence' => 'integer'];
    }

    /** Seeds config('skulos.default_admission_pipeline_chain') once; a no-op the moment any stage already exists, including a school emptied back to zero. */
    public static function ensureSeeded(): void
    {
        if (static::query()->exists()) {
            return;
        }

        $catalog = config('skulos.admission_pipeline_stages', []);
        $chain = Setting::grab('admission_pipeline_chain') ?? config('skulos.default_admission_pipeline_chain', []);
        foreach (array_values($chain) as $i => $key) {
            static::create(['label' => $catalog[$key] ?? $key, 'sequence' => $i]);
        }
    }

    public static function ordered()
    {
        static::ensureSeeded();

        return static::orderBy('sequence')->get();
    }
}
