<?php

namespace App\Models\Tenant;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

/**
 * One school-authored free text block available to the Report Card
 * Layout Designer (user request 2026-07-19), e.g. an address line or a
 * disclaimer the fixed field catalog has no place for. Referenced from
 * ReportCardLayout::bodyOrder() as the key "custom_{id}".
 */
class ReportCardCustomBlock extends Model
{
    use Auditable;

    protected $connection = 'tenant';

    protected $fillable = ['label', 'content'];

    public function bodyKey(): string
    {
        return 'custom_'.$this->id;
    }
}
