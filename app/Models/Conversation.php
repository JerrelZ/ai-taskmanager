<?php

namespace App\Models;

use App\Enums\ConversationType;
use App\Notifications\NewMessageNotification;
use Database\Factories\ConversationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * @property int $id
 * @property ConversationType $type
 * @property string|null $name
 * @property int|null $project_id
 * @property int|null $created_by
 * @property Carbon|null $last_message_at
 */
class Conversation extends Model
{
    /** @use HasFactory<ConversationFactory> */
    use HasFactory;

    protected $fillable = [
        'type',
        'name',
        'project_id',
        'created_by',
        'last_message_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ConversationType::class,
            'last_message_at' => 'datetime',
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
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('last_read_at', 'muted')->withTimestamps();
    }

    /**
     * @return HasMany<Message, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class)->oldest();
    }

    /**
     * @return HasOne<Message, $this>
     */
    public function latestMessage(): HasOne
    {
        return $this->hasOne(Message::class)->latestOfMany();
    }

    /**
     * Conversations a user may see: their DMs/groups, plus project channels
     * for projects they can access.
     *
     * @param  Builder<Conversation>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $query->where(function (Builder $query) use ($user) {
            $query->whereHas('users', fn (Builder $u) => $u->whereKey($user->id))
                ->orWhere(fn (Builder $q) => $q
                    ->where('type', ConversationType::Project->value)
                    ->whereHas('project', fn (Builder $p) => $p->visibleTo($user))
                );
        });
    }

    public function canAccess(User $user): bool
    {
        if ($this->type === ConversationType::Project) {
            $project = $this->project;

            return $project !== null && ($user->isTeam() || $project->client_id === $user->client_id);
        }

        return $this->users()->whereKey($user->id)->exists();
    }

    /**
     * Display title from the perspective of the given user.
     */
    public function titleFor(User $user): string
    {
        return match ($this->type) {
            ConversationType::Project => $this->project?->name ?? __('Project'),
            ConversationType::Group => $this->name ?? __('Groep'),
            ConversationType::Dm => $this->users->firstWhere('id', '!=', $user->id)?->name ?? __('Gesprek'),
        };
    }

    public function unreadCountFor(User $user): int
    {
        $pivot = $this->users->firstWhere('id', $user->id)?->pivot;
        $lastRead = $pivot?->last_read_at;

        return $this->messages()
            ->where('user_id', '!=', $user->id)
            ->when($lastRead, fn ($q) => $q->where('created_at', '>', $lastRead))
            ->count();
    }

    public function markReadFor(User $user): void
    {
        $this->users()->syncWithoutDetaching([
            $user->id => ['last_read_at' => now()],
        ]);
    }

    /**
     * Whether the given user has muted notifications for this conversation.
     */
    public function isMutedFor(User $user): bool
    {
        return DB::table('conversation_user')
            ->where('conversation_id', $this->id)
            ->where('user_id', $user->id)
            ->where('muted', true)
            ->exists();
    }

    /**
     * Mute or unmute notifications for the given user.
     */
    public function setMutedFor(User $user, bool $muted): void
    {
        $this->users()->syncWithoutDetaching([
            $user->id => ['muted' => $muted],
        ]);
    }

    public function postMessage(User $user, string $body, ?int $replyToId = null): Message
    {
        // Only allow replying to a message that lives in this same conversation.
        $validReplyToId = $replyToId !== null
            ? $this->messages()->whereKey($replyToId)->value('id')
            : null;

        $message = $this->messages()->create([
            'user_id' => $user->id,
            'reply_to_id' => $validReplyToId,
            'body' => $body,
        ]);

        $this->forceFill(['last_message_at' => now()])->save();

        $this->notifyParticipantsOfNewMessage($message, $user);

        return $message;
    }

    /**
     * Users who should be notified of activity in this conversation, excluding
     * the actor. DMs and groups use their explicit member list; project channels
     * fan out to the team plus the project's client users.
     *
     * @return Collection<int, User>
     */
    public function recipientsExcept(User $actor): Collection
    {
        if ($this->type === ConversationType::Project) {
            $project = $this->project;

            if ($project === null) {
                return collect();
            }

            return $project->accessibleUsers()->whereKeyNot($actor->id)->get();
        }

        return $this->users()->whereKeyNot($actor->id)->get();
    }

    /**
     * Send a realtime notification to participants who opted into realtime
     * delivery. Digest users are picked up later by the digest command.
     */
    protected function notifyParticipantsOfNewMessage(Message $message, User $sender): void
    {
        $mutedUserIds = DB::table('conversation_user')
            ->where('conversation_id', $this->id)
            ->where('muted', true)
            ->pluck('user_id')
            ->all();

        $recipients = $this->recipientsExcept($sender)
            ->filter(fn (User $user) => $user->wantsRealtimeMessageNotifications()
                && ! in_array($user->id, $mutedUserIds, true));

        if ($recipients->isEmpty()) {
            return;
        }

        $url = route('messages.index', ['conversationId' => $this->id]);

        foreach ($recipients as $recipient) {
            $recipient->notify(new NewMessageNotification($message, $this->pushTitleFor($sender), $url));
        }
    }

    /**
     * Headline shown in the notification: just the sender for DMs, otherwise the
     * sender alongside the group or project name.
     */
    protected function pushTitleFor(User $sender): string
    {
        return match ($this->type) {
            ConversationType::Dm => $sender->name,
            ConversationType::Group => __(':sender in :group', ['sender' => $sender->name, 'group' => $this->name ?? __('Groep')]),
            ConversationType::Project => __(':sender in :project', ['sender' => $sender->name, 'project' => $this->project->name]),
        };
    }
}
