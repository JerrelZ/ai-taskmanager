<?php

namespace App\Services;

use App\Enums\TaskReadiness;
use App\Models\Task;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Judges whether a ticket holds enough context to be handed straight to Claude
 * Code as a paste-ready prompt. A cheap rule gate runs first so we never spend
 * an API call on tickets that obviously can't be ready (no repo, no
 * description, already closed). Tickets that pass the gate are scored by Claude
 * when an API key is configured, otherwise a heuristic keeps the feature working.
 *
 * @phpstan-type Assessment array{readiness: TaskReadiness, missing: list<string>, prompt: string, ai: bool}
 */
class TaskReadinessAssessor
{
    public function __construct(private readonly TaskPromptBuilder $promptBuilder) {}

    /**
     * @return Assessment
     */
    public function assess(Task $task): array
    {
        $task->loadMissing('project');

        $blockers = $this->ruleBlockers($task);

        if ($blockers !== []) {
            return [
                'readiness' => TaskReadiness::NotReady,
                'missing' => $blockers,
                'prompt' => $this->promptBuilder->build($task),
                'ai' => false,
            ];
        }

        $key = config('services.anthropic.key');

        if (blank($key)) {
            return $this->heuristic($task);
        }

        return $this->withClaude($task, (string) $key) ?? $this->heuristic($task);
    }

    /**
     * Cheap, deterministic reasons a ticket cannot be ready — no API call needed.
     *
     * @return list<string>
     */
    private function ruleBlockers(Task $task): array
    {
        $blockers = [];

        if ($task->isComplete()) {
            $blockers[] = 'Ticket is al afgerond of geannuleerd.';
        }

        if (blank($task->project?->repo_path)) {
            $blockers[] = 'Het project heeft geen repository-pad ingesteld.';
        }

        if (blank($task->description)) {
            $blockers[] = 'Ticket heeft geen omschrijving — alleen een titel is te weinig context.';
        }

        return $blockers;
    }

    /**
     * Ask Claude to score readiness and sharpen the prompt.
     *
     * @return Assessment|null Null on any failure so the caller can fall back.
     */
    private function withClaude(Task $task, string $key): ?array
    {
        $base = $this->promptBuilder->build($task);

        try {
            $response = Http::withHeaders([
                'x-api-key' => $key,
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ])->timeout(20)->post('https://api.anthropic.com/v1/messages', [
                'model' => config('services.anthropic.readiness_model', 'claude-haiku-4-5'),
                'max_tokens' => 1500,
                'system' => $this->systemPrompt(),
                'messages' => [[
                    'role' => 'user',
                    'content' => $base,
                ]],
            ]);

            if (! $response->successful()) {
                return null;
            }

            $parsed = $this->parseJson((string) $response->json('content.0.text', ''));

            if ($parsed === null) {
                return null;
            }

            $readiness = TaskReadiness::tryFrom((string) ($parsed['readiness'] ?? '')) ?? TaskReadiness::Almost;
            $missing = $this->normaliseMissing($parsed['missing'] ?? []);
            $refined = trim((string) ($parsed['prompt'] ?? ''));

            // Only trust a sharpened prompt when the ticket is actually workable.
            $prompt = ($readiness !== TaskReadiness::NotReady && $refined !== '') ? $refined : $base;

            return [
                'readiness' => $readiness,
                'missing' => $missing,
                'prompt' => $prompt,
                'ai' => true,
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    private function systemPrompt(): string
    {
        return 'Je beoordeelt of een softwareticket genoeg context bevat om in één keer door een AI-coding-assistent (Claude Code) opgelost te worden, '
            .'zonder dat de ontwikkelaar nog vragen hoeft te stellen. '
            .'Antwoord UITSLUITEND met geldige JSON in de vorm: '
            .'{"readiness": "ready|almost|not_ready", "missing": ["..."], "prompt": "..."}. '
            ."\n- readiness=ready: alles is duidelijk (wat, waar, verwacht resultaat). "
            .'readiness=almost: oplosbaar maar er ontbreekt nuttige context. readiness=not_ready: te vaag om aan te beginnen. '
            ."\n- missing: korte Nederlandse punten over wat er ontbreekt (lege lijst als niets ontbreekt). Verzin geen eisen die er niet toe doen. "
            ."\n- prompt: een aangescherpte, plak-klare Nederlandse prompt voor Claude Code op basis van de aangeleverde ticketinhoud. "
            .'Behoud alle concrete feiten en repository-context; verzin geen nieuwe feiten, code of bestandsnamen.';
    }

    /**
     * Heuristic used when no API key is set: passing the rule gate is enough to
     * be considered ready, with the plain built prompt.
     *
     * @return Assessment
     */
    private function heuristic(Task $task): array
    {
        return [
            'readiness' => TaskReadiness::Ready,
            'missing' => [],
            'prompt' => $this->promptBuilder->build($task),
            'ai' => false,
        ];
    }

    /**
     * @param  mixed  $missing
     * @return list<string>
     */
    private function normaliseMissing($missing): array
    {
        if (! is_array($missing)) {
            return [];
        }

        return collect($missing)
            ->filter(fn ($item) => is_string($item) && trim($item) !== '')
            ->map(fn (string $item) => Str::limit(trim($item), 200))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parseJson(string $text): ?array
    {
        $decoded = json_decode($text, true);

        if (is_array($decoded)) {
            return $decoded;
        }

        if (preg_match('/\{.*\}/s', $text, $matches) === 1) {
            $decoded = json_decode($matches[0], true);

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }
}
