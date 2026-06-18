<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tables that become workspace-scoped. Tasks inherit their workspace through
     * the project, so they are intentionally not listed here.
     *
     * @var list<string>
     */
    private const TABLES = ['users', 'clients', 'projects'];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                $blueprint->foreignId('workspace_id')
                    ->nullable()
                    ->after('id')
                    ->constrained()
                    ->nullOnDelete();

                $blueprint->index('workspace_id', "{$table}_workspace_id_index");
            });
        }

        $this->backfillExistingData();
    }

    /**
     * Existing installs predate workspaces. Give every user their own workspace
     * (matching self-registration, which starts a fresh tenant per signup) so
     * separate accounts do not end up sharing one another's data. Legacy clients
     * and projects carry no owner signal, so they fold into the oldest user's
     * workspace to stay visible. Fresh databases (e.g. the test suite) have no
     * rows and therefore get no workspace.
     */
    private function backfillExistingData(): void
    {
        $users = DB::table('users')->whereNull('workspace_id')->orderBy('id')->get(['id', 'name']);

        $oldestWorkspaceId = null;

        foreach ($users as $user) {
            $name = trim((string) $user->name);

            $workspaceId = DB::table('workspaces')->insertGetId([
                'name' => $name !== '' ? "{$name}'s werkruimte" : 'Mijn werkruimte',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('users')->where('id', $user->id)->update(['workspace_id' => $workspaceId]);

            $oldestWorkspaceId ??= $workspaceId;
        }

        $orphanWorkspaceId = $oldestWorkspaceId ?? DB::table('workspaces')->min('id');

        if ($orphanWorkspaceId === null) {
            $hasOrphans = DB::table('clients')->whereNull('workspace_id')->exists()
                || DB::table('projects')->whereNull('workspace_id')->exists();

            if ($hasOrphans) {
                $orphanWorkspaceId = DB::table('workspaces')->insertGetId([
                    'name' => 'Mijn werkruimte',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        if ($orphanWorkspaceId !== null) {
            DB::table('clients')->whereNull('workspace_id')->update(['workspace_id' => $orphanWorkspaceId]);
            DB::table('projects')->whereNull('workspace_id')->update(['workspace_id' => $orphanWorkspaceId]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                $blueprint->dropForeign(["{$table}_workspace_id_foreign"]);
                $blueprint->dropIndex("{$table}_workspace_id_index");
                $blueprint->dropColumn('workspace_id');
            });
        }
    }
};
