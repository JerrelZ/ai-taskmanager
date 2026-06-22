<?php

namespace App\Livewire\Concerns;

use App\Models\Task;
use Illuminate\Database\Eloquent\Builder;

/**
 * Cheap polling fallback for the live ticket board: when Reverb is unavailable
 * the board still catches up within a few seconds, without re-querying the whole
 * board every tick.
 *
 * Each poll runs one tiny aggregate (row count + newest updated_at) and only
 * triggers the expensive cache-busting refresh when that signature changed.
 * Livewire pauses polling in background tabs, so the idle cost is negligible.
 */
trait PollsLiveBoard
{
    /** Signature of the board state at the last refresh, to detect changes. */
    public ?string $boardSignature = null;

    public function pollBoard(): void
    {
        $signature = $this->boardSignature();

        if ($signature === $this->boardSignature) {
            $this->skipRender();

            return;
        }

        $this->boardSignature = $signature;
        $this->forgetBoardCache();
    }

    /**
     * Record the current signature so the next poll knows the board is already
     * up to date (e.g. right after a live broadcast or local mutation).
     */
    protected function rememberBoardSignature(): void
    {
        $this->boardSignature = $this->boardSignature();
    }

    protected function boardSignature(): string
    {
        $row = $this->boardSignatureScope()
            ->selectRaw('count(*) as c, max(updated_at) as m')
            ->first();

        return ((int) ($row->c ?? 0)).'|'.((string) ($row->m ?? ''));
    }

    /**
     * Root tasks whose changes this board should react to.
     *
     * @return Builder<Task>
     */
    abstract protected function boardSignatureScope(): Builder;

    abstract protected function forgetBoardCache(): void;
}
