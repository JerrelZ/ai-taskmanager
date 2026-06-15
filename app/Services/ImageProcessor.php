<?php

namespace App\Services;

use Imagick;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\Encoders\PngEncoder;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\ImageManager;
use Throwable;

/**
 * Resizes uploaded images for web display and converts phone formats (HEIC/HEIF)
 * to JPEG so they preview in the browser.
 */
class ImageProcessor
{
    /** Longest edge (px) a stored image is scaled down to. */
    private const MAX_EDGE = 1920;

    private const JPEG_QUALITY = 82;

    private const WEBP_QUALITY = 82;

    /** @var array<int, string> */
    private const RASTER_MIMES = [
        'image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif',
    ];

    /** @var array<int, string> */
    private const RASTER_EXTENSIONS = [
        'jpg', 'jpeg', 'png', 'webp', 'heic', 'heif',
    ];

    public function isProcessable(?string $mime, string $filename): bool
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return in_array(strtolower((string) $mime), self::RASTER_MIMES, true)
            || in_array($extension, self::RASTER_EXTENSIONS, true);
    }

    /**
     * Resize an image (and convert HEIC/HEIF to JPEG) for web display.
     *
     * Returns null when the file can't be decoded (e.g. a server without HEIC
     * support) so the caller can fall back to storing the original bytes.
     *
     * @return array{contents: string, filename: string, mime: string}|null
     */
    public function process(string $path, string $filename): ?array
    {
        try {
            $image = $this->manager()->decodePath($path);
            $image->orient();

            if ($image->width() > self::MAX_EDGE || $image->height() > self::MAX_EDGE) {
                $image->scaleDown(self::MAX_EDGE, self::MAX_EDGE);
            }

            $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

            return match ($extension) {
                'png' => [
                    'contents' => (string) $image->encode(new PngEncoder),
                    'filename' => $filename,
                    'mime' => 'image/png',
                ],
                'webp' => [
                    'contents' => (string) $image->encode(new WebpEncoder(quality: self::WEBP_QUALITY)),
                    'filename' => $filename,
                    'mime' => 'image/webp',
                ],
                default => [
                    'contents' => (string) $image->encode(new JpegEncoder(quality: self::JPEG_QUALITY)),
                    'filename' => $this->toJpegName($filename),
                    'mime' => 'image/jpeg',
                ],
            };
        } catch (Throwable) {
            return null;
        }
    }

    private function manager(): ImageManager
    {
        return new ImageManager(
            class_exists(Imagick::class) ? new ImagickDriver : new GdDriver,
        );
    }

    private function toJpegName(string $filename): string
    {
        $base = pathinfo($filename, PATHINFO_FILENAME);

        return ($base !== '' ? $base : 'afbeelding').'.jpg';
    }
}
