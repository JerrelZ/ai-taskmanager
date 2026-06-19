<x-mail::message>
# Je bent uitgenodigd 👋

@if ($inviterName)
**{{ $inviterName }}** nodigt je uit om mee te werken in **{{ $workspaceName }}**.
@else
Je bent uitgenodigd om mee te werken in **{{ $workspaceName }}**.
@endif

Klik op de knop hieronder om je account aan te maken en je eigen wachtwoord in te stellen.

<x-mail::button :url="$acceptUrl">
Uitnodiging accepteren
</x-mail::button>

Deze uitnodiging verloopt over 7 dagen. Heb je deze e-mail onverwacht ontvangen, dan kun je hem negeren.

Groeten,<br>
{{ config('app.name') }}
</x-mail::message>
