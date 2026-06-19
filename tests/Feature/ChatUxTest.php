<?php

use App\Enums\ConversationType;
use App\Enums\UserRole;
use App\Events\MessageSent;
use App\Livewire\Messages\Index as MessagesIndex;
use App\Livewire\Projects\Board;
use App\Livewire\Projects\Chat;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageReaction;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Support\Mentions;
use Illuminate\Support\Facades\Event;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

/**
 * Open a conversation the acting user is part of and return its Livewire test.
 */
function openChatWith(Conversation $conversation): Testable
{
    $conversation->users()->syncWithoutDetaching([test()->user->id]);

    return Livewire::test(MessagesIndex::class)->call('openConversation', $conversation->id);
}

it('broadcasts a new message on the private conversation channel', function () {
    Event::fake([MessageSent::class]);

    $conversation = Conversation::factory()->create();
    $message = $conversation->postMessage($this->user, 'Realtime hallo');

    Event::assertDispatched(
        MessageSent::class,
        fn (MessageSent $event) => $event->message->is($message)
            && $event->broadcastOn()[0]->name === 'private-conversation.'.$conversation->id,
    );
});

it('loads only the most recent page of messages and can load older', function () {
    $conversation = Conversation::factory()->create();

    // 35 messages with zero-padded bodies (so "Bericht-001" isn't a substring
    // of "Bericht-010") to spot exactly which page is loaded.
    foreach (range(1, 35) as $i) {
        Message::factory()->for($conversation)->create([
            'body' => sprintf('Bericht-%03d', $i),
            'created_at' => now()->addSeconds($i),
        ]);
    }

    $component = openChatWith($conversation);

    // First page = newest 30 (Bericht-006..035); the oldest are not loaded yet.
    expect($component->instance()->thread())->toHaveCount(30);
    $component->assertSee('Bericht-035')
        ->assertDontSee('Bericht-001')
        ->assertSee('Toon oudere berichten');

    // Loading older reveals the rest.
    $component->call('loadOlder');
    expect($component->instance()->thread())->toHaveCount(35);
    $component->assertSee('Bericht-001')
        ->assertDontSee('Toon oudere berichten');
});

it('shows a "Vandaag" day separator for messages sent today', function () {
    $conversation = Conversation::factory()->create();
    Message::factory()->for($conversation)->create(['created_at' => now()]);

    openChatWith($conversation)->assertSee('Vandaag');
});

it('shows a separate day separator when messages span multiple days', function () {
    $conversation = Conversation::factory()->create();
    Message::factory()->for($conversation)->create(['created_at' => now()->subDays(3)]);
    Message::factory()->for($conversation)->create(['created_at' => now()]);

    $component = openChatWith($conversation);

    $component->assertSee('Vandaag');
    $component->assertSee(now()->subDays(3)->locale('nl')->isoFormat('D MMMM'));
});

it('renders the jump-to-bottom control in an open conversation', function () {
    $conversation = Conversation::factory()->create();
    Message::factory()->for($conversation)->create();

    openChatWith($conversation)->assertSee('Naar beneden');
});

it('wires a per-conversation draft key into the composer', function () {
    $conversation = Conversation::factory()->create();

    openChatWith($conversation)->assertSeeHtml("chatComposer([], 'chat-conv-{$conversation->id}'");
});

it('pushes the unread total to the browser when opening a conversation', function () {
    // A conversation with an unread message from someone else keeps the badge > 0.
    $unread = Conversation::factory()->create();
    $unread->users()->syncWithoutDetaching([$this->user->id]);
    $other = User::factory()->create();
    $unread->users()->syncWithoutDetaching([$other->id]);
    $unread->postMessage($other, 'Hoi');

    $opened = Conversation::factory()->create();
    Message::factory()->for($opened)->create();

    openChatWith($opened)->assertDispatched('unread-messages-changed', count: 1);
});

it('creates a ticket from a /ticket slash command in a project chat', function () {
    $project = Project::factory()->create();
    $this->user->update(['role' => UserRole::Member]);

    Livewire::test(Chat::class, ['project' => $project])
        ->set('body', '/ticket Login knop werkt niet')
        ->call('send')
        ->assertSet('body', '');

    $task = $project->tasks()->latest()->first();
    expect($task)->not->toBeNull();
    expect($task->title)->toBe('Login knop werkt niet');

    // The command must not also post a chat message.
    expect($project->channel()->messages()->count())->toBe(0);
});

it('does not create a ticket for slash commands in a project-less conversation', function () {
    $conversation = Conversation::factory()->create(['type' => ConversationType::Dm, 'project_id' => null]);

    openChatWith($conversation)
        ->set('body', '/ticket Iets')
        ->call('send');

    expect($conversation->messages()->count())->toBe(0);
    expect(Task::count())->toBe(0);
});

it('renders a #ref as a ticket chip linking to the task within a project', function () {
    $project = Project::factory()->create(['key' => 'WEB']);
    $task = Task::factory()->for($project)->create();

    $html = Mentions::render("Zie #{$task->number} graag", null, $project);

    expect($html)->toContain('WEB-'.$task->number)
        ->toContain('openTask='.$task->id)
        ->toContain('wire:navigate');
});

it('leaves an unknown #ref as plain text', function () {
    $project = Project::factory()->create();

    expect(Mentions::render('Zie #9999', null, $project))->not->toContain('openTask=');
});

it('opens a deep-linked task on the board on load', function () {
    $project = Project::factory()->create();
    $task = Task::factory()->for($project)->create();

    Livewire::test(Board::class, ['project' => $project, 'openTask' => $task->id])
        ->assertDispatched('open-task', taskId: $task->id);
});

it('filters the conversation list by search term', function () {
    $alice = User::factory()->create();
    $bob = User::factory()->create();

    $withAlice = Conversation::factory()->create(['type' => ConversationType::Dm]);
    $withAlice->users()->sync([$this->user->id, $alice->id]);
    $withAlice->postMessage($alice, 'Zoekterm-alpha-bericht');

    $withBob = Conversation::factory()->create(['type' => ConversationType::Dm]);
    $withBob->users()->sync([$this->user->id, $bob->id]);
    $withBob->postMessage($bob, 'Zoekterm-beta-bericht');

    Livewire::test(MessagesIndex::class)
        ->set('search', 'alpha')
        ->assertSee('Zoekterm-alpha-bericht')
        ->assertDontSee('Zoekterm-beta-bericht');
});

it('sends a reply linked to the message it answers', function () {
    $conversation = Conversation::factory()->create();
    $original = Message::factory()->for($conversation)->create(['body' => 'Originele vraag']);

    openChatWith($conversation)
        ->call('startReply', $original->id)
        ->assertSet('replyingToId', $original->id)
        ->set('body', 'Mijn antwoord')
        ->call('send')
        ->assertSet('replyingToId', null);

    $reply = $conversation->messages()->where('body', 'Mijn antwoord')->first();
    expect($reply->reply_to_id)->toBe($original->id);
});

it('ignores a reply target from another conversation', function () {
    $conversation = Conversation::factory()->create();
    $foreign = Message::factory()->create(); // belongs to a different conversation

    openChatWith($conversation)
        ->set('replyingToId', $foreign->id)
        ->set('body', 'Antwoord')
        ->call('send');

    $reply = $conversation->messages()->where('body', 'Antwoord')->first();
    expect($reply->reply_to_id)->toBeNull();
});

it('toggles an emoji reaction on a message', function () {
    $conversation = Conversation::factory()->create();
    $message = Message::factory()->for($conversation)->create();

    $component = openChatWith($conversation);

    $component->call('toggleReaction', $message->id, '👍');
    expect(MessageReaction::where('message_id', $message->id)->where('emoji', '👍')->where('user_id', $this->user->id)->exists())->toBeTrue();

    $component->call('toggleReaction', $message->id, '👍');
    expect(MessageReaction::where('message_id', $message->id)->exists())->toBeFalse();
});
