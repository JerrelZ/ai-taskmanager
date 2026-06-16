<?php

namespace App\Models;

use App\Enums\ConversationType;
use App\Enums\ProjectStatus;
use App\Enums\UserRole;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int|null $client_id
 * @property string $name
 * @property string|null $key
 * @property string $color
 * @property string|null $description
 * @property string|null $repo_path
 * @property string|null $stack
 * @property string|null $context
 * @property ProjectStatus $status
 * @property int $position
 */
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory;

    protected $fillable = [
        'client_id',
        'name',
        'key',
        'color',
        'description',
        'repo_path',
        'stack',
        'context',
        'status',
        'position',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ProjectStatus::class,
        ];
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Limit projects to those a user is allowed to see.
     * Team members see everything; clients only see their own client's projects.
     *
     * @param  Builder<Project>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if (! $user->isTeam()) {
            $query->where('client_id', $user->client_id);
        }
    }

    /**
     * @param  Builder<Project>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', '!=', ProjectStatus::Archived->value);
    }

    public function isArchived(): bool
    {
        return $this->status === ProjectStatus::Archived;
    }

    /**
     * Generate a unique short key (e.g. "WEB") from a project name.
     */
    public static function generateKey(string $name): string
    {
        $words = preg_split('/[^A-Za-z0-9]+/', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if (count($words) >= 2) {
            $base = Str::upper(Str::substr($words[0], 0, 1).Str::substr($words[1], 0, 1).Str::substr($words[2] ?? '', 0, 1));
        } else {
            $base = Str::upper(Str::substr($words[0] ?? 'PRJ', 0, 3));
        }

        $base = $base !== '' ? $base : 'PRJ';

        $key = $base;
        $suffix = 1;

        while (static::where('key', $key)->exists()) {
            $key = $base.$suffix;
            $suffix++;
        }

        return $key;
    }

    /**
     * All tasks belonging to the project, including subtasks.
     *
     * @return HasMany<Task, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /**
     * Only top-level tasks (not subtasks) shown on the board.
     *
     * @return HasMany<Task, $this>
     */
    public function rootTasks(): HasMany
    {
        return $this->hasMany(Task::class)->whereNull('parent_id');
    }

    /**
     * The project's chat channel (a project-type conversation).
     *
     * @return HasOne<Conversation, $this>
     */
    public function conversation(): HasOne
    {
        return $this->hasOne(Conversation::class)->where('type', ConversationType::Project->value);
    }

    /**
     * Get or create the project's chat channel.
     */
    public function channel(): Conversation
    {
        return Conversation::firstOrCreate(
            ['project_id' => $this->id, 'type' => ConversationType::Project->value],
            ['name' => $this->name],
        );
    }

    /**
     * Users who have access to this project: the internal team plus, when the
     * project belongs to a client, that client's users.
     *
     * @return Builder<User>
     */
    public function accessibleUsers(): Builder
    {
        return User::query()->where(function (Builder $query) {
            $query->whereIn('role', [UserRole::Admin->value, UserRole::Member->value]);

            if ($this->client_id !== null) {
                $query->orWhere('client_id', $this->client_id);
            }
        });
    }
}
