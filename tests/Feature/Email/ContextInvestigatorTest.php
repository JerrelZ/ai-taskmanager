<?php

use App\Models\EmailAccount;
use App\Models\EmailFolder;
use App\Models\EmailMessage;
use App\Models\EmailThread;
use App\Services\Email\EmailContextInvestigator;
use App\Services\Email\ExternalProjectDb;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

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

it('prefers the support API tools when an API is configured', function () {
    config()->set('services.anthropic.key', 'test-key');

    Http::fake([
        'https://revboost.test/api/internal/v1/*' => Http::response([
            'data' => ['id' => 8, 'company_name' => 'Just Another Store B.V.', 'unpaid_invoices' => 2],
        ], 200),
        'https://api.anthropic.com/*' => Http::sequence()
            ->push([
                'stop_reason' => 'tool_use',
                'content' => [[
                    'type' => 'tool_use', 'id' => 'tu_1', 'name' => 'customer_summary',
                    'input' => ['user_id' => '8'],
                ]],
            ])
            ->push([
                'stop_reason' => 'tool_use',
                'content' => [[
                    'type' => 'tool_use', 'id' => 'tu_2', 'name' => 'record_findings',
                    'input' => [
                        'summary' => 'Adverteerder met 2 openstaande facturen.',
                        'entities' => [['table' => 'users', 'id' => '8', 'label' => 'Just Another Store B.V.', 'relevance' => 'afzender']],
                    ],
                ]],
            ]),
    ]);

    $account = EmailAccount::factory()->create([
        'external_db_dsn' => ['host' => '127.0.0.1', 'port' => 3306, 'database' => 'revboost', 'username' => 'r', 'password' => 'p'],
        'external_api_base_url' => 'https://revboost.test/api/internal/v1',
        'external_api_token' => 'tok',
    ]);
    $thread = EmailThread::factory()->create(['email_account_id' => $account->id, 'project_id' => $account->project_id]);
    EmailMessage::factory()->create([
        'email_account_id' => $account->id,
        'email_thread_id' => $thread->id,
        'direction' => EmailMessage::DIRECTION_INBOUND,
        'from_email' => 'thomas@justanotherstore.nl',
    ]);

    $result = app(EmailContextInvestigator::class)->investigate($thread);

    expect($result['entities'])->toHaveCount(1);
    Http::assertSent(fn ($request) => str_contains($request->url(), '/api/internal/v1/users/8/summary'));
});

it('includes a readable attachment as a content block for the AI', function () {
    config()->set('services.anthropic.key', 'test-key');
    Storage::fake('local');

    Http::fake([
        'https://api.anthropic.com/*' => Http::response([
            'stop_reason' => 'tool_use',
            'content' => [[
                'type' => 'tool_use', 'id' => 'tu', 'name' => 'record_findings',
                'input' => ['summary' => 'klaar', 'entities' => []],
            ]],
        ], 200),
    ]);

    $thread = investigatorThread();
    $message = $thread->messages()->where('direction', EmailMessage::DIRECTION_INBOUND)->first();

    Storage::disk('local')->put('att/logo.png', 'PNGDATA');
    $message->attachments()->create([
        'disk' => 'local', 'path' => 'att/logo.png', 'filename' => 'logo.png',
        'mime_type' => 'image/png', 'size' => 7,
    ]);

    app(EmailContextInvestigator::class)->investigate($thread->fresh());

    Http::assertSent(function ($request) {
        $blocks = $request->data()['messages'][0]['content'] ?? [];

        return is_array($blocks) && collect($blocks)->contains(fn ($b) => ($b['type'] ?? null) === 'image');
    });
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
