<?php

namespace App\Models;

use Database\Factories\EmailFolderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $email_account_id
 * @property string $name
 * @property int|null $uid_validity
 * @property int $last_seen_uid
 * @property Carbon|null $synced_at
 */
class EmailFolder extends Model
{
    /** @use HasFactory<EmailFolderFactory> */
    use HasFactory;

    protected $fillable = [
        'email_account_id',
        'name',
        'uid_validity',
        'last_seen_uid',
        'synced_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'uid_validity' => 'integer',
            'last_seen_uid' => 'integer',
            'synced_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<EmailAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(EmailAccount::class, 'email_account_id');
    }

    /**
     * @return HasMany<EmailMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(EmailMessage::class);
    }
}
