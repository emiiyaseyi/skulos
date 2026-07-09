<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * One backup of one school's tenant database. The file itself lives on
 * the "local" (private) disk under backups/{school_id}/...
 */
class Backup extends Model
{
    use Auditable;

    public const TYPE_MANUAL = 'manual';
    public const TYPE_SCHEDULED = 'scheduled';

    protected $fillable = [
        'school_id', 'disk', 'file_path', 'size_bytes', 'type', 'initiated_by', 'restored_at',
    ];

    protected function casts(): array
    {
        return ['restored_at' => 'datetime'];
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function initiator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }

    public function exists(): bool
    {
        return Storage::disk($this->disk)->exists($this->file_path);
    }

    public function humanSize(): string
    {
        $bytes = $this->size_bytes;
        foreach (['B', 'KB', 'MB', 'GB'] as $unit) {
            if ($bytes < 1024) {
                return round($bytes, 1)." {$unit}";
            }
            $bytes /= 1024;
        }

        return round($bytes, 1).' TB';
    }
}
