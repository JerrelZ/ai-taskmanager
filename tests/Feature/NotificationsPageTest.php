<?php

use App\Livewire\Notifications\Index;
use App\Models\User;
use App\Notifications\MentionNotification;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

test('the notifications page lists the user notifications', function () {
    $this->user->notify(new MentionNotification('Je bent genoemd', 'in een reactie', '/tickets'));

    Livewire::test(Index::class)
        ->assertSee('Je bent genoemd')
        ->assertSee('in een reactie');
});

test('the page is reachable via its route', function () {
    $this->get(route('notifications.index'))->assertOk();
});

test('marking all as read clears the unread count', function () {
    $this->user->notify(new MentionNotification('Hoi', 'test', '/x'));

    expect($this->user->unreadNotifications()->count())->toBe(1);

    Livewire::test(Index::class)
        ->call('markAllAsRead')
        ->assertSet('unreadCount', 0);

    expect($this->user->fresh()->unreadNotifications()->count())->toBe(0);
});

test('marking one notification as read leaves the others unread', function () {
    $this->user->notify(new MentionNotification('Een', 'a', '/x'));
    $this->user->notify(new MentionNotification('Twee', 'b', '/y'));

    $id = $this->user->notifications()->latest()->first()->id;

    Livewire::test(Index::class)
        ->call('markAsRead', $id);

    expect($this->user->fresh()->unreadNotifications()->count())->toBe(1);
});
