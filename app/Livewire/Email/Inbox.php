<?php

namespace App\Livewire\Email;

use App\Enums\EmailCategory;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Enums\UserRole;
use App\Jobs\RunClaudeCodeForTask;
use App\Models\ClaudeCodeRun;
use App\Models\EmailAccount;
use App\Models\EmailContactLink;
use App\Models\EmailMessage;
use App\Models\EmailThread;
use App\Models\Project;
use App\Models\ReplyTemplate;
use App\Models\Task;
use App\Models\User;
use App\Notifications\InboxNotification;
use App\Services\Email\ContactLinkSuggester;
use App\Services\Email\EmailContextBuilder;
use App\Services\Email\EmailContextInvestigator;
use App\Services\Email\EmailReplyDrafter;
use App\Services\Email\EmailSender;
use App\Services\Email\ExternalProjectApi;
use App\Services\Email\ExternalProjectDb;
use App\Services\Email\ImapClientFactory;
use App\Support\EmailBody;
use App\Support\TaskActivity;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
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

    #[Url]
    public bool $showArchived = false;

    /** @var array<int, int> Thread ids selected for bulk actions. */
    public array $selectedThreads = [];

    /** On mobile, show the context pane instead of the thread pane. */
    public bool $mobileContext = false;

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

    // External database (read-only) settings.
    public string $dbHost = '';

    public ?int $dbPort = 3306;

    public string $dbDatabase = '';

    public string $dbUsername = '';

    public string $dbPassword = '';

    // External support API settings.
    public string $apiBaseUrl = '';

    public string $apiToken = '';

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

    // Generated Claude Code prompt for the thread's ticket.
    public string $claudeCodePrompt = '';

    // New reply-template form.
    public string $templateName = '';

    public string $templateBody = '';

    public function mount(Project $project): void
    {
        abort_unless($project->isVisibleTo(Auth::user()), 403);

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

        if ($this->showArchived) {
            $query->whereNotNull('archived_at');
        } else {
            // Hide archived and currently-snoozed threads from the working inbox.
            $query->whereNull('archived_at')
                ->where(fn ($q) => $q->whereNull('snoozed_until')->orWhere('snoozed_until', '<=', now()));
        }

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
            ->with(['messages' => fn ($q) => $q->orderBy('sent_at')->with('attachments'), 'assignee:id,name'])
            ->find($this->selectedThreadId);
    }

    public function selectThread(int $threadId): void
    {
        $this->selectedThreadId = $threadId;
        $this->context = null;
        $this->showLinkPanel = false;
        $this->mobileContext = false;
        unset($this->linkedContact, $this->linkedContactRow, $this->contactSuggestions, $this->threadTicket, $this->senderHistory);

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
     * @return Builder<EmailThread>
     */
    private function selectedThreadsQuery(): Builder
    {
        return EmailThread::where('project_id', $this->project->id)
            ->visibleTo(Auth::user())
            ->whereIn('id', $this->selectedThreads);
    }

    public function archiveSelected(): void
    {
        abort_unless(Auth::user()->isTeam(), 403);

        $count = $this->selectedThreadsQuery()->update(['archived_at' => now(), 'snoozed_until' => null]);

        $this->afterBulk(__(':count gesprek(ken) gearchiveerd.', ['count' => $count]));
    }

    public function assignSelected(?int $userId): void
    {
        abort_unless(Auth::user()->isTeam(), 403);

        if ($userId !== null && ! $this->assignableUsers->contains('id', $userId)) {
            return;
        }

        $count = $this->selectedThreadsQuery()->update(['assignee_id' => $userId]);

        $this->afterBulk(__(':count gesprek(ken) toegewezen.', ['count' => $count]));
    }

    public function markSelectedRead(): void
    {
        abort_unless(Auth::user()->isTeam(), 403);

        $count = $this->selectedThreadsQuery()->update(['is_read' => true]);

        $this->afterBulk(__(':count gesprek(ken) als gelezen gemarkeerd.', ['count' => $count]));
    }

    private function afterBulk(string $message): void
    {
        $this->selectedThreads = [];
        unset($this->groupedThreads, $this->selectedThread);
        Flux::toast(variant: 'success', text: $message);
    }

    public function archiveThread(int $threadId): void
    {
        abort_unless(Auth::user()->isTeam(), 403);

        EmailThread::where('project_id', $this->project->id)->whereKey($threadId)
            ->update(['archived_at' => now(), 'snoozed_until' => null]);

        if ($this->selectedThreadId === $threadId) {
            $this->selectedThreadId = null;
        }

        unset($this->groupedThreads, $this->selectedThread);
        Flux::toast(variant: 'success', text: __('Gesprek gearchiveerd.'));
    }

    public function unarchiveThread(int $threadId): void
    {
        abort_unless(Auth::user()->isTeam(), 403);

        EmailThread::where('project_id', $this->project->id)->whereKey($threadId)
            ->update(['archived_at' => null]);

        unset($this->groupedThreads);
        Flux::toast(variant: 'success', text: __('Gesprek teruggehaald.'));
    }

    public function snoozeThread(int $threadId, string $preset): void
    {
        abort_unless(Auth::user()->isTeam(), 403);

        $until = match ($preset) {
            'hours' => now()->addHours(3),
            'tomorrow' => now()->addDay()->setTime(8, 0),
            'week' => now()->addWeek()->setTime(8, 0),
            default => now()->addDay(),
        };

        EmailThread::where('project_id', $this->project->id)->whereKey($threadId)
            ->update(['snoozed_until' => $until, 'archived_at' => null]);

        if ($this->selectedThreadId === $threadId) {
            $this->selectedThreadId = null;
        }

        unset($this->groupedThreads, $this->selectedThread);
        Flux::toast(variant: 'success', text: __('Gesnoozed tot :when.', ['when' => $until->translatedFormat('D d M, H:i')]));
    }

    /**
     * Earlier conversations from the same sender in this project (a mini timeline).
     *
     * @return Collection<int, EmailThread>
     */
    #[Computed]
    public function senderHistory(): Collection
    {
        $email = $this->senderEmail();

        if ($email === null) {
            return collect();
        }

        return EmailThread::query()
            ->where('project_id', $this->project->id)
            ->where('id', '!=', $this->selectedThreadId)
            ->whereHas('messages', fn ($q) => $q->where('from_email', $email))
            ->withCount('messages')
            ->orderByDesc('last_message_at')
            ->limit(6)
            ->get();
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
            ->inWorkspace(Auth::user()->workspace_id)
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

    /**
     * Build the Claude Code prompt for the thread's ticket and open the modal.
     */
    public function openClaudeCodePrompt(): void
    {
        abort_unless(Auth::user()->isTeam(), 403);

        $ticket = $this->threadTicket();

        if ($ticket === null) {
            return;
        }

        $ticket->loadMissing('project');
        $this->claudeCodePrompt = $ticket->claudeCodePrompt();
        Flux::modal('claude-code-prompt')->show();
    }

    /**
     * Dispatch a headless Claude Code run for the thread's ticket against the
     * project repository, then show its (polling) result.
     */
    public function runClaudeCode(): void
    {
        abort_unless(Auth::user()->isTeam(), 403);

        $ticket = $this->threadTicket();

        if ($ticket === null) {
            return;
        }

        $ticket->loadMissing('project');

        if (blank($ticket->project?->repo_path)) {
            Flux::toast(variant: 'warning', text: __('Dit project heeft geen repository-pad ingesteld.'));

            return;
        }

        $run = ClaudeCodeRun::create([
            'task_id' => $ticket->id,
            'requested_by' => Auth::id(),
            'status' => ClaudeCodeRun::STATUS_PENDING,
            'prompt' => $ticket->claudeCodePrompt(),
        ]);

        RunClaudeCodeForTask::dispatch($run->id);

        Flux::modal('claude-code-run')->show();
        Flux::toast(variant: 'success', text: __('Claude Code gestart — dit kan even duren.'));
    }

    public function latestClaudeRun(): ?ClaudeCodeRun
    {
        $ticket = $this->threadTicket();

        if ($ticket === null) {
            return null;
        }

        return ClaudeCodeRun::where('task_id', $ticket->id)->latest()->first();
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

        $task = $this->project->tasks()->create([
            'email_thread_id' => $thread->id,
            'title' => $validated['ticketTitle'],
            'description' => $validated['ticketDescription'] ?: null,
            'status' => TaskStatus::Backlog,
            'priority' => $priority,
            'assignee_id' => $validated['ticketAssigneeId'],
            'position' => Task::nextRootPosition($this->project->workspace_id, TaskStatus::Backlog->value),
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

        // Notify the new assignee (but never yourself).
        if ($userId !== null && $userId !== Auth::id()) {
            $assignee = $this->assignableUsers->firstWhere('id', $userId);

            $assignee?->notify(new InboxNotification(
                title: __('Gesprek toegewezen'),
                body: __(':project — :subject', [
                    'project' => $this->project->name,
                    'subject' => $thread->subject ?: __('(geen onderwerp)'),
                ]),
                url: route('projects.inbox', $this->project).'?selectedThreadId='.$thread->id,
                icon: 'inbox-arrow-down',
            ));
        }

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
            'dbHost' => 'nullable|string',
            'dbPort' => 'nullable|integer',
            'dbDatabase' => 'nullable|string',
            'dbUsername' => 'nullable|string',
            'dbPassword' => 'nullable|string',
            'apiBaseUrl' => 'nullable|url',
            'apiToken' => 'nullable|string',
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
            'external_db_dsn' => $this->buildDsn($existing),
            'external_api_base_url' => filled($this->apiBaseUrl) ? $this->apiBaseUrl : null,
        ];

        // Only overwrite the stored password when a new one is entered.
        if (filled($this->accountPassword)) {
            $attributes['password'] = $this->accountPassword;
        }

        // Only overwrite the API token when a new one is entered.
        if (filled($this->apiToken)) {
            $attributes['external_api_token'] = $this->apiToken;
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
        $this->dbPassword = '';
        $this->apiToken = '';
        Flux::modal('inbox-settings')->close();
        Flux::toast(variant: 'success', text: __('Inbox opgeslagen.'));
    }

    /**
     * Build the external DB DSN from the form, preserving the stored password
     * when the field is left blank. Returns null when no database is entered.
     *
     * @return array<string, mixed>|null
     */
    private function buildDsn(?EmailAccount $existing): ?array
    {
        if (blank($this->dbHost) && blank($this->dbDatabase)) {
            return null;
        }

        $current = $existing?->external_db_dsn ?? [];

        return [
            'host' => $this->dbHost ?: '127.0.0.1',
            'port' => $this->dbPort ?: 3306,
            'database' => $this->dbDatabase,
            'username' => $this->dbUsername,
            'password' => filled($this->dbPassword) ? $this->dbPassword : ($current['password'] ?? ''),
        ];
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

    public function testExternalDb(ExternalProjectDb $db): void
    {
        abort_unless(Auth::user()->isTeam(), 403);

        try {
            $db->select($this->transientAccount(), 'SELECT 1');
            Flux::toast(variant: 'success', text: __('Database-verbinding gelukt.'));
        } catch (\Throwable $e) {
            Flux::toast(variant: 'danger', text: __('Database mislukt: :error', ['error' => $e->getMessage()]));
        }
    }

    public function testExternalApi(ExternalProjectApi $api): void
    {
        abort_unless(Auth::user()->isTeam(), 403);

        $account = $this->transientAccount();

        if (! $api->configured($account)) {
            Flux::toast(variant: 'warning', text: __('Vul eerst de API-URL en het token in.'));

            return;
        }

        try {
            $api->lookupUserByEmail($account, 'connectiontest@example.com');
            Flux::toast(variant: 'success', text: __('API-verbinding gelukt.'));
        } catch (\Throwable $e) {
            Flux::toast(variant: 'danger', text: __('API mislukt: :error', ['error' => $e->getMessage()]));
        }
    }

    /**
     * An unsaved EmailAccount built from the current form values, so connections
     * can be tested before saving. Falls back to stored secrets when blank.
     */
    private function transientAccount(): EmailAccount
    {
        $existing = $this->account();

        $account = new EmailAccount(['project_id' => $this->project->id]);
        $account->external_db_dsn = $this->buildDsn($existing);
        $account->external_api_base_url = $this->apiBaseUrl ?: $existing?->external_api_base_url;
        $account->external_api_token = filled($this->apiToken) ? $this->apiToken : $existing?->external_api_token;

        return $account;
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

        $dsn = $account->external_db_dsn ?? [];
        $this->dbHost = $dsn['host'] ?? '';
        $this->dbPort = $dsn['port'] ?? 3306;
        $this->dbDatabase = $dsn['database'] ?? '';
        $this->dbUsername = $dsn['username'] ?? '';
        $this->dbPassword = '';

        $this->apiBaseUrl = $account->external_api_base_url ?? '';
        $this->apiToken = '';
    }

    public function render()
    {
        return view('livewire.email.inbox');
    }
}
