<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * A stored file attached to an email message, a task (ticket) or a chat message.
 *
 * @property int $id
 * @property string $attachable_type
 * @property int $attachable_id
 * @property int|null $comment_id
 * @property string $disk
 * @property string $path
 * @property string $filename
 * @property string|null $mime_type
 * @property int $size
 * @property string $public_token
 * @property int|null $uploaded_by
 */
class Attachment extends Model
{
    protected $fillable = [
        'comment_id',
        'disk',
        'path',
        'filename',
        'mime_type',
        'size',
        'checksum',
        'uploaded_by',
    ];

    protected static function booted(): void
    {
        // Give every attachment an unguessable token so it can be shared via a
        // public, login-free link (e.g. inside a copied AI prompt).
        static::creating(function (Attachment $attachment): void {
            if ((string) $attachment->public_token === '') {
                $attachment->public_token = Str::random(40);
            }
        });

        // Remove the underlying file when the row is deleted.
        static::deleting(function (Attachment $attachment): void {
            Storage::disk($attachment->disk)->delete($attachment->path);
        });
    }

    /**
     * A login-free URL that serves the file inline, gated only by the
     * unguessable token. Safe to embed in shareable content like copied prompts.
     */
    public function publicUrl(): string
    {
        return route('attachments.public', ['token' => $this->public_token]);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * The comment this file was posted in, when it was uploaded as part of a reply.
     *
     * @return BelongsTo<Comment, $this>
     */
    public function comment(): BelongsTo
    {
        return $this->belongsTo(Comment::class);
    }

    public function isImage(): bool
    {
        return str_starts_with((string) $this->mime_type, 'image/');
    }

    public function isVideo(): bool
    {
        return str_starts_with((string) $this->mime_type, 'video/');
    }

    public function isPdf(): bool
    {
        return $this->mime_type === 'application/pdf'
            || str_ends_with(strtolower($this->filename), '.pdf');
    }

    /**
     * Whether this file opens in the in-app media viewer (modal) rather than
     * downloading. Everything else (PDFs, documents, archives) downloads.
     */
    public function isPreviewable(): bool
    {
        return $this->isImage() || $this->isVideo();
    }

    public function humanSize(): string
    {
        $bytes = $this->size;

        if ($bytes < 1024) {
            return $bytes.' B';
        }

        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024, 1).' KB';
        }

        return round($bytes / (1024 * 1024), 1).' MB';
    }
}
