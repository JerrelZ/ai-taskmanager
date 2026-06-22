# AI-features overzicht

Dit document somt alle AI-functies in de app op, zodat we ze niet vergeten en
later weer kunnen aanzetten / uitbreiden.

## Aan/uit zetten

De **gebruikersgerichte AI-functies in de e-mailinbox** hangen achter één
schakelaar:

- Config: `config/features.php` → `'ai'`
- Env: `AI_FEATURES` (in `.env`)
- Nu: `AI_FEATURES=false` → verborgen in de front-end. De backend (jobs,
  services, kolommen) blijft intact; zet de env op `true` om alles terug te
  halen.

> De developer-functies (Claude Code / "prompt kopiëren") vallen **niet** onder
> deze schakelaar. Die zijn per gebruiker afgeschermd via de `can_copy_prompt`
> permissie en blijven dus zichtbaar voor wie die rechten heeft.

Na het wijzigen van `.env`: `php artisan config:clear`.

---

## 1. AI-conceptantwoord (e-mail) — *verborgen via `AI_FEATURES`*

Genereert met Claude een conceptantwoord op een e-mailthread, gevoed door de
gespreksgeschiedenis en gekoppelde klantcontext.

- **Front-end:** `resources/views/livewire/email/inbox.blade.php` — knop
  "AI-concept" (sparkles) boven het antwoordveld.
- **Backend:** `App\Livewire\Email\Inbox::draftReply()`,
  `App\Services\Email\EmailReplyDrafter`, `App\Services\Email\EmailContextBuilder`.
- **Config:** `services.anthropic.model`.

## 2. Thread samenvatten met AI (bij ticket aanmaken) — *verborgen via `AI_FEATURES`*

Vult de omschrijving van een nieuw ticket automatisch met een Claude-samenvatting
van de hele thread (incl. actiepunten).

- **Front-end:** inbox "Ticket aanmaken"-modal — knop "Samenvatten met AI".
- **Backend:** `App\Livewire\Email\Inbox::summariseThread()`,
  `App\Services\Email\EmailThreadSummarizer`.
- **Config:** `services.anthropic.model`.

## 3. AI-samenvatting per thread (inbox-lijst) — *verborgen via `AI_FEATURES`*

Toont een één-regelige AI-samenvatting onder het onderwerp in de threadlijst.

- **Front-end:** inbox threadlijst — `$thread->ai_summary`.
- **Backend:** kolom `email_threads.ai_summary`,
  `App\Jobs\Email\CategoriseEmailThread`, `App\Services\Email\EmailCategoriser`.

## 4. AI-categorie & groepering (inbox) — *verborgen via `AI_FEATURES`*

Classificeert threads (bug, vraag, feature, …) en groepeert + filtert de inbox
op die categorie. Met de schakelaar uit valt de inbox terug op één platte lijst.

- **Front-end:** inbox — categorie-filter (header) + categorie-badges boven de
  groepen. Fallback geregeld in `App\Livewire\Email\Inbox::groupedThreads()`.
- **Backend:** kolommen `email_threads.ai_category` / `ai_categorised_at`,
  `App\Enums\EmailCategory`, `App\Jobs\Email\CategoriseEmailThread`,
  `App\Services\Email\EmailCategoriser`.

---

## 5. Prompt kopiëren voor Claude Code — *zichtbaar (permissie `can_copy_prompt`)*

Kopieert de (AI-geslepen of vers gebouwde) ticketprompt om in Claude Code te
plakken.

- **Front-end:** task-card (hover), ticket-detailmodal, "Klaar voor Claude
  Code"-pagina, en de inbox-dropdown "Claude Code → Prompt kopiëren".
- **Backend:** `App\Livewire\Concerns\CopiesTaskPrompt`,
  `Task::claudeCodePrompt()` / `resolvedPrompt()`,
  `App\Services\TaskPromptBuilder`. Permissie: `User::canCopyPrompt()`.

## 6. Prompt-gereedheid ("Klaar voor Claude Code") — *zichtbaar*

Beoordeelt automatisch of een ticket genoeg context heeft om als prompt te
plakken (ready / bijna / niet) en toont wat er ontbreekt.

- **Front-end:** pagina "Klaar voor Claude Code" (sidebar), incl.
  herbeoordeel-knop.
- **Backend:** `App\Jobs\AssessTaskPromptReadiness`,
  `App\Services\TaskReadinessAssessor`, `App\Enums\TaskReadiness`, kolommen
  `tasks.ai_readiness` / `ai_missing` / `ai_prompt` / `ai_assessed_at`.
- **Config:** `services.anthropic.readiness_model`.

## 7. Claude Code headless run — *zichtbaar (team)*

Draait Claude Code headless tegen de project-repo voor een ticket en toont het
resultaat in een modal.

- **Front-end:** inbox-dropdown "Claude Code → Uitvoeren in repo" + resultaat-
  en prompt-modals.
- **Backend:** `App\Livewire\Email\Inbox::runClaudeCode()`,
  `App\Jobs\RunClaudeCodeForTask`, `App\Services\ClaudeCodeRunner`,
  `App\Models\ClaudeCodeRun`.
- **Config:** `services.claude_code.*` (binary, permission_mode, timeout).

---

## Anthropic-config (gedeeld)

- `services.anthropic.key` — API-key (`ANTHROPIC_API_KEY` / `CLAUDE_API_KEY`)
- `services.anthropic.model` — model voor concepten/samenvattingen (default
  `claude-sonnet-4-6`)
- `services.anthropic.readiness_model` — goedkoper model voor de
  prompt-gereedheidscheck (default `claude-haiku-4-5`)
