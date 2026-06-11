<?php

namespace App\Models;

use App\Enums\ConversationType;
use Database\Factories\ConversationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property ConversationType $type
 * @property string|null $name
 * @property int|null $project_id
 * @property int|null $created_by
 * @property \Illuminate\Support\Carbon|null $last_message_at
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
        return $this->belongsToMany(User::class)->withPivot('last_read_at')->withTimestamps();
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

    public function postMessage(User $user, string $body): Message
    {
        $message = $this->messages()->create([
            'user_id' => $user->id,
            'body' => $body,
        ]);

        $this->forceFill(['last_message_at' => now()])->save();

        return $message;
    }
}
