<?php

namespace App\Models\Tenant;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

/**
 * A calendar entry: a notable day, holiday, exam window, event, etc.
 * Seeded defaults carry is_default = true; schools add their own freely.
 */
class CalendarEvent extends Model
{
    use Auditable;

    protected $connection = 'tenant';

    protected $fillable = [
        'title', 'category', 'description', 'starts_on', 'ends_on',
        'all_day', 'is_default', 'academic_session_id', 'term_id',
        'subject_id', 'class_arm_id', 'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'all_day' => 'boolean',
            'is_default' => 'boolean',
        ];
    }

    public function scopeBetween($query, $from, $to)
    {
        return $query->whereBetween('starts_on', [$from, $to]);
    }

    public function scopeUpcoming($query)
    {
        return $query->whereNotNull('starts_on')
            ->whereDate('starts_on', '>=', now()->toDateString())
            ->orderBy('starts_on');
    }

    public function scopeExams($query)
    {
        return $query->where('category', 'exam');
    }

    public function subject(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function classArm(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(ClassArm::class);
    }
}
