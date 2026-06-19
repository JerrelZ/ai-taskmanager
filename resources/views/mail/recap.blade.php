@php($max = 5)
<x-mail::message>
# Hallo {{ $user->name }} 👋

Je dagelijkse overzicht van {{ now()->locale('nl')->translatedFormat('l j F') }}.

@if ($deadlines->isNotEmpty())
## ⏰ Deadlines

@foreach ($deadlines->take($max) as $task)
- {{ $task->due_date->isPast() ? '🔴' : '🗓️' }} **{{ $task->identifier() }}** — {{ $task->title }}
  <br>_{{ $task->due_date->isPast() ? 'Verlopen op' : 'Deadline' }} {{ $task->due_date->locale('nl')->translatedFormat('j M') }}_
@endforeach
@if ($deadlines->count() > $max)
_… en nog {{ $deadlines->count() - $max }} met een deadline._
@endif
@endif

@if ($recentActivity->isNotEmpty())
## 🔔 Activiteit op jouw tickets

@foreach ($recentActivity->take($max) as $activity)
- **{{ $activity->task?->identifier() }}** — {{ $activity->user?->name ?? 'Iemand' }} {{ $activity->description() }}
@endforeach
@if ($recentActivity->count() > $max)
_… en nog {{ $recentActivity->count() - $max }} updates._
@endif
@endif

@if ($unreadMessages > 0)
## 💬 Chat ({{ $unreadMessages }} ongelezen)

@foreach ($unreadMessagePreviews as $message)
- **{{ $message->user?->name ?? 'Onbekend' }}** in _{{ $message->conversation->titleFor($user) }}_:
  <br>{{ \Illuminate\Support\Str::limit(trim(strip_tags($message->body)), 120) }}
@endforeach
@if ($unreadMessages > $unreadMessagePreviews->count())
_… en nog {{ $unreadMessages - $unreadMessagePreviews->count() }} {{ ($unreadMessages - $unreadMessagePreviews->count()) === 1 ? 'bericht' : 'berichten' }}._
@endif

<x-mail::button :url="$messagesUrl" color="success">
Naar berichten
</x-mail::button>
@endif

@if ($assignedTasks->isNotEmpty())
## 📋 Jouw open tickets ({{ $assignedTasks->count() }})

@foreach ($assignedTasks->take($max) as $task)
- **{{ $task->identifier() }}** — {{ $task->title }} _({{ $task->status->label() }})_
@endforeach
@if ($assignedTasks->count() > $max)
_… en nog {{ $assignedTasks->count() - $max }} open tickets._
@endif
@endif

<x-mail::button :url="$ticketsUrl">
Open je tickets
</x-mail::button>

---
Je krijgt deze mail omdat er vandaag activiteit was op jouw werk. Niet meer ontvangen? Pas je voorkeuren aan in je accountinstellingen.

Met vriendelijke groet,<br>
{{ config('app.name') }}
</x-mail::message>
