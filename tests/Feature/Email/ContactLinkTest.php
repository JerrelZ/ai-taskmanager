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
use App\Services\Email\ContactLinkSuggester;
use App\Services\Email\EmailContextBuilder;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

function accountWithSupportApi(): EmailAccount
{
    $account = EmailAccount::factory()->create([
        'external_api_base_url' => 'https://revboost.test/api/internal/v1',
        'external_api_token' => 'secret-token',
    ]);

    if ($id = auth()->id()) {
        Project::find($account->project_id)?->members()->attach($id);
    }

    return $account;
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

/** Fake the /users/{id}/summary response the suggester resolves a link with. */
function fakeCustomerSummary(array $data = []): void
{
    Http::fake([
        'https://revboost.test/api/internal/v1/*' => Http::response(['data' => [
            'id' => 42,
            'name' => 'Thomas',
            'company_name' => 'Acme BV',
            'account_type' => 'advertiser',
            'unpaid_invoices' => 2,
            ...$data,
        ]], 200),
    ]);
}

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('links a sender to a support-API record and unlinks it again', function () {
    $account = accountWithSupportApi();
    $project = Project::find($account->project_id);
    $thread = inboundThreadFor($account);

    $component = Livewire::test(Inbox::class, ['project' => $project])
        ->call('selectThread', $thread->id)
        ->call('linkContact', 'users', '42', 'Acme BV')
        ->assertHasNoErrors();

    $link = EmailContactLink::where('email_account_id', $account->id)->first();
    expect($link)->not->toBeNull();
    expect($link->email)->toBe('klant@voorbeeld.nl');
    expect($link->external_resource)->toBe('users');
    expect($link->external_id)->toBe('42');
    expect($link->label)->toBe('Acme BV');

    $component->call('unlinkContact');
    expect(EmailContactLink::count())->toBe(0);
});

it('rejects an unknown resource when linking', function () {
    $account = accountWithSupportApi();
    $project = Project::find($account->project_id);
    $thread = inboundThreadFor($account);

    Livewire::test(Inbox::class, ['project' => $project])
        ->call('selectThread', $thread->id)
        ->call('linkContact', 'orders', '42', '');

    expect(EmailContactLink::count())->toBe(0);
});

it('keeps one link per sender within an inbox', function () {
    $account = accountWithSupportApi();
    $project = Project::find($account->project_id);
    $thread = inboundThreadFor($account);

    Livewire::test(Inbox::class, ['project' => $project])
        ->call('selectThread', $thread->id)
        ->call('linkContact', 'users', '42', 'Acme')
        ->call('linkContact', 'users', '77', 'Acme Holding');

    expect(EmailContactLink::count())->toBe(1);
    $link = EmailContactLink::first();
    expect($link->external_id)->toBe('77');
    expect($link->label)->toBe('Acme Holding');
});

it('uses the confirmed link in the context panel instead of guessing', function () {
    $account = accountWithSupportApi();
    inboundThreadFor($account);

    EmailContactLink::create([
        'email_account_id' => $account->id,
        'email' => 'klant@voorbeeld.nl',
        'external_resource' => EmailContactLink::RESOURCE_USERS,
        'external_id' => '42',
        'label' => 'Acme BV',
    ]);

    fakeCustomerSummary(['this_month' => ['revenue_excl_vat' => 1234.5]]);

    $thread = EmailThread::where('email_account_id', $account->id)->with(['messages', 'project', 'account'])->first();
    $context = app(EmailContextBuilder::class)->build($thread);

    expect($context)->toContain('Gekoppeld contact');
    expect($context)->toContain('Acme BV');
    expect($context)->toContain('account_type: advertiser');

    // The link is authoritative: we resolve it directly, never search by email.
    Http::assertSent(fn ($request) => str_contains($request->url(), '/users/42/summary'));
});

it('flattens nested API values so the panel can render them', function () {
    $account = accountWithSupportApi();
    inboundThreadFor($account);

    EmailContactLink::create([
        'email_account_id' => $account->id,
        'email' => 'klant@voorbeeld.nl',
        'external_resource' => EmailContactLink::RESOURCE_USERS,
        'external_id' => '42',
        'label' => 'Acme BV',
    ]);

    fakeCustomerSummary(['this_month' => ['revenue_excl_vat' => 1234.5]]);

    $resolved = app(ContactLinkSuggester::class)
        ->resolve(EmailContactLink::first());

    expect($resolved['fields'])->toHaveKey('this_month.revenue_excl_vat');
    expect($resolved['fields']['this_month.revenue_excl_vat'])->toBe(1234.5);
});

it('returns the confirmed link from the MCP lookup tool', function () {
    $account = accountWithSupportApi();
    $project = Project::find($account->project_id);

    EmailContactLink::create([
        'email_account_id' => $account->id,
        'email' => 'klant@voorbeeld.nl',
        'external_resource' => EmailContactLink::RESOURCE_USERS,
        'external_id' => '42',
        'label' => 'Acme BV',
    ]);

    fakeCustomerSummary();

    ProjectDatabaseServer::tool(LookupContactByEmailTool::class, [
        'project' => $project->key,
        'email' => 'klant@voorbeeld.nl',
    ])->assertOk()->assertSee('Confirmed link');
});

it('never leaks a credential-like field into the panel', function () {
    $account = accountWithSupportApi();
    inboundThreadFor($account);

    EmailContactLink::create([
        'email_account_id' => $account->id,
        'email' => 'klant@voorbeeld.nl',
        'external_resource' => EmailContactLink::RESOURCE_USERS,
        'external_id' => '42',
        'label' => 'Acme BV',
    ]);

    fakeCustomerSummary(['api_token' => 'super-secret']);

    $resolved = app(ContactLinkSuggester::class)
        ->resolve(EmailContactLink::first());

    expect($resolved['fields']['api_token'])->not->toBe('super-secret');
});
