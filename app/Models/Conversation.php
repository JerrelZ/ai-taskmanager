<?php

namespace App\Models;

use App\Enums\ConversationType;
use App\Events\MessageSent;
use App\Notifications\MentionNotification;
use App\Notifications\NewMessageNotification;
use App\Support\Mentions;
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
use Illuminate\Support\Str;

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

            // isVisibleTo fences on workspace too, so a team member from another
            // workspace can't reach this channel via a forged conversation id.
            return $project !== null && $project->isVisibleTo($user);
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

        MessageSent::dispatch($message);

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
     * Notify conversation participants of a new message. A direct @mention always
     * reaches the recipient in-app (and via web-push on subscribed devices),
     * regardless of their messenger-notification preference or a muted thread.
     * Regular new-message notifications still respect realtime opt-in and mute;
     * digest users are picked up later by the digest command.
     */
    protected function notifyParticipantsOfNewMessage(Message $message, User $sender): void
    {
        $recipients = $this->recipientsExcept($sender);

        if ($recipients->isEmpty()) {
            return;
        }

        $url = route('messages.index', ['conversationId' => $this->id]);
        $preview = Str::limit(trim($message->body), 120) ?: __('Stuurde een bijlage.');

        // Mentions bypass every preference; regular messages honour mute + opt-in.
        $mentionedIds = Mentions::extractUsers($message->body, $recipients)->pluck('id')->all();
        $mutedUserIds = DB::table('conversation_user')
            ->where('conversation_id', $this->id)
            ->where('muted', true)
            ->pluck('user_id')
            ->all();

        foreach ($recipients as $recipient) {
            if (in_array($recipient->id, $mentionedIds, true)) {
                $recipient->notify(new MentionNotification(
                    __(':sender noemde je', ['sender' => $sender->name]),
                    $preview,
                    $url,
                    'conversation-'.$this->id,
                ));

                continue;
            }

            if ($recipient->wantsRealtimeMessageNotifications() && ! in_array($recipient->id, $mutedUserIds, true)) {
                $recipient->notify(new NewMessageNotification($message, $this->pushTitleFor($sender), $url));
            }
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
