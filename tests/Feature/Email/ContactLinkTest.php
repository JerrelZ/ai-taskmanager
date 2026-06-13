<?php

use App\Livewire\Email\Inbox;
use App\Mcp\Servers\ProjectDatabaseServer;
use App\Mcp\Tools\LookupContactByEmailTool;
use App\Models\EmailAccount;
use App\Models\EmailContactLink;
use App\Models\EmailFolder;
use App\Models\EmailMessage;
use App\Models\EmailThread;
use App\Models\Project;
use App\Models\User;
use App\Services\Email\EmailContextBuilder;
use App\Services\Email\ExternalProjectDb;
use Livewire\Livewire;

function accountWithExternalDb(): EmailAccount
{
    return EmailAccount::factory()->create([
        'external_db_dsn' => [
            'host' => '127.0.0.1', 'port' => 3306, 'database' => 'revboost',
            'username' => 'reader', 'password' => 'secret',
        ],
    ]);
}

function inboundThreadFor(EmailAccount $account, string $from = 'klant@voorbeeld.nl'): EmailThread
{
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
        'from_email' => $from,
    ]);

    return $thread;
}

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('links a sender to an external row and unlinks it again', function () {
    $account = accountWithExternalDb();
    $project = Project::find($account->project_id);
    $thread = inboundThreadFor($account);

    $component = Livewire::test(Inbox::class, ['project' => $project])
        ->call('selectThread', $thread->id)
        ->call('linkContact', 'customers', 'id', '42', 'Acme BV')
        ->assertHasNoErrors();

    $link = EmailContactLink::where('email_account_id', $account->id)->first();
    expect($link)->not->toBeNull();
    expect($link->email)->toBe('klant@voorbeeld.nl');
    expect($link->external_table)->toBe('customers');
    expect($link->external_id)->toBe('42');
    expect($link->label)->toBe('Acme BV');

    $component->call('unlinkContact');
    expect(EmailContactLink::count())->toBe(0);
});

it('rejects an unsafe table name when linking', function () {
    $account = accountWithExternalDb();
    $project = Project::find($account->project_id);
    $thread = inboundThreadFor($account);

    Livewire::test(Inbox::class, ['project' => $project])
        ->call('selectThread', $thread->id)
        ->call('linkContact', 'customers; DROP TABLE x', 'id', '42', '');

    expect(EmailContactLink::count())->toBe(0);
});

it('keeps one link per sender within an inbox', function () {
    $account = accountWithExternalDb();
    $project = Project::find($account->project_id);
    $thread = inboundThreadFor($account);

    Livewire::test(Inbox::class, ['project' => $project])
        ->call('selectThread', $thread->id)
        ->call('linkContact', 'customers', 'id', '42', 'Acme')
        ->call('linkContact', 'companies', 'uuid', 'abc', 'Acme Holding');

    expect(EmailContactLink::count())->toBe(1);
    $link = EmailContactLink::first();
    expect($link->external_table)->toBe('companies');
    expect($link->external_id)->toBe('abc');
});

it('uses the confirmed link in the context panel instead of guessing', function () {
    $account = accountWithExternalDb();
    inboundThreadFor($account);

    EmailContactLink::create([
        'email_account_id' => $account->id,
        'email' => 'klant@voorbeeld.nl',
        'external_table' => 'customers',
        'external_id_column' => 'id',
        'external_id' => '42',
        'label' => 'Acme BV',
    ]);

    // Stub the external DB so resolve() returns a concrete row.
    $this->mock(ExternalProjectDb::class, function ($mock) {
        $mock->shouldReceive('select')->andReturn([
            (object) ['id' => 42, 'name' => 'Acme BV', 'plan' => 'enterprise', 'email' => 'klant@voorbeeld.nl'],
        ]);
    });

    $thread = EmailThread::where('email_account_id', $account->id)->with(['messages', 'project', 'account'])->first();
    $context = app(EmailContextBuilder::class)->build($thread);

    expect($context)->toContain('Gekoppeld contact');
    expect($context)->toContain('Acme BV');
    expect($context)->toContain('plan: enterprise');
});

it('returns the confirmed link from the MCP lookup tool', function () {
    $account = accountWithExternalDb();
    $project = Project::find($account->project_id);

    EmailContactLink::create([
        'email_account_id' => $account->id,
        'email' => 'klant@voorbeeld.nl',
        'external_table' => 'customers',
        'external_id_column' => 'id',
        'external_id' => '42',
        'label' => 'Acme BV',
    ]);

    $this->mock(ExternalProjectDb::class, function ($mock) {
        $mock->shouldReceive('select')->andReturn([
            (object) ['id' => 42, 'name' => 'Acme BV'],
        ]);
    });

    ProjectDatabaseServer::tool(LookupContactByEmailTool::class, [
        'project' => $project->key,
        'email' => 'klant@voorbeeld.nl',
    ])->assertOk()->assertSee('Confirmed link');
});
