<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One applicant's answer to one custom application-form field.
 */
class AdmissionFormValue extends Model
{
    protected $connection = 'tenant';

    protected $fillable = ['applicant_id', 'admission_form_field_id', 'value'];

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(Applicant::class);
    }

    public function field(): BelongsTo
    {
        return $this->belongsTo(AdmissionFormField::class, 'admission_form_field_id');
    }
}
