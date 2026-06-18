<?php

namespace App\Console\Commands\Workspaces;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('workspace:member
    {email : The user that becomes a member}
    {workspace : Workspace id, or a name to find/create}
    {--active : Also make it the user\'s active workspace}')]
#[Description('Add a user to a workspace (creating the workspace by name if needed). Used to set up shared workspaces after a fresh migrate, since there is no invite UI yet.')]
class AddWorkspaceMember extends Command
{
    public function handle(): int
    {
        $user = User::firstWhere('email', $this->argument('email'));

        if ($user === null) {
            $this->error("Geen gebruiker met e-mail {$this->argument('email')}.");

            return self::FAILURE;
        }

        $workspace = $this->resolveWorkspace($this->argument('workspace'));

        $user->workspaces()->syncWithoutDetaching([$workspace->id]);

        if ($this->option('active') || $user->workspace_id === null) {
            $user->update(['workspace_id' => $workspace->id]);
        }

        $active = $user->fresh()->workspace_id === $workspace->id ? ' (actief)' : '';
        $this->info("{$user->email} is nu lid van '{$workspace->name}' (id {$workspace->id}){$active}.");

        return self::SUCCESS;
    }

    /**
     * Resolve a workspace by numeric id, or find/create one by name.
     */
    private function resolveWorkspace(string $workspace): Workspace
    {
        if (ctype_digit($workspace)) {
            return Workspace::findOrFail((int) $workspace);
        }

        return Workspace::firstOrCreate(['name' => $workspace]);
    }
}
