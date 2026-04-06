<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

/**
 * Strip EXIF/metadata from uploaded images before storage.
 * Reprocesses images through GD to neutralize polyglot attacks and remove
 * GPS coordinates, camera info, and other PII embedded in EXIF data.
 */
class ImageSanitizer
{
    private const SUPPORTED_MIMES = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
    ];

    /**
     * If the file is a supported image, strip all metadata by re-encoding.
     * Returns the sanitized temp path, or the original path if not an image.
     */
    public static function sanitize(UploadedFile $file): UploadedFile
    {
        $mime = $file->getMimeType();

        if (!in_array($mime, self::SUPPORTED_MIMES, true)) {
            return $file;
        }

        if (!extension_loaded('gd')) {
            Log::warning('ImageSanitizer: GD extension not loaded — skipping metadata strip.');
            return $file;
        }

        try {
            $sourcePath = $file->getRealPath();
            $image = self::createFromFile($sourcePath, $mime);

            if (!$image) {
                return $file;
            }

            // Create a clean image by copying pixel data (strips all metadata)
            $width  = imagesx($image);
            $height = imagesy($image);
            $clean  = imagecreatetruecolor($width, $height);

            // Preserve transparency for PNG/GIF/WebP
            if (in_array($mime, ['image/png', 'image/gif', 'image/webp'])) {
                imagealphablending($clean, false);
                imagesavealpha($clean, true);
                $transparent = imagecolorallocatealpha($clean, 0, 0, 0, 127);
                imagefilledrectangle($clean, 0, 0, $width, $height, $transparent);
                imagealphablending($clean, true);
            }

            imagecopy($clean, $image, 0, 0, 0, 0, $width, $height);
            imagedestroy($image);

            // Write to a temp file
            $tempPath = tempnam(sys_get_temp_dir(), 'img_sanitized_');
            self::writeImage($clean, $tempPath, $mime);
            imagedestroy($clean);

            // Return a new UploadedFile pointing to the sanitized image
            return new UploadedFile(
                $tempPath,
                $file->getClientOriginalName(),
                $mime,
                null,
                true // mark as already validated
            );
        } catch (\Throwable $e) {
            Log::warning('ImageSanitizer: failed to strip metadata', [
                'file'  => $file->getClientOriginalName(),
                'error' => $e->getMessage(),
            ]);
            return $file;
        }
    }

    /**
     * Sanitize a file that's already stored on disk (by absolute path).
     * Overwrites the file in place with a clean version.
     */
    public static function sanitizePath(string $absolutePath): bool
    {
        if (!file_exists($absolutePath) || !extension_loaded('gd')) {
            return false;
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($absolutePath);

        if (!in_array($mime, self::SUPPORTED_MIMES, true)) {
            return false;
        }

        try {
            $image = self::createFromFile($absolutePath, $mime);
            if (!$image) return false;

            $width  = imagesx($image);
            $height = imagesy($image);
            $clean  = imagecreatetruecolor($width, $height);

            if (in_array($mime, ['image/png', 'image/gif', 'image/webp'])) {
                imagealphablending($clean, false);
                imagesavealpha($clean, true);
                $transparent = imagecolorallocatealpha($clean, 0, 0, 0, 127);
                imagefilledrectangle($clean, 0, 0, $width, $height, $transparent);
                imagealphablending($clean, true);
            }

            imagecopy($clean, $image, 0, 0, 0, 0, $width, $height);
            imagedestroy($image);
            self::writeImage($clean, $absolutePath, $mime);
            imagedestroy($clean);

            return true;
        } catch (\Throwable $e) {
            Log::warning('ImageSanitizer::sanitizePath failed', [
                'path'  => $absolutePath,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    private static function createFromFile(string $path, string $mime): ?\GdImage
    {
        return match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($path) ?: null,
            'image/png'  => @imagecreatefrompng($path) ?: null,
            'image/gif'  => @imagecreatefromgif($path) ?: null,
            'image/webp' => @imagecreatefromwebp($path) ?: null,
            default      => null,
        };
    }

    private static function writeImage(\GdImage $image, string $path, string $mime): void
    {
        match ($mime) {
            'image/jpeg' => imagejpeg($image, $path, 90),
            'image/png'  => imagepng($image, $path, 6),
            'image/gif'  => imagegif($image, $path),
            'image/webp' => imagewebp($image, $path, 85),
        };
    }
}
