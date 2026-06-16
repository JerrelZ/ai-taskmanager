<?php

use App\Enums\MessengerNotificationMode;
use App\Livewire\Settings\Notifications as NotificationSettings;
use App\Models\Conversation;
use App\Models\User;
use App\Notifications\MessageDigestNotification;
use App\Notifications\NewMessageNotification;
use App\Notifications\TestPushNotification;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

test('notification settings can be saved', function () {
    Livewire::test(NotificationSettings::class)
        ->set('enabled', true)
        ->set('mode', 'digest')
        ->set('digestIntervalHours', 6)
        ->call('save');

    $this->user->refresh();

    expect($this->user->messenger_notifications_enabled)->toBeTrue()
        ->and($this->user->messenger_notification_mode)->toBe(MessengerNotificationMode::Digest)
        ->and($this->user->messenger_digest_interval_hours)->toBe(6);
});

test('the digest interval is validated within bounds', function () {
    Livewire::test(NotificationSettings::class)
        ->set('mode', 'digest')
        ->set('digestIntervalHours', 99)
        ->call('save')
        ->assertHasErrors('digestIntervalHours');
});

test('a realtime participant is notified of a new message', function () {
    Notification::fake();

    $recipient = User::factory()->create([
        'messenger_notifications_enabled' => true,
        'messenger_notification_mode' => MessengerNotificationMode::Realtime,
    ]);

    $group = Conversation::factory()->create();
    $group->users()->sync([$this->user->id, $recipient->id]);

    $group->postMessage($this->user, 'Hallo!');

    Notification::assertSentTo($recipient, NewMessageNotification::class);
    Notification::assertNotSentTo($this->user, NewMessageNotification::class);
});

test('a participant with notifications disabled is not notified', function () {
    Notification::fake();

    $recipient = User::factory()->create([
        'messenger_notifications_enabled' => false,
    ]);

    $group = Conversation::factory()->create();
    $group->users()->sync([$this->user->id, $recipient->id]);

    $group->postMessage($this->user, 'Hallo!');

    Notification::assertNotSentTo($recipient, NewMessageNotification::class);
});

test('a digest participant is not notified in realtime', function () {
    Notification::fake();

    $recipient = User::factory()->create([
        'messenger_notifications_enabled' => true,
        'messenger_notification_mode' => MessengerNotificationMode::Digest,
    ]);

    $group = Conversation::factory()->create();
    $group->users()->sync([$this->user->id, $recipient->id]);

    $group->postMessage($this->user, 'Hallo!');

    Notification::assertNotSentTo($recipient, NewMessageNotification::class);
});

test('the digest command notifies due users with their unread count', function () {
    Notification::fake();

    $recipient = User::factory()->create([
        'messenger_notifications_enabled' => true,
        'messenger_notification_mode' => MessengerNotificationMode::Digest,
        'messenger_digest_interval_hours' => 4,
        'messenger_digest_last_sent_at' => now()->subHours(5),
    ]);

    $group = Conversation::factory()->create();
    $group->users()->sync([$this->user->id, $recipient->id]);
    $group->postMessage($this->user, 'Bericht een');
    $group->postMessage($this->user, 'Bericht twee');

    $this->artisan('messenger:send-digests')->assertSuccessful();

    Notification::assertSentTo(
        $recipient,
        MessageDigestNotification::class,
        fn (MessageDigestNotification $notification) => $notification->count === 2,
    );

    expect($recipient->fresh()->messenger_digest_last_sent_at)->not->toBeNull();
});

test('the digest command skips users whose interval has not elapsed', function () {
    Notification::fake();

    $recipient = User::factory()->create([
        'messenger_notifications_enabled' => true,
        'messenger_notification_mode' => MessengerNotificationMode::Digest,
        'messenger_digest_interval_hours' => 4,
        'messenger_digest_last_sent_at' => now()->subHour(),
    ]);

    $group = Conversation::factory()->create();
    $group->users()->sync([$this->user->id, $recipient->id]);
    $group->postMessage($this->user, 'Bericht');

    $this->artisan('messenger:send-digests')->assertSuccessful();

    Notification::assertNotSentTo($recipient, MessageDigestNotification::class);
});

test('a user can send themselves a test notification', function () {
    Notification::fake();

    Livewire::test(NotificationSettings::class)->call('sendTestNotification');

    Notification::assertSentTo($this->user, TestPushNotification::class);
});

test('a muted conversation does not trigger realtime notifications', function () {
    Notification::fake();

    $recipient = User::factory()->create([
        'messenger_notifications_enabled' => true,
        'messenger_notification_mode' => MessengerNotificationMode::Realtime,
    ]);

    $group = Conversation::factory()->create();
    $group->users()->sync([$this->user->id, $recipient->id]);
    $group->setMutedFor($recipient, true);

    $group->postMessage($this->user, 'Hoi');

    Notification::assertNotSentTo($recipient, NewMessageNotification::class);
});

test('muted conversations are excluded from the digest count', function () {
    Notification::fake();

    $recipient = User::factory()->create([
        'messenger_notifications_enabled' => true,
        'messenger_notification_mode' => MessengerNotificationMode::Digest,
        'messenger_digest_interval_hours' => 4,
        'messenger_digest_last_sent_at' => now()->subHours(5),
    ]);

    $group = Conversation::factory()->create();
    $group->users()->sync([$this->user->id, $recipient->id]);
    $group->postMessage($this->user, 'een bericht');
    $group->setMutedFor($recipient, true);

    $this->artisan('messenger:send-digests')->assertSuccessful();

    Notification::assertNotSentTo($recipient, MessageDigestNotification::class);
});

test('a user can store a push subscription for this device', function () {
    $this->postJson(route('push-subscriptions.store'), [
        'endpoint' => 'https://push.example.com/abc',
        'keys' => [
            'p256dh' => 'a-public-key',
            'auth' => 'an-auth-token',
        ],
    ])->assertOk();

    expect($this->user->pushSubscriptions()->where('endpoint', 'https://push.example.com/abc')->exists())->toBeTrue();
});
