{{--
    Shows the date a board is currently sorted on (created / last updated), so
    the order on screen explains itself. Renders nothing for other sort modes.
--}}
@php
    [$hintDate, $hintLabel] = match ($this->activeSort()) {
        'created_desc', 'created_asc' => [$task->created_at, __('Aangemaakt op')],
        'updated_desc' => [$task->updated_at, __('Bijgewerkt op')],
        default => [null, null],
    };
@endphp
@if ($hintDate)
    <span class="flex shrink-0 items-center gap-1 text-xs text-zinc-400"
        title="{{ $hintLabel }} {{ $hintDate->translatedFormat('j M Y H:i') }}">
        <flux:icon name="clock" variant="micro" />
        {{ $hintDate->isCurrentYear() ? $hintDate->translatedFormat('j M') : $hintDate->translatedFormat('j M Y') }}
    </span>
@endif
