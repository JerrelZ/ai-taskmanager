<?php

namespace App\Livewire\Email;

use App\Enums\EmailCategory;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Enums\UserRole;
use App\Models\EmailAccount;
use App\Models\EmailContactLink;
use App\Models\EmailMessage;
use App\Models\EmailThread;
use App\Models\Project;
use App\Models\ReplyTemplate;
use App\Models\Task;
use App\Models\User;
use App\Services\Email\ContactLinkSuggester;
use App\Services\Email\EmailContextBuilder;
use App\Services\Email\EmailContextInvestigator;
use App\Services\Email\EmailReplyDrafter;
use App\Services\Email\EmailSender;
use App\Services\Email\ImapClientFactory;
use App\Support\EmailBody;
use App\Support\TaskActivity;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Attributes\Validate;
use Livewire\Component;

#[Title('Inbox')]
class Inbox extends Component
{
    public Project $project;

    #[Url]
    public ?int $selectedThreadId = null;

    #[Url(as: 'cat')]
    public ?string $categoryFilter = null;

    /** Markdown context for the selected thread, lazily built. */
    public ?string $context = null;

    #[Validate('required|string')]
    public string $replyBody = '';

    // Account settings form.
    public string $emailAddress = '';

    public string $imapHost = '';

    public int $imapPort = 993;

    public string $imapEncryption = 'ssl';

    public string $smtpHost = '';

    public int $smtpPort = 465;

    public string $smtpEncryption = 'ssl';

    public string $username = '';

    public string $accountPassword = '';

    public ?int $syncDays = 30;

    // Manual contact-link form (fallback when no suggestions match).
    public string $manualTable = '';

    public string $manualIdColumn = 'id';

    public string $manualId = '';

    // Suggestions hit the external DB, so only load them when the user opens the panel.
    public bool $showLinkPanel = false;

    // Create-ticket form.
    public string $ticketTitle = '';

    public string $ticketDescription = '';

    public string $ticketPriority = 'none';

    public ?int $ticketAssigneeId = null;

    // New reply-template form.
    public string $templateName = '';

    public string $templateBody = '';

    public function mount(Project $project): void
    {
        $user = Auth::user();
        abort_if(! $user->isTeam() && $project->client_id !== $user->client_id, 403);

        $this->project = $project;
        $this->fillSettingsForm();
    }

    public function account(): ?EmailAccount
    {
        return EmailAccount::where('project_id', $this->project->id)->first();
    }

    /**
     * Threads grouped by AI category for the left pane.
     *
     * @return Collection<string, Collection<int, EmailThread>>
     */
    #[Computed]
    public function groupedThreads(): Collection
    {
        $query = EmailThread::query()
            ->where('project_id', $this->project->id)
            ->visibleTo(Auth::user())
            ->withCount('messages')
            ->orderByDesc('last_message_at');

        if ($this->categoryFilter !== null) {
            $query->where('ai_category', $this->categoryFilter);
        }

        return $query->get()->groupBy(fn (EmailThread $thread): string => $thread->ai_category ?? 'uncategorised');
    }

    #[Computed]
    public function selectedThread(): ?EmailThread
    {
        if ($this->selectedThreadId === null) {
            return null;
        }

        return EmailThread::query()
            ->where('project_id', $this->project->id)
            ->visibleTo(Auth::user())
            ->with(['messages' => fn ($q) => $q->orderBy('sent_at'), 'assignee:id,name'])
            ->find($this->selectedThreadId);
    }

    public function selectThread(int $threadId): void
    {
        $this->selectedThreadId = $threadId;
        $this->context = null;
        $this->showLinkPanel = false;
        unset($this->linkedContact, $this->linkedContactRow, $this->contactSuggestions, $this->threadTicket);

        $thread = $this->selectedThread();

        if ($thread !== null && ! $thread->is_read) {
            $thread->forceFill(['is_read' => true])->save();
        }
    }

    /**
     * Build the context panel on demand (wire:init) so it never blocks the inbox render.
     */
    public function loadContext(EmailContextBuilder $builder): void
    {
        $thread = $this->selectedThread();

        $this->context = $thread !== null ? $builder->build($thread) : null;
    }

    /**
     * The address of the latest inbound message in the selected thread — the
     * sender we link to an external-database row.
     */
    private function senderEmail(): ?string
    {
        return $this->selectedThread()
            ?->messages
            ->where('direction', EmailMessage::DIRECTION_INBOUND)
            ->last()?->from_email;
    }

    /**
     * Public accessor for the view: the sender address eligible for linking.
     */
    public function senderForLink(): ?string
    {
        return $this->senderEmail();
    }

    #[Computed]
    public function linkedContact(): ?EmailContactLink
    {
        $account = $this->account();
        $email = $this->senderEmail();

        if ($account === null || $email === null) {
            return null;
        }

        return EmailContactLink::where('email_account_id', $account->id)
            ->where('email', $email)
            ->first();
    }

    /**
     * The resolved external row for the linked contact, for display.
     *
     * @return array{label: string, fields: array<string, mixed>}|null
     */
    #[Computed]
    public function linkedContactRow(): ?array
    {
        $link = $this->linkedContact();

        if ($link === null) {
            return null;
        }

        try {
            return app(ContactLinkSuggester::class)->resolve($link);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Suggested external rows for the sender, only when nothing is linked yet.
     *
     * @return array<int, array{table: string, id_column: string, id: string, label: string, preview: string}>
     */
    #[Computed]
    public function contactSuggestions(): array
    {
        $account = $this->account();
        $email = $this->senderEmail();

        if ($account === null || $email === null || blank($account->external_db_dsn)) {
            return [];
        }

        if ($this->linkedContact() !== null) {
            return [];
        }

        try {
            return app(ContactLinkSuggester::class)->suggest($account, $email);
        } catch (\Throwable) {
            return [];
        }
    }

    public function linkContact(string $table, string $idColumn, string $id, string $label = ''): void
    {
        abort_unless(Auth::user()->isTeam(), 403);

        $account = $this->account();
        $email = $this->senderEmail();

        if ($account === null || $email === null) {
            return;
        }

        // Identifiers are interpolated into queries elsewhere; never accept anything unsafe.
        if (preg_match('/^[A-Za-z0-9_]+$/', $table) !== 1 || preg_match('/^[A-Za-z0-9_]+$/', $idColumn) !== 1) {
            Flux::toast(variant: 'danger', text: __('Ongeldige tabel- of kolomnaam.'));

            return;
        }

        EmailContactLink::updateOrCreate(
            ['email_account_id' => $account->id, 'email' => $email],
            [
                'external_table' => $table,
                'external_id_column' => $idColumn,
                'external_id' => $id,
                'label' => $label !== '' ? $label : null,
                'linked_by' => Auth::id(),
            ],
        );

        $this->refreshContactState();
        Flux::toast(variant: 'success', text: __('Afzender gekoppeld.'));
    }

    public function linkManual(): void
    {
        abort_unless(Auth::user()->isTeam(), 403);

        $validated = $this->validate([
            'manualTable' => ['required', 'string', 'regex:/^[A-Za-z0-9_]+$/'],
            'manualIdColumn' => ['required', 'string', 'regex:/^[A-Za-z0-9_]+$/'],
            'manualId' => ['required', 'string', 'max:255'],
        ]);

        $this->linkContact($validated['manualTable'], $validated['manualIdColumn'], $validated['manualId']);

        $this->manualTable = '';
        $this->manualIdColumn = 'id';
        $this->manualId = '';
    }

    public function unlinkContact(): void
    {
        abort_unless(Auth::user()->isTeam(), 403);

        $this->linkedContact()?->delete();

        $this->refreshContactState();
        Flux::toast(variant: 'success', text: __('Koppeling verwijderd.'));
    }

    /**
     * Drop cached contact computeds and force the context panel to rebuild.
     */
    private function refreshContactState(): void
    {
        unset($this->linkedContact, $this->linkedContactRow, $this->contactSuggestions);
        $this->context = null;
        $this->showLinkPanel = false;
    }

    /**
     * An existing ticket created from the selected thread, if any (prevents duplicates).
     */
    #[Computed]
    public function threadTicket(): ?Task
    {
        if ($this->selectedThreadId === null) {
            return null;
        }

        return Task::where('email_thread_id', $this->selectedThreadId)->first();
    }

    /**
     * Team members who can be assigned a ticket.
     *
     * @return Collection<int, User>
     */
    #[Computed]
    public function assignableUsers(): Collection
    {
        return User::query()
            ->whereIn('role', [UserRole::Admin->value, UserRole::Member->value])
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    public function openTicketModal(): void
    {
        abort_unless(Auth::user()->isTeam(), 403);

        $thread = $this->selectedThread();

        if ($thread === null) {
            return;
        }

        $latestInbound = $thread->messages->where('direction', EmailMessage::DIRECTION_INBOUND)->last();

        $this->ticketTitle = Str::limit($thread->subject ?: __('E-mail van :sender', [
            'sender' => $latestInbound?->from_email ?? __('onbekend'),
        ]), 240, '');

        $this->ticketDescription = $this->ticketDescriptionFor($thread, $latestInbound);
        $this->ticketPriority = TaskPriority::None->value;
        $this->ticketAssigneeId = null;

        Flux::modal('create-ticket')->show();
    }

    /**
     * Let Claude investigate the external database and append the structured
     * context (entities with ids) to the ticket description.
     */
    public function enrichTicketContext(EmailContextInvestigator $investigator): void
    {
        abort_unless(Auth::user()->isTeam(), 403);

        $thread = $this->selectedThread();

        if ($thread === null) {
            return;
        }

        try {
            $result = $investigator->investigate($thread);
            $this->ticketDescription = trim($this->ticketDescription."\n\n".$result['markdown']);
            Flux::toast(variant: 'success', text: trans_choice(
                '{0}Geen records gevonden.|[1,*]:count record(s) gevonden.',
                count($result['entities']),
                ['count' => count($result['entities'])],
            ));
        } catch (\Throwable $e) {
            Flux::toast(variant: 'danger', text: __('Context ophalen mislukt: :error', ['error' => $e->getMessage()]));
        }
    }

    public function createTicket(): void
    {
        abort_unless(Auth::user()->isTeam(), 403);

        $thread = $this->selectedThread();

        if ($thread === null) {
            return;
        }

        $validated = $this->validate([
            'ticketTitle' => ['required', 'string', 'max:255'],
            'ticketDescription' => ['nullable', 'string', 'max:5000'],
            'ticketPriority' => ['required', 'string', 'in:none,urgent,high,medium,low'],
            'ticketAssigneeId' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $priority = TaskPriority::from($validated['ticketPriority']);
        $maxPosition = (int) $this->project->rootTasks()->where('status', TaskStatus::Backlog->value)->max('position');

        $task = $this->project->tasks()->create([
            'email_thread_id' => $thread->id,
            'title' => $validated['ticketTitle'],
            'description' => $validated['ticketDescription'] ?: null,
            'status' => TaskStatus::Backlog,
            'priority' => $priority,
            'assignee_id' => $validated['ticketAssigneeId'],
            'position' => $maxPosition + 1,
            'created_by' => Auth::id(),
        ]);

        TaskActivity::log($task, 'created');

        unset($this->threadTicket);
        Flux::modal('create-ticket')->close();
        Flux::toast(variant: 'success', text: __('Ticket :id aangemaakt.', ['id' => $task->identifier()]));
    }

    /**
     * Build a useful ticket description: the AI summary when available, plus the
     * sender and the freshly written part of the latest inbound message.
     */
    private function ticketDescriptionFor(EmailThread $thread, ?EmailMessage $latestInbound): string
    {
        $parts = [];

        if (filled($thread->ai_summary)) {
            $parts[] = $thread->ai_summary;
        }

        if ($latestInbound !== null) {
            $parts[] = __('Van: :sender', ['sender' => $latestInbound->from_email ?? __('onbekend')]);

            $body = EmailBody::split($latestInbound->text_body, $latestInbound->html_body)['visible'];

            if (filled($body)) {
                $parts[] = Str::limit($body, 1500);
            }
        }

        return implode("\n\n", $parts);
    }

    // ----- Assign thread to a teammate -------------------------------------

    public function assignThread(?int $userId): void
    {
        abort_unless(Auth::user()->isTeam(), 403);

        $thread = $this->selectedThread();

        if ($thread === null) {
            return;
        }

        if ($userId !== null && ! $this->assignableUsers->contains('id', $userId)) {
            return;
        }

        $thread->forceFill(['assignee_id' => $userId])->save();

        unset($this->selectedThread);
        Flux::toast(variant: 'success', text: $userId === null
            ? __('Toewijzing verwijderd.')
            : __('Gesprek toegewezen.'));
    }

    // ----- AI draft reply ---------------------------------------------------

    public function draftReply(EmailReplyDrafter $drafter): void
    {
        abort_unless(Auth::user()->isTeam(), 403);

        $thread = $this->selectedThread();

        if ($thread === null) {
            return;
        }

        try {
            $this->replyBody = $drafter->draft($thread, Auth::user());
            Flux::toast(variant: 'success', text: __('Concept gegenereerd — controleer voor verzending.'));
        } catch (\Throwable $e) {
            Flux::toast(variant: 'danger', text: __('Concept mislukt: :error', ['error' => $e->getMessage()]));
        }
    }

    // ----- Reply templates --------------------------------------------------

    /**
     * Templates available in this project (project-specific plus global).
     *
     * @return Collection<int, ReplyTemplate>
     */
    #[Computed]
    public function replyTemplates(): Collection
    {
        return ReplyTemplate::query()
            ->forProject($this->project->id)
            ->orderBy('name')
            ->get();
    }

    public function insertTemplate(int $templateId): void
    {
        $template = $this->replyTemplates->firstWhere('id', $templateId);

        if ($template === null) {
            return;
        }

        $this->replyBody = $this->fillPlaceholders($template->body);
    }

    public function saveTemplate(): void
    {
        abort_unless(Auth::user()->isTeam(), 403);

        $validated = $this->validate([
            'templateName' => ['required', 'string', 'max:120'],
            'templateBody' => ['required', 'string', 'max:5000'],
        ]);

        ReplyTemplate::create([
            'project_id' => $this->project->id,
            'name' => $validated['templateName'],
            'body' => $validated['templateBody'],
            'created_by' => Auth::id(),
        ]);

        $this->templateName = '';
        $this->templateBody = '';
        unset($this->replyTemplates);
        Flux::toast(variant: 'success', text: __('Sjabloon opgeslagen.'));
    }

    public function deleteTemplate(int $templateId): void
    {
        abort_unless(Auth::user()->isTeam(), 403);

        // Only project-scoped templates can be removed here; global ones are shared.
        ReplyTemplate::where('id', $templateId)
            ->where('project_id', $this->project->id)
            ->delete();

        unset($this->replyTemplates);
    }

    /**
     * Replace {{sender}}, {{contact}} and {{agent}} tokens with concrete values.
     */
    private function fillPlaceholders(string $body): string
    {
        $sender = $this->senderEmail() ?? '';
        $contact = $this->linkedContact()?->label ?: $sender;

        return str_replace(
            ['{{sender}}', '{{contact}}', '{{agent}}'],
            [$sender, $contact, Auth::user()->name],
            $body,
        );
    }

    public function categoryLabel(string $key): string
    {
        return $key === 'uncategorised'
            ? __('Ongecategoriseerd')
            : EmailCategory::fromValue($key)->label();
    }

    public function categoryColor(string $key): string
    {
        return $key === 'uncategorised'
            ? 'zinc'
            : EmailCategory::fromValue($key)->color();
    }

    /**
     * Send a reply to the latest inbound message in the selected thread.
     */
    public function sendReply(EmailSender $sender): void
    {
        abort_unless(Auth::user()->isTeam(), 403);
        $this->validateOnly('replyBody');

        $thread = $this->selectedThread();
        $target = $thread?->messages->where('direction', EmailMessage::DIRECTION_INBOUND)->last();

        if ($target === null) {
            Flux::toast(variant: 'warning', text: __('Geen bericht om op te antwoorden.'));

            return;
        }

        try {
            $sender->reply($target, $this->replyBody);
        } catch (\Throwable $e) {
            Flux::toast(variant: 'danger', text: __('Versturen mislukt: :error', ['error' => $e->getMessage()]));

            return;
        }

        $this->replyBody = '';
        unset($this->selectedThread);
        Flux::toast(variant: 'success', text: __('Antwoord verstuurd.'));
    }

    public function openSettings(): void
    {
        abort_unless(Auth::user()->isTeam(), 403);
        $this->fillSettingsForm();
        Flux::modal('inbox-settings')->show();
    }

    public function saveAccount(): void
    {
        abort_unless(Auth::user()->isTeam(), 403);

        $validated = $this->validate([
            'emailAddress' => 'required|email',
            'imapHost' => 'required|string',
            'imapPort' => 'required|integer',
            'smtpHost' => 'required|string',
            'smtpPort' => 'required|integer',
            'username' => 'required|string',
            'accountPassword' => 'nullable|string',
            'syncDays' => 'nullable|integer|min:1|max:3650',
        ]);

        $existing = $this->account();

        $attributes = [
            'email_address' => $validated['emailAddress'],
            'imap_host' => $validated['imapHost'],
            'imap_port' => $validated['imapPort'],
            'imap_encryption' => $this->imapEncryption,
            'smtp_host' => $validated['smtpHost'],
            'smtp_port' => $validated['smtpPort'],
            'smtp_encryption' => $this->smtpEncryption,
            'username' => $validated['username'],
            'sync_days' => $validated['syncDays'],
        ];

        // Only overwrite the stored password when a new one is entered.
        if (filled($this->accountPassword)) {
            $attributes['password'] = $this->accountPassword;
        }

        if ($existing !== null) {
            $existing->update($attributes);
        } else {
            EmailAccount::create([
                'project_id' => $this->project->id,
                'password' => $this->accountPassword,
                ...$attributes,
            ]);
        }

        $this->accountPassword = '';
        Flux::modal('inbox-settings')->close();
        Flux::toast(variant: 'success', text: __('Inbox opgeslagen.'));
    }

    public function testConnection(ImapClientFactory $factory): void
    {
        abort_unless(Auth::user()->isTeam(), 403);

        $account = $this->account();

        if ($account === null) {
            Flux::toast(variant: 'warning', text: __('Sla eerst de inbox op.'));

            return;
        }

        try {
            $connection = $factory->connect($account);
            $connection->selectFolder('INBOX');
            $connection->disconnect();
            Flux::toast(variant: 'success', text: __('Verbinding gelukt.'));
        } catch (\Throwable $e) {
            Flux::toast(variant: 'danger', text: __('Verbinding mislukt: :error', ['error' => $e->getMessage()]));
        }
    }

    private function fillSettingsForm(): void
    {
        $account = $this->account();

        if ($account === null) {
            return;
        }

        $this->emailAddress = $account->email_address;
        $this->imapHost = $account->imap_host;
        $this->imapPort = $account->imap_port;
        $this->imapEncryption = $account->imap_encryption;
        $this->smtpHost = $account->smtp_host;
        $this->smtpPort = $account->smtp_port;
        $this->smtpEncryption = $account->smtp_encryption;
        $this->username = $account->username;
        $this->syncDays = $account->sync_days;
    }

    public function render()
    {
        return view('livewire.email.inbox');
    }
}
