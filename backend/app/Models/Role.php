<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * school_id null  => built-in template every school starts from
 * school_id set   => that school's own (possibly customized) role
 *
 * Security: fillable is explicit — school_id is intentionally NOT mass
 * assignable so a request can never re-home a role to another school;
 * it must be set deliberately in code.
 */
class Role extends Model
{
    protected $fillable = ['name', 'slug', 'description'];

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class);
    }

    public function isTemplate(): bool
    {
        return $this->school_id === null;
    }
}
