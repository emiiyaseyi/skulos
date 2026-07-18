<?php

namespace App\Models\Tenant;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One student's value for one behavioural trait, in one term. Entered by the class teacher. */
class StudentBehaviouralRating extends Model
{
    use Auditable;

    protected $connection = 'tenant';

    protected $fillable = ['student_id', 'term_id', 'behavioural_trait_id', 'value'];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    public function trait_(): BelongsTo
    {
        return $this->belongsTo(BehaviouralTrait::class, 'behavioural_trait_id');
    }
}
