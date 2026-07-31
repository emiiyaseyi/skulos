<?php

namespace App\Services\Cbt;

use App\Models\Tenant\CbtSession;
use App\Models\Tenant\CbtSnapshot;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * SKULOS Assessment Hub, Phase 2g: stores an evidence capture (a base64
 * data URI from the take screen's own webcam/screen feed) taken
 * alongside a concerning proctoring signal. Path only, never bytes in
 * the database, same rule as every other upload in this app.
 */
class CbtSnapshotService
{
    public function capture(CbtSession $session, string $type, string $dataUri, string $schoolSlug): ?CbtSnapshot
    {
        if (! str_starts_with($dataUri, 'data:image/')) {
            return null;
        }

        [, $encoded] = explode(',', $dataUri, 2) + [null, null];
        $bytes = $encoded !== null ? base64_decode($encoded, true) : false;
        if ($bytes === false) {
            return null;
        }

        $path = "assessment/{$schoolSlug}/sessions/{$session->id}/snapshots/".Str::random(16).'.jpg';
        Storage::disk('local')->put($path, $bytes);

        return CbtSnapshot::create([
            'session_id' => $session->id,
            'type' => $type,
            'path' => $path,
            'captured_at' => now(),
        ]);
    }
}
