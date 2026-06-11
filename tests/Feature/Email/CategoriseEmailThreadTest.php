<?php

use App\Enums\EmailCategory;
use App\Jobs\Email\CategoriseEmailThread;
use App\Models\EmailAccount;
use App\Models\EmailFolder;
use App\Models\EmailMessage;
use App\Models\EmailThread;
use App\Services\Email\EmailCategoriser;
use Illuminate\Support\Facades\Http;

function threadWithMessage(string $subject, string $body): EmailThread
{
    $account = EmailAccount::factory()->create();
    $folder = EmailFolder::factory()->create(['email_account_id' => $account->id]);
    $thread = EmailThread::factory()->create([
        'email_account_id' => $account->id,
        'project_id' => $account->project_id,
        'subject' => $subject,
    ]);

    EmailMessage::factory()->create([
        'email_account_id' => $account->id,
        'email_folder_id' => $folder->id,
        'email_thread_id' => $thread->id,
        'subject' => $subject,
        'text_body' => $body,
        'status' => EmailMessage::STATUS_PARSED,
    ]);

    return $thread;
}

it('categorises a thread using Claude and marks messages categorised', function () {
    config(['services.anthropic.key' => 'test-key']);

    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [[
                'text' => json_encode(['category' => 'billing', 'summary' => 'Klant vraagt om een kopie van de factuur.']),
            ]],
        ]),
    ]);

    $thread = threadWithMessage('Vraag over factuur', 'Kan ik een kopie van mijn laatste factuur krijgen?');

    (new CategoriseEmailThread($thread->id))->handle(app(EmailCategoriser::class));

    $thread->refresh();
    expect($thread->ai_category)->toBe(EmailCategory::Billing->value);
    expect($thread->ai_summary)->toContain('factuur');
    expect($thread->ai_categorised_at)->not->toBeNull();
    expect($thread->messages()->first()->status)->toBe(EmailMessage::STATUS_CATEGORISED);
});

it('falls back to Other without an API key and never errors', function () {
    config(['services.anthropic.key' => null]);

    $thread = threadWithMessage('Willekeurig', 'Zomaar een bericht.');

    (new CategoriseEmailThread($thread->id))->handle(app(EmailCategoriser::class));

    $thread->refresh();
    expect($thread->ai_category)->toBe(EmailCategory::Other->value);
    expect($thread->ai_summary)->not->toBe('');
});

it('coerces an unknown AI category to Other', function () {
    config(['services.anthropic.key' => 'test-key']);

    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [['text' => json_encode(['category' => 'nonsense', 'summary' => 'Iets.'])]],
        ]),
    ]);

    $thread = threadWithMessage('Onbekend', 'Test.');

    (new CategoriseEmailThread($thread->id))->handle(app(EmailCategoriser::class));

    expect($thread->refresh()->ai_category)->toBe(EmailCategory::Other->value);
});
