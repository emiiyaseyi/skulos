<?php

namespace App\Models\Tenant;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

/**
 * One entry in the score-range comment bank. See the migration for why
 * this table exists rather than reading config('skulos.default_comment_bank')
 * directly, a school must be able to edit/add/deactivate its own.
 */
class Comment extends Model
{
    use Auditable;

    protected $connection = 'tenant';

    protected $fillable = ['min_score', 'max_score', 'text', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
