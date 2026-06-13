<?php

use App\Jobs\Email\AutoLinkSender;
use App\Models\EmailAccount;
use App\Models\EmailContactLink;
use App\Models\EmailFolder;
use App\Models\EmailMessage;
use App\Models\EmailThread;
use App\Services\Email\ContactLinkSuggester;

function autoLinkThread(): EmailThread
{
    $account = EmailAccount::factory()->create([
        'external_db_dsn' => ['host' => '127.0.0.1', 'port' => 3306, 'database' => 'd', 'username' => 'u', 'password' => 'p'],
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

it('auto-links the sender on a single confident match', function () {
    $thread = autoLinkThread();
    fakeSuggester([
        ['table' => 'users', 'id_column' => 'id', 'id' => '8', 'label' => 'Just Another Store', 'preview' => ''],
    ]);

    (new AutoLinkSender($thread->id))->handle(app(ContactLinkSuggester::class));

    $link = EmailContactLink::first();
    expect($link)->not->toBeNull();
    expect($link->email)->toBe('thomas@store.nl');
    expect($link->external_id)->toBe('8');
    expect($link->linked_by)->toBeNull(); // automatic
});

it('does not auto-link when there are multiple matches', function () {
    $thread = autoLinkThread();
    fakeSuggester([
        ['table' => 'users', 'id_column' => 'id', 'id' => '8', 'label' => 'A', 'preview' => ''],
        ['table' => 'advertisers', 'id_column' => 'id', 'id' => '5', 'label' => 'B', 'preview' => ''],
    ]);

    (new AutoLinkSender($thread->id))->handle(app(ContactLinkSuggester::class));

    expect(EmailContactLink::count())->toBe(0);
});

it('leaves an existing link untouched', function () {
    $thread = autoLinkThread();
    EmailContactLink::create([
        'email_account_id' => $thread->email_account_id,
        'email' => 'thomas@store.nl',
        'external_table' => 'companies',
        'external_id_column' => 'id',
        'external_id' => '99',
        'label' => 'Manual',
        'linked_by' => \App\Models\User::factory()->create()->id,
    ]);
    fakeSuggester([
        ['table' => 'users', 'id_column' => 'id', 'id' => '8', 'label' => 'A', 'preview' => ''],
    ]);

    (new AutoLinkSender($thread->id))->handle(app(ContactLinkSuggester::class));

    expect(EmailContactLink::count())->toBe(1);
    expect(EmailContactLink::first()->external_id)->toBe('99');
});
