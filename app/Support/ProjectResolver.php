<?php

namespace App\Support;

use App\Models\EmailAccount;
use App\Models\Project;

/**
 * Resolves a free-form project reference (key, id, or name) used by AI clients
 * into a Project and its linked email account, which carries the external
 * database credentials.
 */
class ProjectResolver
{
    public static function find(string $reference): ?Project
    {
        $reference = trim($reference);

        if ($reference === '') {
            return null;
        }

        return Project::query()
            ->when(ctype_digit($reference), fn ($q) => $q->orWhere('id', (int) $reference))
            ->orWhereRaw('LOWER(`key`) = ?', [mb_strtolower($reference)])
            ->orWhereRaw('LOWER(name) = ?', [mb_strtolower($reference)])
            ->first();
    }

    public static function account(Project $project): ?EmailAccount
    {
        return EmailAccount::where('project_id', $project->id)->first();
    }
}
