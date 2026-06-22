<?php

namespace App\Http\Controllers;

use App\Models\Task;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class TicketController extends Controller
{
    /**
     * Resolve a ticket by its identifier (e.g. "WEB-12") and open it on its
     * project board. The trailing slug is decorative. If the identifier is one
     * the ticket used before it moved project, redirect to its current URL.
     */
    public function show(string $identifier): RedirectResponse
    {
        $identifier = strtoupper($identifier);
        $key = Str::beforeLast($identifier, '-');
        $number = (int) Str::afterLast($identifier, '-');

        $task = Task::query()
            ->whereHas('project', fn ($q) => $q->visibleTo(Auth::user())->where('key', $key))
            ->where('number', $number)
            ->first();

        if ($task !== null) {
            return redirect()->to($this->boardUrl($task));
        }

        // Not a current identifier: it may belong to a ticket that has since
        // moved. Send those links on to the ticket's current URL with a 301.
        $moved = Task::query()
            ->whereHas('project', fn ($q) => $q->visibleTo(Auth::user()))
            ->whereJsonContains('previous_identifiers', $identifier)
            ->first();

        abort_if($moved === null, 404);

        return redirect()->to($moved->ticketUrl(), 301);
    }

    private function boardUrl(Task $task): string
    {
        return route('projects.board', ['project' => $task->project_id, 'openTask' => $task->id]);
    }
}
