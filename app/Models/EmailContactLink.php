<?php

namespace App\Models;

use Database\Factories\EmailContactLinkFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Links an inbound sender address to a single row in a project's external
 * database. The pointer is generic: each link names its own table, identifier
 * column and value, so different senders can map to different tables.
 *
 * @property int $id
 * @property int $email_account_id
 * @property string $email
 * @property string $external_table
 * @property string $external_id_column
 * @property string $external_id
 * @property string|null $label
 * @property int|null $linked_by
 */
class EmailContactLink extends Model
{
    /** @use HasFactory<EmailContactLinkFactory> */
    use HasFactory;

    protected $fillable = [
        'email_account_id',
        'email',
        'external_table',
        'external_id_column',
        'external_id',
        'label',
        'linked_by',
    ];

    /**
     * @return BelongsTo<EmailAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(EmailAccount::class, 'email_account_id');
    }
}
