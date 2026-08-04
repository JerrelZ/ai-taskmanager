<?php

namespace App\Models;

use Database\Factories\EmailContactLinkFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Links an inbound sender address to a single record on a project's support
 * API. The pointer names a resource and an id, so it stays valid regardless of
 * how the project stores that record internally.
 *
 * @property int $id
 * @property int $email_account_id
 * @property string $email
 * @property string $external_resource
 * @property string $external_id
 * @property string|null $label
 * @property int|null $linked_by
 */
class EmailContactLink extends Model
{
    /** @use HasFactory<EmailContactLinkFactory> */
    use HasFactory;

    /** The only resource the support API currently exposes contacts on. */
    public const RESOURCE_USERS = 'users';

    protected $fillable = [
        'email_account_id',
        'email',
        'external_resource',
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
