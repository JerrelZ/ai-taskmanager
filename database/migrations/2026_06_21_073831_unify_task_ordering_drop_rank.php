<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Root tasks now share one ordering per status across every project in a
     * workspace (the `position` column), so the project board and the
     * all-tickets board stay in sync. Seed that order from the old global
     * `rank` so the priority the team already built up carries over, then drop
     * the now-redundant `rank` column.
     */
    public function up(): void
    {
        $rows = DB::table('tasks')
            ->join('projects', 'tasks.project_id', '=', 'projects.id')
            ->whereNull('tasks.parent_id')
            ->orderBy('projects.workspace_id')
            ->orderBy('tasks.status')
            ->orderBy('tasks.rank')
            ->orderBy('tasks.id')
            ->get(['tasks.id', 'projects.workspace_id', 'tasks.status']);

        $counters = [];

        foreach ($rows as $row) {
            $key = $row->workspace_id.'|'.$row->status;
            $position = $counters[$key] ?? 0;

            DB::table('tasks')->where('id', $row->id)->update(['position' => $position]);

            $counters[$key] = $position + 1;
        }

        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex(['rank']);
            $table->dropColumn('rank');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->unsignedInteger('rank')->default(0)->after('position');
            $table->index('rank');
        });
    }
};
