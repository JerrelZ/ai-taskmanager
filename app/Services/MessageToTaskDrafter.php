<?php

namespace App\Services;

use App\Models\Project;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class MessageToTaskDrafter
{
    /**
     * Turn a chat message into a draft ticket (title + description).
     * Uses Claude when an API key is configured, otherwise falls back
     * to a simple heuristic so the feature always works.
     *
     * @param  array<int, string>  $contextMessages  Recent conversation lines ("Naam: tekst"), oldest first, for richer drafting.
     * @return array{title: string, description: string, ai: bool}
     */
    public function draft(string $messageBody, ?Project $project = null, array $contextMessages = []): array
    {
        $key = config('services.anthropic.key');

        if (blank($key)) {
            return $this->fallback($messageBody);
        }

        try {
            $response = Http::withHeaders([
                'x-api-key' => $key,
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ])->timeout(20)->post('https://api.anthropic.com/v1/messages', [
                'model' => config('services.anthropic.model', 'claude-sonnet-4-6'),
                'max_tokens' => 500,
                'system' => 'Je zet een chatbericht om in een nette taak voor een task manager. '
                    .'Antwoord UITSLUITEND met geldige JSON: {"title": "...", "description": "..."}. '
                    .'Titel: kort, imperatief, Nederlands, max ~80 tekens. '
                    .'Beschrijving: verheldert beknopt wat er moet gebeuren; verzin geen feiten.',
                'messages' => [[
                    'role' => 'user',
                    'content' => $this->prompt($messageBody, $project, $contextMessages),
                ]],
            ]);

            if (! $response->successful()) {
                return $this->fallback($messageBody);
            }

            $parsed = $this->parseJson((string) $response->json('content.0.text', ''));

            if ($parsed === null) {
                return $this->fallback($messageBody);
            }

            return [
                'title' => Str::limit(trim($parsed['title'] ?? ''), 250, ''),
                'description' => trim($parsed['description'] ?? $messageBody),
                'ai' => true,
            ];
        } catch (\Throwable) {
            return $this->fallback($messageBody);
        }
    }

    /**
     * @param  array<int, string>  $contextMessages
     */
    private function prompt(string $messageBody, ?Project $project, array $contextMessages = []): string
    {
        $thread = '';

        if ($contextMessages !== []) {
            $lines = implode("\n", $contextMessages);
            $thread = "Gesprek tot nu toe (context, oudste eerst):\n\"\"\"\n{$lines}\n\"\"\"\n\n";
        }

        $context = '';

        if ($project !== null && filled($project->stack)) {
            $context = "\n\nProjectcontext (stack): {$project->stack}";
        }

        return $thread."Maak een ticket op basis van dit bericht als kern:\n\"\"\"\n{$messageBody}\n\"\"\"{$context}";
    }

    /**
     * @return array{title?: string, description?: string}|null
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

    /**
     * @return array{title: string, description: string, ai: bool}
     */
    private function fallback(string $messageBody): array
    {
        return [
            'title' => Str::limit(trim($messageBody), 80, ''),
            'description' => trim($messageBody),
            'ai' => false,
        ];
    }
}
