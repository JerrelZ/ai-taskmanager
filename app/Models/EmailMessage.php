<?php

namespace App\Models;

use Database\Factories\EmailMessageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $email_account_id
 * @property int $email_folder_id
 * @property int|null $email_thread_id
 * @property int $uid_validity
 * @property int $uid
 * @property string|null $message_id
 * @property string|null $in_reply_to
 * @property string|null $references
 * @property string|null $raw_path
 * @property int|null $raw_size
 * @property string $direction
 * @property string $status
 * @property int $parse_attempts
 * @property string|null $parse_error
 * @property string|null $from_name
 * @property string|null $from_email
 * @property array<int, string>|null $to
 * @property array<int, string>|null $cc
 * @property string|null $subject
 * @property string|null $text_body
 * @property string|null $html_body
 * @property array<string, mixed>|null $headers
 * @property Carbon|null $sent_at
 * @property Carbon|null $received_at
 */
class EmailMessage extends Model
{
    /** @use HasFactory<EmailMessageFactory> */
    use HasFactory;

    public const STATUS_RECEIVED = 'received';

    public const STATUS_PARSED = 'parsed';

    public const STATUS_CATEGORISED = 'categorised';

    public const STATUS_PARSE_FAILED = 'parse_failed';

    public const DIRECTION_INBOUND = 'inbound';

    public const DIRECTION_OUTBOUND = 'outbound';

    /**
     * Max parse attempts before a message is marked parse_failed.
     */
    public const MAX_PARSE_ATTEMPTS = 5;

    protected $fillable = [
        'email_account_id',
        'email_folder_id',
        'email_thread_id',
        'uid_validity',
        'uid',
        'message_id',
        'in_reply_to',
        'references',
        'raw_path',
        'raw_size',
        'direction',
        'status',
        'parse_attempts',
        'parse_error',
        'from_name',
        'from_email',
        'to',
        'cc',
        'subject',
        'text_body',
        'html_body',
        'headers',
        'sent_at',
        'received_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'uid_validity' => 'integer',
            'uid' => 'integer',
            'raw_size' => 'integer',
            'parse_attempts' => 'integer',
            'to' => 'array',
            'cc' => 'array',
            'headers' => 'array',
            'sent_at' => 'datetime',
            'received_at' => 'datetime',
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
     * @return BelongsTo<EmailFolder, $this>
     */
    public function folder(): BelongsTo
    {
        return $this->belongsTo(EmailFolder::class, 'email_folder_id');
    }

    /**
     * @return BelongsTo<EmailThread, $this>
     */
    public function thread(): BelongsTo
    {
        return $this->belongsTo(EmailThread::class, 'email_thread_id');
    }
}
