<?php

namespace App\Services\Licensing;

use App\Models\Release;
use App\Models\User;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Self-hosted licensing: release management (2026-08-07). Packages a
 * clean, tracked-files-only snapshot of the platform's own `backend/`
 * working copy via `git archive` — this deliberately requires the
 * running platform deployment itself to be a real git checkout (true for
 * a git-clone/pull deployed instance, the recommended pattern for a
 * continuously updated app); if it is not, the command fails loudly with
 * a clear error rather than silently zipping something wrong. Every
 * entry in the resulting zip is already prefixed `backend/` (the real
 * path `git archive` sees it at), matching exactly the "what to copy"
 * shape DEPLOYMENT.md's self-hosted section already documents.
 */
class ReleaseService
{
    public function build(string $version, ?string $notes, ?User $createdBy = null): Release
    {
        $relativePath = "releases/{$version}.zip";
        Storage::disk('local')->makeDirectory('releases');
        $absolutePath = Storage::disk('local')->path($relativePath);

        // base_path() is backend/ itself; the git repository root (where
        // .git actually lives) is one directory up.
        $repoRoot = dirname(base_path());

        $result = Process::path($repoRoot)->timeout(120)->run([
            'git', 'archive', '--format=zip', 'HEAD', '-o', $absolutePath, '--', 'backend',
        ]);

        if ($result->failed()) {
            throw new RuntimeException('git archive failed: '.$result->errorOutput());
        }

        return Release::create([
            'version' => $version,
            'notes' => $notes,
            'file_path' => $relativePath,
            'file_size' => Storage::disk('local')->size($relativePath),
            'checksum' => hash_file('sha256', $absolutePath),
            'created_by_user_id' => $createdBy?->id,
        ]);
    }

    public function publish(Release $release): void
    {
        $release->forceFill(['published_at' => now()])->save();
    }

    public function unpublish(Release $release): void
    {
        $release->forceFill(['published_at' => null])->save();
    }

    public function delete(Release $release): void
    {
        Storage::disk('local')->delete($release->file_path);
        $release->delete();
    }

    public function latestPublished(): ?Release
    {
        return Release::published()->orderByDesc('published_at')->first();
    }
}
