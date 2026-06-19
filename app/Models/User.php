<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\MessengerNotificationMode;
use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use NotificationChannels\WebPush\HasPushSubscriptions;

/**
 * @property int $id
 * @property int|null $workspace_id
 * @property string $name
 * @property string $email
 * @property UserRole $role
 * @property bool $can_copy_prompt
 * @property int|null $client_id
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property bool $messenger_notifications_enabled
 * @property MessengerNotificationMode $messenger_notification_mode
 * @property int $messenger_digest_interval_hours
 * @property Carbon|null $messenger_digest_last_sent_at
 * @property bool $daily_recap_enabled
 * @property Carbon|null $daily_recap_last_sent_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password', 'role', 'client_id', 'workspace_id', 'daily_recap_enabled'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasPushSubscriptions, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'can_copy_prompt' => 'boolean',
            'messenger_notifications_enabled' => 'boolean',
            'messenger_notification_mode' => MessengerNotificationMode::class,
            'messenger_digest_last_sent_at' => 'datetime',
            'daily_recap_enabled' => 'boolean',
            'daily_recap_last_sent_at' => 'datetime',
        ];
    }

    /**
     * Whether this user should receive an immediate push/in-app notification
     * for each new messenger message.
     */
    public function wantsRealtimeMessageNotifications(): bool
    {
        return $this->messenger_notifications_enabled
            && $this->messenger_notification_mode === MessengerNotificationMode::Realtime;
    }

    /**
     * Whether this user collects new messages into a periodic digest instead of
     * being notified in realtime.
     */
    public function wantsMessageDigest(): bool
    {
        return $this->messenger_notifications_enabled
            && $this->messenger_notification_mode === MessengerNotificationMode::Digest;
    }

    /**
     * Whether enough time has passed since the last digest to send a new one.
     */
    public function isDueForMessageDigest(): bool
    {
        if (! $this->wantsMessageDigest()) {
            return false;
        }

        $last = $this->messenger_digest_last_sent_at;

        return $last === null
            || $last->copy()->addHours(max(1, $this->messenger_digest_interval_hours))->isPast();
    }

    /**
     * The user's *active* workspace — the one all data is currently scoped to.
     *
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * Every workspace the user belongs to and may switch into.
     *
     * @return BelongsToMany<Workspace, $this>
     */
    public function workspaces(): BelongsToMany
    {
        return $this->belongsToMany(Workspace::class, 'workspace_user')->withTimestamps();
    }

    /**
     * Whether the user is a member of the given workspace.
     */
    public function belongsToWorkspace(Workspace|int $workspace): bool
    {
        $workspaceId = $workspace instanceof Workspace ? $workspace->id : $workspace;

        return $this->workspaces()->whereKey($workspaceId)->exists();
    }

    /**
     * Make the given workspace the active one. No-op (returns false) when the
     * user is not a member, so a stale or forged id can never escape the tenant.
     */
    public function switchWorkspace(Workspace|int $workspace): bool
    {
        $workspaceId = $workspace instanceof Workspace ? $workspace->id : $workspace;

        if (! $this->belongsToWorkspace($workspaceId)) {
            return false;
        }

        $this->update(['workspace_id' => $workspaceId]);

        return true;
    }

    /**
     * Limit users to the members of the given workspace.
     *
     * @param  Builder<User>  $query
     */
    public function scopeInWorkspace(Builder $query, int $workspaceId): void
    {
        $query->whereHas('workspaces', fn (Builder $workspaces) => $workspaces->whereKey($workspaceId));
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Internal team member (admin or member) with access to all projects.
     */
    public function isTeam(): bool
    {
        return $this->role->isTeam();
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    public function isClient(): bool
    {
        return $this->role === UserRole::Client;
    }

    /**
     * Whether this user may copy a task's AI prompt to the clipboard.
     */
    public function canCopyPrompt(): bool
    {
        return (bool) $this->can_copy_prompt;
    }

    /**
     * Whether this user already received their daily recap today, used to keep
     * repeated command runs from sending duplicate e-mails.
     */
    public function hasReceivedRecapToday(): bool
    {
        return $this->daily_recap_last_sent_at?->isToday() ?? false;
    }

    /**
     * Total unread messages across the conversations this user belongs to.
     */
    public function unreadMessagesCount(): int
    {
        return DB::table('conversation_user as cu')
            ->join('messages as m', 'm.conversation_id', '=', 'cu.conversation_id')
            ->where('cu.user_id', $this->id)
            ->where('cu.muted', false)
            ->where('m.user_id', '!=', $this->id)
            ->where(fn ($q) => $q->whereNull('cu.last_read_at')->orWhereColumn('m.created_at', '>', 'cu.last_read_at'))
            ->count();
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        return Str::of($this->name)
            ->explode(' ')
            ->take(2)
            ->map(fn ($word) => Str::substr($word, 0, 1))
            ->implode('');
    }
}
