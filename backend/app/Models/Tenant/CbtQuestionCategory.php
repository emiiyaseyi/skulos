<?php

namespace App\Models\Tenant;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A Question Bank category — the left sidebar on the Question Bank
 * screen, with a live per-category count. Mirrors the reference repo's
 * `learnguard_qbank_cats`.
 */
class CbtQuestionCategory extends Model
{
    use Auditable;

    protected $connection = 'tenant';

    protected $fillable = ['name', 'description', 'sort_order'];

    protected function casts(): array
    {
        return ['sort_order' => 'integer'];
    }

    public function questions(): HasMany
    {
        return $this->hasMany(CbtQuestion::class, 'category_id');
    }
}
