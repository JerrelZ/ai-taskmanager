<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\MessengerNotificationMode;
use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
 * @property string $name
 * @property string $email
 * @property UserRole $role
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
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password', 'role', 'client_id'])]
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
            'messenger_notifications_enabled' => 'boolean',
            'messenger_notification_mode' => MessengerNotificationMode::class,
            'messenger_digest_last_sent_at' => 'datetime',
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
