# Chat & messaging UX/UI verbeteringen

Voortgang van de afgesproken verbeteringen voor de berichten/chat (en task-crossover).
Volgorde: frontend quick wins → titel-badge → DB-features → zoeken → task-crossover → realtime.

Legenda: `[ ]` todo · `[~]` mee bezig · `[x]` klaar (met test)

## Snelle winst (frontend, hergebruikt bestaande infra)

- [x] **1. Plakken om te uploaden (Cmd/Ctrl+V)** — `chatComposer.onPaste()` →
  `$uploadMultiple('newChatAttachments', …)`; listener op de textarea.
- [x] **2. Dag-scheidingen in de thread** — "Vandaag / Gisteren / 18 juni" tussen
  dagovergangen in `thread.blade.php` (Dutch via `->locale('nl')->isoFormat()`).
- [x] **3. Scroll-naar-beneden knop** — sticky knop + "Nieuwe berichten" via `hasNew`
  in `chatThread`.
- [x] **5. URL's automatisch klikbaar** — was al aanwezig in `App\Support\Mentions::render()`.

## Titel-badge

- [x] **4. Document-titel ongelezen-badge** — `(3) Berichten` via meta-tag in head +
  `applyUnreadTitleBadge()`; messages-component pusht live via `unread-messages-changed`.
  Favicon-stip bewust gepunt (fragiel over SPA-navigatie); kan later met canvas.

## Middel (DB-backed)

- [x] **6. Concept per gesprek bewaren** — `chatComposer` draft-logica (load/save/clear)
  in localStorage per `draftKey` (`chat-conv-{id}` / `project-chat-{id}`); `wire:key` op
  composer forceert re-init bij wisselen.
- [x] **7. Reacties op berichten** — `message_reactions` tabel + `MessageReaction` model +
  `toggleReaction` (gedeelde `ManagesChatInteractions` trait); hover-palet + pills in thread.
- [x] **8. "Gelezen"-indicatie** — "Verzonden/Gelezen" onder mijn laatste DM-bericht
  o.b.v. pivot `last_read_at`.
- [x] **9. Antwoorden / quoten** — `reply_to_id` op messages, `replyTo`-relatie,
  `startReply`/`cancelReply`, quote in bubbel + reply-balk in composer. Werkt in beide chats.
- [~] **10. Berichten zoeken** — gesprekkenlijst-filter op titel + laatste bericht
  (`search`-property, live debounce) **klaar**. In-thread full-text zoeken met
  match-navigatie bewust uitgesteld (vereist scroll-naar-match UX); follow-up.

## Task-platform crossover

- [x] **11. `#123`-ticketreferenties als chips** — `Mentions::render` met projectcontext;
  chip linkt naar bord met `?openTask=` deep-link (Board `openTask` Url-param opent paneel).
- [x] **12. Slash-commando's in de composer** — `/ticket`/`/task` maakt ticket in
  projectgesprek (`handleSlashCommand` in trait) + command-autocomplete in composer.

## Groter (architectuur)

- [x] **13. Polling pauzeren bij verborgen tab** — al gedekt door Livewire: `wire:poll`
  throttelt automatisch 95% op de achtergrond (we gebruiken bewust géén `.keep-alive`).
  **Follow-up (apart, vereist dependency-goedkeuring):** Laravel Echo/Reverb voor échte
  realtime levering + typing-indicatoren.

---

### Werkafspraken
- Elke wijziging krijgt een test (Pest) of, als puur frontend, minimaal een render-assert.
- `vendor/bin/pint --dirty` + `npm run build` na PHP/JS-wijzigingen.
- Afvinken zodra test groen is.
