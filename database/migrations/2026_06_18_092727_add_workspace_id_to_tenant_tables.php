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
     * Existing installs predate workspaces: fold all current data into a single
     * default workspace so nothing becomes invisible. Fresh databases (e.g. the
     * test suite) have no rows and therefore get no workspace.
     */
    private function backfillExistingData(): void
    {
        $hasData = collect(self::TABLES)->contains(fn (string $table) => DB::table($table)->exists());

        if (! $hasData) {
            return;
        }

        $workspaceId = DB::table('workspaces')->insertGetId([
            'name' => 'Mijn werkruimte',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach (self::TABLES as $table) {
            DB::table($table)->whereNull('workspace_id')->update(['workspace_id' => $workspaceId]);
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
