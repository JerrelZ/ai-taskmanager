<?php

use App\Jobs\Email\AutoLinkSender;
use App\Models\EmailAccount;
use App\Models\EmailContactLink;
use App\Models\EmailFolder;
use App\Models\EmailMessage;
use App\Models\EmailThread;
use App\Models\User;
use App\Services\Email\ContactLinkSuggester;
use App\Services\Email\ExternalProjectApi;

function autoLinkThread(): EmailThread
{
    $account = EmailAccount::factory()->create([
        'external_api_base_url' => 'https://revboost.test/api/internal/v1',
        'external_api_token' => 'secret-token',
    ]);
    $folder = EmailFolder::firstOrCreate(['email_account_id' => $account->id, 'name' => 'INBOX']);

    $thread = EmailThread::factory()->create([
        'email_account_id' => $account->id,
        'project_id' => $account->project_id,
    ]);
    EmailMessage::factory()->create([
        'email_account_id' => $account->id,
        'email_folder_id' => $folder->id,
        'email_thread_id' => $thread->id,
        'direction' => EmailMessage::DIRECTION_INBOUND,
        'from_email' => 'thomas@store.nl',
    ]);

    return $thread;
}

function fakeSuggester(array $suggestions): void
{
    test()->mock(ContactLinkSuggester::class, function ($mock) use ($suggestions) {
        $mock->shouldReceive('suggest')->andReturn($suggestions);
    });
}

function runAutoLink(EmailThread $thread): void
{
    (new AutoLinkSender($thread->id))->handle(
        app(ContactLinkSuggester::class),
        app(ExternalProjectApi::class),
    );
}

it('auto-links the sender on a single confident match', function () {
    $thread = autoLinkThread();
    fakeSuggester([
        ['resource' => 'users', 'id' => '8', 'label' => 'Just Another Store', 'preview' => ''],
    ]);

    runAutoLink($thread);

    $link = EmailContactLink::first();
    expect($link)->not->toBeNull();
    expect($link->email)->toBe('thomas@store.nl');
    expect($link->external_resource)->toBe('users');
    expect($link->external_id)->toBe('8');
    expect($link->linked_by)->toBeNull(); // automatic
});

it('does not auto-link when there are multiple matches', function () {
    $thread = autoLinkThread();
    fakeSuggester([
        ['resource' => 'users', 'id' => '8', 'label' => 'A', 'preview' => ''],
        ['resource' => 'users', 'id' => '5', 'label' => 'B', 'preview' => ''],
    ]);

    runAutoLink($thread);

    expect(EmailContactLink::count())->toBe(0);
});

it('does nothing when the project has no support API', function () {
    $account = EmailAccount::factory()->create(['external_api_base_url' => null]);
    $thread = EmailThread::factory()->create([
        'email_account_id' => $account->id,
        'project_id' => $account->project_id,
    ]);
    EmailMessage::factory()->create([
        'email_account_id' => $account->id,
        'email_thread_id' => $thread->id,
        'direction' => EmailMessage::DIRECTION_INBOUND,
        'from_email' => 'thomas@store.nl',
    ]);

    fakeSuggester([
        ['resource' => 'users', 'id' => '8', 'label' => 'A', 'preview' => ''],
    ]);

    runAutoLink($thread);

    expect(EmailContactLink::count())->toBe(0);
});

it('leaves an existing link untouched', function () {
    $thread = autoLinkThread();
    EmailContactLink::create([
        'email_account_id' => $thread->email_account_id,
        'email' => 'thomas@store.nl',
        'external_resource' => EmailContactLink::RESOURCE_USERS,
        'external_id' => '99',
        'label' => 'Manual',
        'linked_by' => User::factory()->create()->id,
    ]);
    fakeSuggester([
        ['resource' => 'users', 'id' => '8', 'label' => 'A', 'preview' => ''],
    ]);

    runAutoLink($thread);

    expect(EmailContactLink::count())->toBe(1);
    expect(EmailContactLink::first()->external_id)->toBe('99');
});
