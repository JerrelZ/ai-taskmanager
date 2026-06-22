<?php

use App\Livewire\Email\Inbox;
use App\Models\EmailAccount;
use App\Models\EmailFolder;
use App\Models\EmailMessage;
use App\Models\EmailThread;
use App\Models\Project;
use App\Models\User;
use App\Services\Email\EmailThreadSummarizer;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

function summariserThread(): EmailThread
{
    $account = EmailAccount::factory()->create();
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
        'text_body' => 'Waar blijft mijn uitbetaling van vorige maand?',
    ]);

    return $thread;
}

function fakeSummaryResponse(): void
{
    config()->set('services.anthropic.key', 'test-key');

    Http::fake([
        'https://api.anthropic.com/*' => Http::response([
            'content' => [[
                'type' => 'text',
                'text' => "## Samenvatting\n\nThomas vraagt naar zijn uitbetaling.\n\n### Acties\n- Uitbetaling controleren",
            ]],
        ], 200),
    ]);
}

it('summarises the thread into markdown with a summary and actions', function () {
    fakeSummaryResponse();

    $thread = summariserThread();
    $summary = app(EmailThreadSummarizer::class)->summarise($thread);

    expect($summary)->toContain('## Samenvatting');
    expect($summary)->toContain('### Acties');
    expect($summary)->toContain('Uitbetaling controleren');

    Http::assertSent(fn ($request) => str_contains($request->url(), 'api.anthropic.com')
        && ! isset($request->data()['tools']));
});

it('appends the summary to the ticket description', function () {
    fakeSummaryResponse();

    $thread = summariserThread();
    $project = Project::find($thread->project_id);
    $this->actingAs(User::factory()->create());

    Livewire::test(Inbox::class, ['project' => $project])
        ->call('selectThread', $thread->id)
        ->call('openTicketModal')
        ->call('summariseThread')
        ->assertSet('ticketDescription', fn ($value) => str_contains($value, '### Acties'));
});

it('surfaces the API error message when the call fails', function () {
    config()->set('services.anthropic.key', 'test-key');

    Http::fake([
        'https://api.anthropic.com/*' => Http::response([
            'error' => ['message' => 'rate limit reached'],
        ], 429),
    ]);

    $thread = summariserThread();

    expect(fn () => app(EmailThreadSummarizer::class)->summarise($thread))
        ->toThrow(RuntimeException::class, 'rate limit reached');
});

it('errors when no AI key is configured', function () {
    config()->set('services.anthropic.key', null);

    $thread = summariserThread();

    expect(fn () => app(EmailThreadSummarizer::class)->summarise($thread))
        ->toThrow(RuntimeException::class);
});
