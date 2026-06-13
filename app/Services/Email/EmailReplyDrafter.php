<?php

namespace App\Services\Email;

use App\Models\EmailMessage;
use App\Models\EmailThread;
use App\Models\User;
use App\Support\EmailBody;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Drafts a reply to an email thread with Claude, grounded in the conversation
 * and the assembled customer context (project data + linked external DB row).
 */
class EmailReplyDrafter
{
    public function __construct(private readonly EmailContextBuilder $contextBuilder) {}

    public function draft(EmailThread $thread, ?User $agent = null): string
    {
        $key = config('services.anthropic.key');

        if (blank($key)) {
            throw new RuntimeException('Er is geen AI-sleutel geconfigureerd.');
        }

        $thread->loadMissing(['messages', 'project', 'account']);

        $response = Http::withHeaders([
            'x-api-key' => $key,
            'anthropic-version' => '2023-06-01',
            'content-type' => 'application/json',
        ])->timeout(45)->post('https://api.anthropic.com/v1/messages', [
            'model' => config('services.anthropic.model', 'claude-sonnet-4-6'),
            'max_tokens' => 1000,
            'system' => $this->system($agent),
            'messages' => [[
                'role' => 'user',
                'content' => $this->prompt($thread),
            ]],
        ]);

        if (! $response->successful()) {
            throw new RuntimeException('AI gaf een fout terug ('.$response->status().').');
        }

        $text = trim((string) $response->json('content.0.text', ''));

        if ($text === '') {
            throw new RuntimeException('AI gaf een leeg antwoord terug.');
        }

        return $text;
    }

    private function system(?User $agent): string
    {
        $signature = $agent !== null ? " Onderteken met de naam {$agent->name}." : '';

        return 'Je bent een ervaren support-medewerker. Schrijf een professioneel, vriendelijk en bondig '
            .'concept-antwoord in het Nederlands op het laatste binnengekomen bericht in de conversatie. '
            .'Gebruik de meegegeven klant- en projectcontext waar relevant; verzin geen feiten en beloof niets '
            .'wat niet uit de context blijkt. Schrijf alleen de tekst van het antwoord — geen onderwerpregel en '
            .'geen placeholders zoals [naam].'.$signature;
    }

    private function prompt(EmailThread $thread): string
    {
        $context = '';

        try {
            $context = trim($this->contextBuilder->build($thread));
        } catch (\Throwable) {
            // Context is best-effort; draft from the conversation alone if it fails.
        }

        $lines = [];
        $lines[] = 'Onderwerp: '.($thread->subject ?: '(geen onderwerp)');

        if ($context !== '') {
            $lines[] = "\nContext:\n\"\"\"\n".Str::limit($context, 4000)."\n\"\"\"";
        }

        $lines[] = "\nConversatie (oudste eerst):";

        foreach ($thread->messages->take(-8) as $message) {
            $who = $message->direction === EmailMessage::DIRECTION_OUTBOUND
                ? 'Wij'
                : ($message->from_name ?: $message->from_email ?: 'Klant');

            $body = EmailBody::split($message->text_body, $message->html_body)['visible'];

            $lines[] = "\n[{$who}]\n".Str::limit($body, 1500);
        }

        $lines[] = "\nSchrijf nu het concept-antwoord op het laatste bericht van de klant.";

        return implode("\n", $lines);
    }
}
