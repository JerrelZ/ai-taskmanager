<?php

use App\Livewire\Email\Inbox;
use App\Models\EmailAccount;
use App\Models\EmailFolder;
use App\Models\EmailMessage;
use App\Models\EmailThread;
use App\Models\Project;
use App\Models\User;
use App\Services\Email\EmailContextInvestigator;
use App\Services\Email\ExternalProjectDb;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

function investigatorThread(): EmailThread
{
    $account = EmailAccount::factory()->create([
        'external_db_dsn' => [
            'host' => '127.0.0.1', 'port' => 3306, 'database' => 'revboost',
            'username' => 'reader', 'password' => 'secret',
        ],
    ]);
    $folder = EmailFolder::firstOrCreate(['email_account_id' => $account->id, 'name' => 'INBOX']);

    $thread = EmailThread::factory()->create([
        'email_account_id' => $account->id,
        'project_id' => $account->project_id,
        'subject' => 'Uitbetaling',
    ]);

    EmailMessage::factory()->create([
        'email_account_id' => $account->id,
        'email_folder_id' => $folder->id,
        'email_thread_id' => $thread->id,
        'direction' => EmailMessage::DIRECTION_INBOUND,
        'from_email' => 'thomas@justanotherstore.nl',
        'text_body' => 'Waar blijft mijn uitbetaling?',
    ]);

    return $thread;
}

/** Two-step agent run: query the schema, then record findings. */
function fakeAgentRun(): void
{
    config()->set('services.anthropic.key', 'test-key');

    Http::fake([
        'https://api.anthropic.com/*' => Http::sequence()
            ->push([
                'stop_reason' => 'tool_use',
                'content' => [[
                    'type' => 'tool_use', 'id' => 'tu_1', 'name' => 'query_database',
                    'input' => ['sql' => 'SHOW TABLES'],
                ]],
            ])
            ->push([
                'stop_reason' => 'tool_use',
                'content' => [[
                    'type' => 'tool_use', 'id' => 'tu_2', 'name' => 'record_findings',
                    'input' => [
                        'summary' => 'Thomas is een adverteerder met een openstaande uitbetaling.',
                        'entities' => [
                            ['table' => 'users', 'id' => '8', 'label' => 'Justanotherstore', 'relevance' => 'afzender-account'],
                            ['table' => 'advertiser_additional_infos', 'id' => '5', 'label' => 'Just Another Store B.V.', 'relevance' => 'adverteerder'],
                        ],
                    ],
                ]],
            ]),
    ]);
}

it('lets the AI investigate the database and returns structured entities', function () {
    fakeAgentRun();
    $this->mock(ExternalProjectDb::class, function ($mock) {
        $mock->shouldReceive('select')->andReturn([(object) ['Tables_in_revboost' => 'users']]);
    });

    $thread = investigatorThread();
    $result = app(EmailContextInvestigator::class)->investigate($thread);

    expect($result['entities'])->toHaveCount(2);
    expect($result['entities'][0]['table'])->toBe('users');
    expect($result['entities'][0]['id'])->toBe('8');
    expect($result['markdown'])->toContain('Context voor Claude Code');
    expect($result['markdown'])->toContain('`users` #8');
    expect($result['markdown'])->toContain('`advertiser_additional_infos` #5');
});

it('appends the investigated context to the ticket description', function () {
    fakeAgentRun();
    $this->mock(ExternalProjectDb::class, function ($mock) {
        $mock->shouldReceive('select')->andReturn([(object) ['Tables_in_revboost' => 'users']]);
    });

    $thread = investigatorThread();
    $project = Project::find($thread->project_id);
    $this->actingAs(User::factory()->create());

    Livewire::test(Inbox::class, ['project' => $project])
        ->call('selectThread', $thread->id)
        ->call('openTicketModal')
        ->call('enrichTicketContext')
        ->assertSet('ticketDescription', fn ($value) => str_contains($value, '`users` #8'));
});

it('errors gracefully when the project has no external database', function () {
    config()->set('services.anthropic.key', 'test-key');

    $account = EmailAccount::factory()->create(['external_db_dsn' => null]);
    $thread = EmailThread::factory()->create([
        'email_account_id' => $account->id,
        'project_id' => $account->project_id,
    ]);

    expect(fn () => app(EmailContextInvestigator::class)->investigate($thread))
        ->toThrow(RuntimeException::class);
});
