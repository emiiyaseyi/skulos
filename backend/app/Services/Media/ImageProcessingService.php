<?php

namespace App\Services\Media;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Converts an uploaded image to WebP and downsizes it before storing (the
 * standing Database & Storage Efficiency rule: "compress and convert
 * uploaded images to WebP at upload time"). No prior implementation of
 * this rule existed anywhere in the codebase before the Messages "light
 * image" attachment (2026-07-15), so this is written as a small reusable
 * service other upload features (school logo, staff/student photos) can
 * adopt later, rather than a one-off inline conversion.
 *
 * Only ever stores the file path (public disk), never bytes in the
 * database, per the same standing rule.
 */
class ImageProcessingService
{
    /**
     * @param int $maxDimension the longest edge is capped to this many
     *   pixels, preserving aspect ratio ("light image": a phone photo
     *   attached to a broadcast does not need to stay at full camera
     *   resolution to be useful)
     * @param int $quality WebP quality, 0-100
     * @param string $disk defaults to 'public' (a broadcast/logo/chat
     *   image is meant to be widely viewable); financial evidence uploads
     *   pass 'local' instead so the file is never reachable by a bare
     *   unauthenticated URL (audit finding M-2, 2026-07-17)
     * @param int|null $maxKb a hard ceiling on the FINAL stored file size
     *   (student photo upload, user request 2026-07-23: "max size of
     *   image should be 100kb, admin can reduce or increase it under
     *   settings"), re-encoding at falling quality, then falling
     *   dimension, until it fits. Null (the default) keeps every
     *   existing caller's behaviour exactly as before this existed.
     */
    public function toWebp(UploadedFile $file, string $directory, int $maxDimension = 1600, int $quality = 78, string $disk = 'public', ?int $maxKb = null): string
    {
        [$width, $height, $type] = getimagesize($file->getRealPath());

        $source = match ($type) {
            IMAGETYPE_JPEG => imagecreatefromjpeg($file->getRealPath()),
            IMAGETYPE_PNG => imagecreatefrompng($file->getRealPath()),
            IMAGETYPE_WEBP => imagecreatefromwebp($file->getRealPath()),
            IMAGETYPE_GIF => imagecreatefromgif($file->getRealPath()),
            default => throw new \InvalidArgumentException('Unsupported image type.'),
        };

        if ($source === false) {
            throw new \RuntimeException('Could not read the uploaded image.');
        }

        $tmpPath = $maxKb === null
            ? $this->encode($source, $width, $height, $maxDimension, $quality)
            : $this->encodeUnderLimit($source, $width, $height, $maxDimension, $quality, $maxKb);
        imagedestroy($source);

        $filename = $directory.'/'.Str::random(24).'.webp';
        Storage::disk($disk)->put($filename, file_get_contents($tmpPath));
        @unlink($tmpPath);

        return $filename;
    }

    /** Renders once at the given dimension/quality, returns the temp file path (caller deletes it). */
    protected function encode($source, int $width, int $height, int $maxDimension, int $quality): string
    {
        $scale = min(1, $maxDimension / max($width, $height));
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));

        $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
        // Flatten transparency onto white rather than keep an alpha
        // channel: a broadcast image is shown on a themed card background,
        // not guaranteed white, so a transparent PNG would otherwise show
        // a black background in most non-alpha-aware save paths.
        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefill($canvas, 0, 0, $white);
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        $tmpPath = tempnam(sys_get_temp_dir(), 'skulos_img_').'.webp';
        imagewebp($canvas, $tmpPath, $quality);
        imagedestroy($canvas);

        return $tmpPath;
    }

    /**
     * Steps quality down in a fixed sequence first (cheap, same
     * dimension, usually enough on its own); if it is still over the
     * limit even at the lowest quality step, halves the target
     * dimension and starts the quality sequence over, repeating until
     * either it fits or the image is too small to shrink further
     * (returned as-is at that point rather than looping forever on an
     * image that genuinely cannot reach the target without becoming
     * unusably tiny).
     */
    protected function encodeUnderLimit($source, int $width, int $height, int $maxDimension, int $startQuality, int $maxKb): string
    {
        $maxBytes = $maxKb * 1024;
        $qualitySteps = array_values(array_unique(array_filter([$startQuality, 60, 45, 30], fn ($q) => $q <= $startQuality)));
        $dimension = $maxDimension;
        $lastAttempt = null;

        while (true) {
            foreach ($qualitySteps as $quality) {
                $path = $this->encode($source, $width, $height, $dimension, $quality);
                if (filesize($path) <= $maxBytes) {
                    if ($lastAttempt !== null) {
                        @unlink($lastAttempt);
                    }

                    return $path;
                }
                if ($lastAttempt !== null) {
                    @unlink($lastAttempt);
                }
                $lastAttempt = $path;
            }

            if ($dimension <= 200) {
                return $lastAttempt;
            }
            $dimension = (int) round($dimension / 2);
        }
    }
}
