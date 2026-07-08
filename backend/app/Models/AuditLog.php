<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * An immutable audit record. Lives in the platform DB for platform models
 * and in each school's tenant DB for tenant models — the Recorder picks
 * the connection; never instantiate this against the wrong one manually.
 *
 * Security: rows can be created and read, never updated or deleted from
 * application code. "Nothing deleted without a trace" applies to the
 * trace itself most of all.
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'action',
        'auditable_type',
        'auditable_id',
        'old_values',
        'new_values',
        'ip_address',
        'user_agent',
        'url',
    ];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new RuntimeException('Audit logs are immutable.');
        });

        static::deleting(function () {
            throw new RuntimeException('Audit logs cannot be deleted.');
        });
    }
}
