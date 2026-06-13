<?php

namespace App\Services;

use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Runs Claude Code headlessly inside a project's repository. Defaults to "plan"
 * permission mode (read-only analysis — no file edits) so a ticket can be
 * investigated against the codebase without changing anything.
 */
class ClaudeCodeRunner
{
    public function run(string $repoPath, string $prompt): string
    {
        $path = $this->expand($repoPath);

        if (! is_dir($path)) {
            throw new RuntimeException("Repository-pad bestaat niet: {$repoPath}");
        }

        $binary = (string) config('services.claude_code.binary', 'claude');
        $mode = (string) config('services.claude_code.permission_mode', 'plan');

        $result = Process::path($path)
            ->timeout((int) config('services.claude_code.timeout', 600))
            ->run([$binary, '--print', '--permission-mode', $mode, $prompt]);

        if (! $result->successful()) {
            throw new RuntimeException(
                Str::limit(trim($result->errorOutput()) ?: 'Claude Code gaf een fout terug.', 2000)
            );
        }

        return trim($result->output());
    }

    /**
     * Expand a leading ~ to the user's home directory.
     */
    private function expand(string $path): string
    {
        if (str_starts_with($path, '~')) {
            $home = $_SERVER['HOME'] ?? getenv('HOME') ?: '';

            return $home.substr($path, 1);
        }

        return $path;
    }
}
