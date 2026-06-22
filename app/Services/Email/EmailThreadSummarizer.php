<?php

namespace App\Services\Email;

use App\Models\EmailMessage;
use App\Models\EmailThread;
use App\Support\EmailBody;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Summarises an email thread with a single Claude call: it reads the whole
 * conversation and returns a concise Dutch summary plus the concrete action
 * points, formatted as markdown ready to drop into a ticket description.
 *
 * Unlike {@see EmailContextInvestigator} this makes exactly one request (no
 * tools, no agent loop), so it stays well within low API rate-limit tiers.
 */
class EmailThreadSummarizer
{
    /** Most recent messages to include, to bound the token usage. */
    private const MAX_MESSAGES = 15;

    public function summarise(EmailThread $thread): string
    {
        $key = config('services.anthropic.key');

        if (blank($key)) {
            throw new RuntimeException('Er is geen AI-sleutel geconfigureerd.');
        }

        $thread->loadMissing('messages');

        $response = Http::withHeaders([
            'x-api-key' => $key,
            'anthropic-version' => '2023-06-01',
            'content-type' => 'application/json',
        ])->timeout(45)->post('https://api.anthropic.com/v1/messages', [
            'model' => config('services.anthropic.model', 'claude-sonnet-4-6'),
            'max_tokens' => 1200,
            'system' => $this->system(),
            'messages' => [[
                'role' => 'user',
                'content' => $this->prompt($thread),
            ]],
        ]);

        if (! $response->successful()) {
            $message = (string) $response->json('error.message', '');

            throw new RuntimeException(trim('AI gaf een fout terug ('.$response->status().').'.($message !== '' ? ' '.$message : '')));
        }

        $text = trim((string) $response->json('content.0.text', ''));

        if ($text === '') {
            throw new RuntimeException('AI gaf een leeg antwoord terug.');
        }

        return $text;
    }

    private function system(): string
    {
        return 'Je bent een support-analist. Vat de meegegeven e-mailconversatie bondig samen in het Nederlands en '
            .'haal de concrete actiepunten eruit. Verzin geen feiten; gebruik alleen wat in de conversatie staat. '
            .'Antwoord uitsluitend in markdown met exact deze structuur: een kop "## Samenvatting" met een korte '
            .'alinea, gevolgd door een kop "### Acties" met een opsomming van concrete actiepunten (één per regel, '
            .'beginnend met "- "). Zijn er geen acties, schrijf dan onder "### Acties" de regel "- Geen acties." '
            .'Geen inleiding, geen afsluiting, geen andere koppen.';
    }

    private function prompt(EmailThread $thread): string
    {
        $lines = ['Onderwerp: '.($thread->subject ?: '(geen onderwerp)')];
        $lines[] = "\nConversatie (oudste eerst):";

        foreach ($thread->messages->take(-self::MAX_MESSAGES) as $message) {
            $who = $message->direction === EmailMessage::DIRECTION_OUTBOUND
                ? 'Wij'
                : ($message->from_name ?: $message->from_email ?: 'Klant');

            $body = EmailBody::split($message->text_body, $message->html_body)['visible'];

            $lines[] = "\n[{$who}]\n".Str::limit($body, 1500);
        }

        $lines[] = "\nVat nu samen en benoem de acties.";

        return implode("\n", $lines);
    }
}
