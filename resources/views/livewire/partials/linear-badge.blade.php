{{-- Marks a ticket imported from Linear and shows its original identifier, so
     you know to check Linear for that issue's comments. --}}
@if ($task->linear_id)
    <flux:tooltip :content="__('Geïmporteerd uit Linear — bekijk het ticket daar voor reacties')">
        <flux:badge size="sm" color="indigo" icon="arrow-down-tray" class="font-mono">{{ $task->linear_id }}</flux:badge>
    </flux:tooltip>
@endif
