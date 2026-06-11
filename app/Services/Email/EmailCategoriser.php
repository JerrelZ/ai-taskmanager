<?php

namespace App\Services\Email;

use App\Enums\EmailCategory;
use App\Models\EmailThread;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Classifies an email thread into a fixed category and a one-line summary.
 * Uses Claude when an API key is configured, otherwise falls back to a simple
 * heuristic so the feature always works.
 */
class EmailCategoriser
{
    /**
     * @return array{category: string, summary: string, ai: bool}
     */
    public function categorise(EmailThread $thread): array
    {
        $key = config('services.anthropic.key');

        if (blank($key)) {
            return $this->fallback($thread);
        }

        try {
            $response = Http::withHeaders([
                'x-api-key' => $key,
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ])->timeout(20)->post('https://api.anthropic.com/v1/messages', [
                'model' => config('services.anthropic.model', 'claude-sonnet-4-6'),
                'max_tokens' => 300,
                'system' => 'Je categoriseert e-mailconversaties voor een support-inbox. '
                    .'Antwoord UITSLUITEND met geldige JSON: {"category": "...", "summary": "..."}. '
                    .'Kies category uit deze waarden: '.EmailCategory::promptList().'. '
                    .'Summary: één korte Nederlandse zin die de kern van de conversatie weergeeft; verzin geen feiten.',
                'messages' => [[
                    'role' => 'user',
                    'content' => $this->prompt($thread),
                ]],
            ]);

            if (! $response->successful()) {
                return $this->fallback($thread);
            }

            $parsed = $this->parseJson((string) $response->json('content.0.text', ''));

            if ($parsed === null) {
                return $this->fallback($thread);
            }

            return [
                'category' => EmailCategory::fromValue($parsed['category'] ?? null)->value,
                'summary' => Str::limit(trim($parsed['summary'] ?? ''), 250) ?: $this->summaryFallback($thread),
                'ai' => true,
            ];
        } catch (\Throwable) {
            return $this->fallback($thread);
        }
    }

    private function prompt(EmailThread $thread): string
    {
        $messages = $thread->messages()->get();
        $first = $messages->first();
        $last = $messages->last();

        $subject = $thread->subject ?? $first?->subject ?? '(geen onderwerp)';
        $firstBody = Str::limit((string) ($first?->text_body ?? ''), 2000);
        $lastBody = $last && $last->isNot($first)
            ? "\n\nLaatste bericht:\n\"\"\"\n".Str::limit((string) $last->text_body, 2000)."\n\"\"\""
            : '';

        return "Onderwerp: {$subject}\n\nEerste bericht:\n\"\"\"\n{$firstBody}\n\"\"\"{$lastBody}";
    }

    /**
     * @return array{category?: string, summary?: string}|null
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
     * @return array{category: string, summary: string, ai: bool}
     */
    private function fallback(EmailThread $thread): array
    {
        return [
            'category' => EmailCategory::Other->value,
            'summary' => $this->summaryFallback($thread),
            'ai' => false,
        ];
    }

    private function summaryFallback(EmailThread $thread): string
    {
        $first = $thread->messages()->first();

        return Str::limit(trim((string) ($thread->subject ?? $first?->subject ?? $first?->text_body ?? '')), 200, '');
    }
}
