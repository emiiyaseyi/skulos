<?php

namespace App\Models\Tenant;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Optional feedback a student or parent leaves on their own released
 * report card (user request 2026-07-11: "optional and not mandatory,
 * dont forget this"). No submission flow exists yet, there is no parent
 * or student portal login in the product at all, this is the data model
 * ready for whenever that is built.
 */
class ReportCardFeedback extends Model
{
    use Auditable;

    protected $connection = 'tenant';

    // "feedback" is uncountable, so Eloquent's default pluralized table
    // name ("report_card_feedback") does not match the migration's own
    // "report_card_feedbacks", must be set explicitly. Never caught
    // before this model was actually wired up (2026-07-26), it had no
    // caller and no test until then.
    protected $table = 'report_card_feedbacks';

    protected $fillable = ['student_id', 'term_id', 'submitted_by', 'message'];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }
}
