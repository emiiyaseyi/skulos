<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * One entry in the permission catalog ("students.view", "examinations.approve_results", ...).
 * The catalog is seeded from config/skulos.php and is not user-editable —
 * schools grant/revoke catalog entries, they never invent new codes.
 */
class Permission extends Model
{
    protected $fillable = ['code', 'module', 'label'];

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }
}
