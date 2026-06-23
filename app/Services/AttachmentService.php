<?php

namespace App\Services;

use App\Models\Attachment;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Stores files (uploads or raw bytes) on a private disk and links them to a
 * model via a polymorphic Attachment record.
 */
class AttachmentService
{
    private const DISK = 'local';

    public function __construct(private ImageProcessor $images) {}

    public function storeUpload(UploadedFile $file, Model $attachable, ?User $uploader = null): Attachment
    {
        // Read metadata before storing: on a matching disk Livewire *moves* the
        // temporary upload, after which reading its size/name/mime would throw
        // an UnableToRetrieveMetadata error against the now-missing temp file.
        $originalName = $file->getClientOriginalName();
        $mimeType = $file->getClientMimeType();
        $size = $file->getSize() ?: 0;

        // Checksum the original bytes so an identical re-upload (e.g. re-pasting
        // the same screenshot) can be recognised, regardless of any processing.
        $checksum = self::checksum($file->get());

        // Resize images (and convert HEIC to JPEG) so they stay a sensible size
        // and preview in the browser. Falls back to the original on failure.
        if ($this->images->isProcessable($mimeType, $originalName) && ($realPath = $file->getRealPath()) !== false) {
            $processed = $this->images->process($realPath, $originalName);

            if ($processed !== null) {
                return $this->storeRaw($processed['contents'], $processed['filename'], $processed['mime'], $attachable, $uploader, $checksum);
            }
        }

        $path = $file->store($this->directory($attachable), self::DISK);

        return $this->record($attachable, [
            'path' => $path,
            'filename' => $originalName ?: basename($path),
            'mime_type' => $mimeType,
            'size' => $size,
            'checksum' => $checksum,
        ], $uploader);
    }

    public function storeRaw(string $contents, string $filename, ?string $mime, Model $attachable, ?User $uploader = null, ?string $checksum = null): Attachment
    {
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $path = $this->directory($attachable).'/'.Str::uuid().($extension !== '' ? '.'.$extension : '');

        Storage::disk(self::DISK)->put($path, $contents);

        return $this->record($attachable, [
            'path' => $path,
            'filename' => $filename !== '' ? $filename : basename($path),
            'mime_type' => $mime,
            'size' => strlen($contents),
            'checksum' => $checksum ?? self::checksum($contents),
        ], $uploader);
    }

    /**
     * The sha256 digest used to detect identical file contents.
     */
    public static function checksum(string $contents): string
    {
        return hash('sha256', $contents);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function record(Model $attachable, array $attributes, ?User $uploader): Attachment
    {
        $attachment = new Attachment([
            ...$attributes,
            'disk' => self::DISK,
            'uploaded_by' => $uploader?->id,
        ]);

        $attachment->attachable()->associate($attachable);
        $attachment->save();

        return $attachment;
    }

    private function directory(Model $attachable): string
    {
        return 'attachments/'.Str::of(class_basename($attachable))->snake()->plural().'/'.$attachable->getKey();
    }
}
