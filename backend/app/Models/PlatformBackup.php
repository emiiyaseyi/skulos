<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * One backup of the shared platform database (schools, users, plans,
 * billing, licenses, ...) — see the migration's docblock for why this is
 * its own model rather than a reuse of Backup (which is built entirely
 * around one school owning one backup).
 */
class PlatformBackup extends Model
{
    use Auditable;

    public const UPDATED_AT = null;

    public const TYPE_MANUAL = 'manual';

    public const TYPE_SCHEDULED = 'scheduled';

    protected $fillable = ['disk', 'file_path', 'size_bytes', 'type', 'initiated_by'];

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
