<?php

use App\Enums\ConversationType;
use App\Livewire\Messages\Index;
use App\Models\Client;
use App\Models\Conversation;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

test('the messenger lists conversations the user is a member of', function () {
    $group = Conversation::factory()->create(['name' => 'Algemeen']);
    $group->users()->sync([$this->user->id]);

    $otherGroup = Conversation::factory()->create(['name' => 'Geheim']);
    $otherGroup->users()->sync([User::factory()->create()->id]);

    Livewire::test(Index::class)
        ->assertSee('Algemeen')
        ->assertDontSee('Geheim');
});

test('a message can be sent in a conversation', function () {
    $group = Conversation::factory()->create();
    $group->users()->sync([$this->user->id]);

    Livewire::test(Index::class)
        ->call('openConversation', $group->id)
        ->set('body', 'Hoi allemaal')
        ->call('send')
        ->assertSet('body', '');

    expect($group->messages()->count())->toBe(1)
        ->and($group->fresh()->last_message_at)->not->toBeNull();
});

test('starting a DM reuses an existing one', function () {
    $other = User::factory()->create();

    $component = Livewire::test(Index::class)
        ->set('newDmUserId', $other->id)
        ->call('startDm');

    expect(Conversation::where('type', ConversationType::Dm->value)->count())->toBe(1);

    $component->set('newDmUserId', $other->id)->call('startDm');

    expect(Conversation::where('type', ConversationType::Dm->value)->count())->toBe(1);
});

test('a group conversation can be created with project members', function () {
    $project = Project::factory()->create();
    $a = User::factory()->create();
    $b = User::factory()->create();

    Livewire::test(Index::class)
        ->set('newGroupName', 'Design')
        ->set('newGroupProjectId', $project->id)
        ->set('newGroupMembers', [$a->id, $b->id])
        ->call('createGroup');

    $group = Conversation::where('name', 'Design')->first();

    expect($group)->not->toBeNull()
        ->and($group->project_id)->toBe($project->id)
        ->and($group->users()->count())->toBe(3);
});

test('creating a group requires a project', function () {
    Livewire::test(Index::class)
        ->set('newGroupName', 'Design')
        ->call('createGroup')
        ->assertHasErrors('newGroupProjectId');

    expect(Conversation::where('name', 'Design')->exists())->toBeFalse();
});

test('group members are limited to people in the chosen project', function () {
    $clientA = Client::factory()->create();
    $clientB = Client::factory()->create();

    $projectA = Project::factory()->create(['client_id' => $clientA->id]);

    $clientAUser = User::factory()->client($clientA)->create();
    $clientBUser = User::factory()->client($clientB)->create();

    Livewire::test(Index::class)
        ->set('newGroupName', 'Design')
        ->set('newGroupProjectId', $projectA->id)
        ->set('newGroupMembers', [$clientAUser->id, $clientBUser->id])
        ->call('createGroup');

    $group = Conversation::where('name', 'Design')->first();

    expect($group)->not->toBeNull()
        ->and($group->users->pluck('id')->all())->toContain($clientAUser->id)
        ->and($group->users->pluck('id')->all())->not->toContain($clientBUser->id);
});

test('a message can be turned into a ticket (fallback without AI key)', function () {
    config()->set('services.anthropic.key', null);

    $project = Project::factory()->create();
    $group = Conversation::factory()->create();
    $group->users()->sync([$this->user->id]);
    $message = $group->postMessage($this->user, 'We moeten de checkout-bug fixen voor de livegang');

    Livewire::test(Index::class)
        ->call('openConversation', $group->id)
        ->call('openTicketDraft', $message->id)
        ->set('ticketProjectId', $project->id)
        ->call('createTicketFromMessage');

    $task = Task::where('project_id', $project->id)->first();

    expect($task)->not->toBeNull()
        ->and($task->description)->toContain('checkout-bug');
});

test('the unread count reflects unread messages and resets on read', function () {
    $other = User::factory()->create();
    $group = Conversation::factory()->create();
    $group->users()->sync([$this->user->id, $other->id]);

    $group->postMessage($other, 'ongelezen bericht');

    expect($this->user->unreadMessagesCount())->toBe(1);

    $group->markReadFor($this->user);

    expect($this->user->fresh()->unreadMessagesCount())->toBe(0);
});

test('the open conversation is kept read while polling for new messages', function () {
    $other = User::factory()->create();
    $group = Conversation::factory()->create();
    $group->users()->sync([$this->user->id, $other->id]);

    $component = Livewire::test(Index::class)->call('openConversation', $group->id);

    $this->travel(5)->seconds();
    $group->postMessage($other, 'binnenkomend bericht');

    expect($this->user->fresh()->unreadMessagesCount())->toBe(1);

    $component->call('pollMessages');

    expect($this->user->fresh()->unreadMessagesCount())->toBe(0);
});

test('a participant can mute and unmute a conversation', function () {
    $group = Conversation::factory()->create();
    $group->users()->sync([$this->user->id]);

    $component = Livewire::test(Index::class)->call('openConversation', $group->id);

    expect($group->isMutedFor($this->user))->toBeFalse();

    $component->call('toggleMute');
    expect($group->isMutedFor($this->user))->toBeTrue();

    $component->call('toggleMute');
    expect($group->isMutedFor($this->user))->toBeFalse();
});

test('clients only see their own project channel', function () {
    $client = Client::factory()->create();
    $clientUser = User::factory()->client($client)->create();

    $ownProject = Project::factory()->create(['client_id' => $client->id]);
    $ownProject->channel()->postMessage($this->user, 'Welkom bij je project');

    $otherProject = Project::factory()->create(['client_id' => null]);
    $otherProject->channel()->postMessage($this->user, 'Interne chat');

    $this->actingAs($clientUser);

    Livewire::test(Index::class)
        ->assertSee($ownProject->name)
        ->assertDontSee($otherProject->name);
});
