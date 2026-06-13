<?php

namespace App\Models;

use Database\Factories\EmailThreadFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $email_account_id
 * @property int $project_id
 * @property string|null $subject
 * @property string $thread_key
 * @property string|null $ai_category
 * @property string|null $ai_summary
 * @property Carbon|null $ai_categorised_at
 * @property Carbon|null $last_message_at
 * @property int $message_count
 * @property bool $is_read
 */
class EmailThread extends Model
{
    /** @use HasFactory<EmailThreadFactory> */
    use HasFactory;

    protected $fillable = [
        'email_account_id',
        'project_id',
        'assignee_id',
        'subject',
        'thread_key',
        'ai_category',
        'ai_summary',
        'ai_categorised_at',
        'last_message_at',
        'message_count',
        'is_read',
        'archived_at',
        'snoozed_until',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ai_categorised_at' => 'datetime',
            'last_message_at' => 'datetime',
            'message_count' => 'integer',
            'is_read' => 'boolean',
            'archived_at' => 'datetime',
            'snoozed_until' => 'datetime',
        ];
    }

    /**
     * Limit threads to those a user is allowed to see, mirroring Project::scopeVisibleTo.
     * Team members see everything; clients only see threads of their own projects.
     *
     * @param  Builder<EmailThread>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if (! $user->isTeam()) {
            $query->whereHas('project', function (Builder $projectQuery) use ($user): void {
                $projectQuery->where('client_id', $user->client_id);
            });
        }
    }

    /**
     * @return BelongsTo<EmailAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(EmailAccount::class, 'email_account_id');
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    /**
     * @return HasMany<EmailMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(EmailMessage::class)->orderBy('sent_at');
    }
}
