<div>
    @if ($this->workspaces->count() > 1)
        <flux:dropdown position="top" align="start" class="w-full">
            <flux:button
                variant="subtle"
                size="sm"
                icon="building-office-2"
                icon:trailing="chevrons-up-down"
                class="w-full justify-start"
                data-test="workspace-switcher-button"
            >
                <span class="truncate">{{ $this->current?->name }}</span>
            </flux:button>

            <flux:menu>
                <flux:menu.radio.group>
                    @foreach ($this->workspaces as $workspace)
                        <flux:menu.item
                            wire:click="switch({{ $workspace->id }})"
                            :icon="$workspace->is($this->current) ? 'check' : null"
                        >
                            {{ $workspace->name }}
                        </flux:menu.item>
                    @endforeach
                </flux:menu.radio.group>
            </flux:menu>
        </flux:dropdown>
    @endif
</div>
