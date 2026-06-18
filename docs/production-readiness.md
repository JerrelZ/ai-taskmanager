# Productie-gereedheid: realtime, paginatie, workspace-security

Drie sporen die het platform bruikbaar maken voor klanten/collega's.
Legenda: `[ ]` todo · `[~]` mee bezig · `[x]` klaar (met test)

## 1. Workspace-security (data-isolatie) afmaken & hard testen

- [x] Audit lees-paden: listings (tickets/projecten/taken/messages/team) waren al
  gescoped via `visibleTo`/`inWorkspace`.
- [x] **IDOR's gedicht** (publieke Livewire-props zijn client-instelbaar):
  `TaskDetail::task()` hercheckt nu zichtbaarheid (dekt alle mutaties);
  `Tickets::markReviewed` en `Tickets::reorder` gescoped op zichtbare projecten;
  `Conversation::canAccess` projectkanaal nu via `isVisibleTo` (workspace-fence).
- [x] Klant ziet alleen eigen client-data (getest, cross-client in zelfde workspace).
- [x] Conversaties: projectkanaal lekt niet tussen workspaces (getest).
- [x] Isolatie-testsuite: `tests/Feature/WorkspaceIsolationTest.php` (8 tests).
- [ ] Follow-up: DM/groep-conversaties dragen geen `workspace_id` (toegang via
  lidmaatschap). Acceptabel nu; overweeg expliciete workspace-kolom later.

## 2. Thread-paginatie

- [x] Laatste 30 berichten (`messageLimit` in trait); `loadOlder()` laadt +30.
  Query: `reorder()->latest()->limit()` dan oplopend sorteren voor weergave.
- [x] "Toon oudere berichten"-knop; `chatThread.loadOlder()` behoudt scrollpositie
  (anker boven blijft staan); observer-guard voorkomt valse "nieuw"-melding bij prepend.
- [x] Test: alleen newest-30 geladen, `loadOlder` onthult de rest (ChatUxTest).

## 3. Realtime (Reverb + Echo)

- [x] `laravel/reverb` + `laravel-echo`/`pusher-js` geïnstalleerd; broadcasting +
  `config/reverb.php` + `routes/channels.php`; env in `.env`/`.env.example`.
- [x] `MessageSent` (ShouldBroadcast) op `private-conversation.{id}`; channel-auth via
  `Conversation::canAccess` (workspace-veilig). Dispatch in `postMessage`.
- [x] Chat-componenten luisteren via Echo (`getListeners` → `pollMessages`/`pollChat`);
  3s-poll blijft als fallback wanneer Reverb niet draait.
- [x] Typing-indicator via whisper (`chatComposer.whisperTyping` ↔ `chatThread`
  `listenForTyping`); defensief (no-op zonder Echo).
- [x] Test: `MessageSent` broadcast op juiste private channel (ChatUxTest).
- [ ] **Productie:** Reverb als apart proces draaien (`php artisan reverb:start`,
  supervisor/Cloud) + TLS/proxy op de ws-poort; `VITE_REVERB_*` bij build zetten.
- [ ] Follow-up: online/laatst-gezien (presence channel + heartbeat) — niet
  in deze ronde (testbaar gedrag vereist browser + draaiende Reverb).

---

### Werkafspraken
- Elke wijziging een test (Pest) of minimaal render/broadcast-assert.
- `vendor/bin/pint --dirty` + `npm run build` na wijzigingen.
- Queue worker draait al op productie. Reverb is een apart proces (`reverb:start`).
