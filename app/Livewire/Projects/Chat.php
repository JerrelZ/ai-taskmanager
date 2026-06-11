<?php

namespace App\Livewire\Projects;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\Project;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

class Chat extends Component
{
    public Project $project;

    public Conversation $conversation;

    public string $body = '';

    public function mount(Project $project): void
    {
        $user = Auth::user();
        abort_if(! $user->isTeam() && $project->client_id !== $user->client_id, 403);

        $this->project = $project;
        $this->conversation = $project->channel();
    }

    /**
     * @return Collection<int, Message>
     */
    #[Computed]
    public function messages(): Collection
    {
        return $this->conversation->messages()->with('user')->get();
    }

    public function send(): void
    {
        $body = trim($this->body);

        if ($body === '') {
            return;
        }

        $this->conversation->postMessage(Auth::user(), $body);

        $this->body = '';

        unset($this->messages);
    }
}
