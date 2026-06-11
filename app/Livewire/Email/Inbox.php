<?php

namespace App\Livewire\Email;

use App\Enums\EmailCategory;
use App\Models\EmailAccount;
use App\Models\EmailMessage;
use App\Models\EmailThread;
use App\Models\Project;
use App\Services\Email\EmailContextBuilder;
use App\Services\Email\EmailSender;
use App\Services\Email\ImapClientFactory;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
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
            ->with(['messages' => fn ($q) => $q->orderBy('sent_at')])
            ->find($this->selectedThreadId);
    }

    public function selectThread(int $threadId): void
    {
        $this->selectedThreadId = $threadId;
        $this->context = null;

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
    }

    public function render()
    {
        return view('livewire.email.inbox');
    }
}
