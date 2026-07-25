<?php

namespace App\Models\Tenant;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

/**
 * One of the school's own receiving bank accounts for the Bank Transfer
 * payment option (user request 2026-07-25). A school can list more than
 * one; a parent submitting a payment sees every active one so they know
 * where to send the money.
 */
class BankAccount extends Model
{
    use Auditable;

    protected $connection = 'tenant';

    protected $fillable = ['bank_name', 'account_name', 'account_number', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
