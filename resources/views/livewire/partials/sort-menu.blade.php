{{--
    Sort-mode menu for a ticket board. Works in any Livewire component that uses
    the SortsBoardTickets concern.
--}}
<flux:dropdown position="bottom" align="start">
    <flux:button size="sm" :variant="$this->isManualSort() ? 'subtle' : 'primary'" icon="arrows-up-down" icon:trailing="chevron-down">
        {{ $this->isManualSort() ? __('Sorteren') : $this->sortLabel() }}
    </flux:button>

    <flux:menu>
        <flux:menu.radio.group wire:model.live="sortBy">
            @foreach ($this->sortOptions() as $value => $label)
                <flux:menu.radio :value="$value">{{ $label }}</flux:menu.radio>
            @endforeach
        </flux:menu.radio.group>
    </flux:menu>
</flux:dropdown>
