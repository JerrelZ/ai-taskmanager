{{-- Marks a ticket imported from Linear as a small "L" dot; the original
     identifier and a hint to check Linear for comments live in the tooltip. --}}
@if ($task->linear_id)
    <flux:tooltip :content="__('Geïmporteerd uit Linear').' · '.$task->linear_id.' · '.__('bekijk daar voor reacties')">
        <span
            aria-label="{{ __('Linear-ticket').' '.$task->linear_id }}"
            class="inline-flex size-4 shrink-0 items-center justify-center rounded-full bg-indigo-500 text-[0.6rem] font-bold leading-none text-white dark:bg-indigo-400 dark:text-indigo-950"
        >L</span>
    </flux:tooltip>
@endif
