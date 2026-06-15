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

        // Resize images (and convert HEIC to JPEG) so they stay a sensible size
        // and preview in the browser. Falls back to the original on failure.
        if ($this->images->isProcessable($mimeType, $originalName) && ($realPath = $file->getRealPath()) !== false) {
            $processed = $this->images->process($realPath, $originalName);

            if ($processed !== null) {
                return $this->storeRaw($processed['contents'], $processed['filename'], $processed['mime'], $attachable, $uploader);
            }
        }

        $path = $file->store($this->directory($attachable), self::DISK);

        return $this->record($attachable, [
            'path' => $path,
            'filename' => $originalName ?: basename($path),
            'mime_type' => $mimeType,
            'size' => $size,
        ], $uploader);
    }

    public function storeRaw(string $contents, string $filename, ?string $mime, Model $attachable, ?User $uploader = null): Attachment
    {
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $path = $this->directory($attachable).'/'.Str::uuid().($extension !== '' ? '.'.$extension : '');

        Storage::disk(self::DISK)->put($path, $contents);

        return $this->record($attachable, [
            'path' => $path,
            'filename' => $filename !== '' ? $filename : basename($path),
            'mime_type' => $mime,
            'size' => strlen($contents),
        ], $uploader);
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
