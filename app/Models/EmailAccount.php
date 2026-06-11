<?php

namespace App\Models;

use Database\Factories\EmailAccountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $project_id
 * @property string $email_address
 * @property string $imap_host
 * @property int $imap_port
 * @property string $imap_encryption
 * @property string $smtp_host
 * @property int $smtp_port
 * @property string $smtp_encryption
 * @property string $username
 * @property string $password
 * @property array<string, mixed>|null $external_db_dsn
 * @property bool $is_active
 * @property Carbon|null $last_sync_at
 * @property string|null $last_sync_error
 */
class EmailAccount extends Model
{
    /** @use HasFactory<EmailAccountFactory> */
    use HasFactory;

    protected $fillable = [
        'project_id',
        'email_address',
        'imap_host',
        'imap_port',
        'imap_encryption',
        'smtp_host',
        'smtp_port',
        'smtp_encryption',
        'username',
        'password',
        'external_db_dsn',
        'is_active',
        'last_sync_at',
        'last_sync_error',
    ];

    protected $hidden = [
        'password',
        'external_db_dsn',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'encrypted',
            'external_db_dsn' => 'encrypted:array',
            'is_active' => 'boolean',
            'last_sync_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return HasMany<EmailFolder, $this>
     */
    public function folders(): HasMany
    {
        return $this->hasMany(EmailFolder::class);
    }

    /**
     * @return HasMany<EmailThread, $this>
     */
    public function threads(): HasMany
    {
        return $this->hasMany(EmailThread::class);
    }

    /**
     * @return HasMany<EmailMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(EmailMessage::class);
    }
}
