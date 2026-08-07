<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * A packaged code release a self-hosted school can download through the
 * license-gated endpoint (ReleaseDownloadController), built from a clean
 * `git archive` of this platform's own working copy — see
 * App\Services\Licensing\ReleaseService. The zip itself lives on the
 * "local" (private) disk under releases/{version}.zip, same convention
 * Backup already uses for backups/{school_id}/...
 */
class Release extends Model
{
    use Auditable;

    protected $fillable = ['version', 'notes', 'file_path', 'file_size', 'checksum', 'created_by_user_id'];

    protected function casts(): array
    {
        return ['published_at' => 'datetime'];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function isPublished(): bool
    {
        return $this->published_at !== null;
    }

    public function exists(): bool
    {
        return Storage::disk('local')->exists($this->file_path);
    }

    public function humanSize(): string
    {
        $bytes = $this->file_size;
        foreach (['B', 'KB', 'MB', 'GB'] as $unit) {
            if ($bytes < 1024) {
                return round($bytes, 1)." {$unit}";
            }
            $bytes /= 1024;
        }

        return round($bytes, 1).' TB';
    }

    public function scopePublished($query)
    {
        return $query->whereNotNull('published_at');
    }
}
