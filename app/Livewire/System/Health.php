<?php

namespace App\Livewire\System;

use App\Support\SystemStatus;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Systeemstatus')]
class Health extends Component
{
    /** The single account allowed to view production status. */
    public const ALLOWED_EMAIL = 'jerrel@zendos.nl';

    public function mount(): void
    {
        abort_unless(Auth::user()?->email === self::ALLOWED_EMAIL, 403);
    }

    /**
     * @return array<int, array{key: string, label: string, status: string, message: string}>
     */
    #[Computed]
    public function checks(): array
    {
        return (new SystemStatus)->checks();
    }

    /**
     * Re-run all checks.
     */
    public function refresh(): void
    {
        unset($this->checks);
    }

    public function render(): View
    {
        return view('livewire.system.health');
    }
}
