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
     */
    public function toWebp(UploadedFile $file, string $directory, int $maxDimension = 1600, int $quality = 78, string $disk = 'public'): string
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
        imagedestroy($source);

        $tmpPath = tempnam(sys_get_temp_dir(), 'skulos_img_').'.webp';
        imagewebp($canvas, $tmpPath, $quality);
        imagedestroy($canvas);

        $filename = $directory.'/'.Str::random(24).'.webp';
        Storage::disk($disk)->put($filename, file_get_contents($tmpPath));
        @unlink($tmpPath);

        return $filename;
    }
}
