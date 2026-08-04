<?php

namespace App\Services\Email;

use App\Models\EmailAccount;
use App\Models\EmailContactLink;
use App\Models\EmailMessage;
use App\Models\EmailThread;
use App\Support\EmailBody;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Lets Claude investigate a project's customer data on its own to gather the
 * context behind an email. Claude is given the project's read-only support-API
 * tools and a `record_findings` tool; it locates the customer and the relevant
 * records, then returns a structured set of findings (entities with concrete
 * ids) that can be attached to a ticket and handed to Claude Code.
 *
 * The model never touches a database: it can only reach what the support API
 * deliberately publishes, so no raw row — and no other customer's data — can
 * leak into a ticket.
 */
class EmailContextInvestigator
{
    /** Hard cap on agent loop iterations. */
    private const MAX_STEPS = 8;

    /** The support-API tools the model may call. */
    private const API_TOOLS = ['lookup_user_by_email', 'customer_summary', 'customer_revenue', 'customer_invoices'];

    public function __construct(private readonly ExternalProjectApi $api) {}

    /**
     * @return array{summary: string, entities: array<int, array{resource: string, id: string, label: string, relevance: string}>, markdown: string}
     */
    public function investigate(EmailThread $thread): array
    {
        $key = config('services.anthropic.key');

        if (blank($key)) {
            throw new RuntimeException('Er is geen AI-sleutel geconfigureerd.');
        }

        $thread->loadMissing(['messages', 'account']);
        $account = $thread->account;

        if ($account === null || ! $this->api->configured($account)) {
            throw new RuntimeException('Dit project heeft geen support-API.');
        }

        $messages = [[
            'role' => 'user',
            'content' => $this->userContent($thread, $account),
        ]];

        for ($step = 0; $step < self::MAX_STEPS; $step++) {
            $response = Http::withHeaders([
                'x-api-key' => $key,
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ])->timeout(60)->post('https://api.anthropic.com/v1/messages', [
                'model' => config('services.anthropic.model', 'claude-sonnet-4-6'),
                'max_tokens' => 1500,
                'system' => $this->system(),
                'tools' => $this->tools(),
                'messages' => $messages,
            ]);

            if (! $response->successful()) {
                throw new RuntimeException('AI gaf een fout terug ('.$response->status().').');
            }

            /** @var array<int, array<string, mixed>> $content */
            $content = $response->json('content', []);
            $messages[] = ['role' => 'assistant', 'content' => $content];

            if ($response->json('stop_reason') !== 'tool_use') {
                // Model stopped without recording findings: use any text as the summary.
                return $this->emptyResult($this->firstText($content));
            }

            $toolResults = [];

            foreach ($content as $block) {
                if (($block['type'] ?? null) !== 'tool_use') {
                    continue;
                }

                if ($block['name'] === 'record_findings') {
                    return $this->buildResult($block['input'] ?? []);
                }

                if (in_array($block['name'], self::API_TOOLS, true)) {
                    $toolResults[] = [
                        'type' => 'tool_result',
                        'tool_use_id' => $block['id'],
                        'content' => $this->runApiTool($account, $block['name'], (array) ($block['input'] ?? [])),
                    ];
                }
            }

            if ($toolResults === []) {
                return $this->emptyResult($this->firstText($content));
            }

            $messages[] = ['role' => 'user', 'content' => $toolResults];
        }

        return $this->emptyResult('De analyse bereikte de maximale hoeveelheid stappen zonder afronding.');
    }

    /**
     * Call one of the support-API tools and return a JSON string result.
     *
     * @param  array<string, mixed>  $input
     */
    private function runApiTool(EmailAccount $account, string $name, array $input): string
    {
        try {
            $data = match ($name) {
                'lookup_user_by_email' => $this->api->lookupUserByEmail($account, (string) ($input['email'] ?? '')),
                'customer_summary' => $this->api->userSummary($account, (string) ($input['user_id'] ?? '')),
                'customer_revenue' => $this->api->revenue(
                    $account,
                    (string) ($input['user_id'] ?? ''),
                    (string) ($input['from'] ?? ''),
                    (string) ($input['to'] ?? ''),
                    (string) ($input['granularity'] ?? 'day'),
                ),
                'customer_invoices' => $this->api->invoices($account, (string) ($input['user_id'] ?? ''), $input['status'] ?? null),
                default => ['error' => 'unknown tool'],
            };

            return (string) json_encode($data);
        } catch (\Throwable $e) {
            return 'Fout: '.Str::limit($e->getMessage(), 300);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function tools(): array
    {
        return [
            [
                'name' => 'lookup_user_by_email',
                'description' => 'START HERE. Resolve an email address to a customer via the support API. Returns id, account type and status.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => ['email' => ['type' => 'string']],
                    'required' => ['email'],
                ],
            ],
            [
                'name' => 'customer_summary',
                'description' => 'Customer-360 summary (profile, status, counts, this-month revenue) from the support API. Pass the user id.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => ['user_id' => ['type' => 'string']],
                    'required' => ['user_id'],
                ],
            ],
            [
                'name' => 'customer_revenue',
                'description' => 'Revenue/commission over a date range from the support API, bucketed by hour/day/month.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'user_id' => ['type' => 'string'],
                        'from' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                        'to' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                        'granularity' => ['type' => 'string', 'enum' => ['hour', 'day', 'month']],
                    ],
                    'required' => ['user_id', 'from', 'to'],
                ],
            ],
            [
                'name' => 'customer_invoices',
                'description' => 'Customer invoices and their paid/unpaid status from the support API.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'user_id' => ['type' => 'string'],
                        'status' => ['type' => 'string', 'enum' => ['pending', 'paid']],
                    ],
                    'required' => ['user_id'],
                ],
            ],
            [
                'name' => 'record_findings',
                'description' => 'Record the final structured context once you have gathered enough. Call this exactly once when done.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'summary' => ['type' => 'string', 'description' => 'A concise Dutch summary of who the sender is and the relevant context.'],
                        'entities' => [
                            'type' => 'array',
                            'description' => 'The concrete support-API records relevant to this email.',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'resource' => ['type' => 'string', 'description' => 'The API resource, e.g. "users" or "invoices".'],
                                    'id' => ['type' => 'string', 'description' => 'Identifier of the record as a string.'],
                                    'label' => ['type' => 'string', 'description' => 'Human-readable name for the record.'],
                                    'relevance' => ['type' => 'string', 'description' => 'Why this record matters for the email.'],
                                ],
                                'required' => ['resource', 'id', 'label', 'relevance'],
                            ],
                        ],
                    ],
                    'required' => ['summary', 'entities'],
                ],
            ],
        ];
    }

    private function system(): string
    {
        return 'Je bent een support-analist. Je onderzoekt de klantdata van een project via de read-only '
            .'support-API om de juiste context bij een binnengekomen e-mail te vinden. Werk methodisch: zoek '
            .'eerst de afzender op met lookup_user_by_email, haal daarna met customer_summary, customer_revenue '
            .'en customer_invoices op wat relevant is voor de vraag. Je hebt geen directe databasetoegang; alles '
            .'wat je niet via deze tools kunt ophalen, bestaat voor jou niet. Verzin geen gegevens. Als je genoeg '
            .'context hebt, roep je record_findings aan met een korte samenvatting en de concrete records '
            .'(resource, id, label, relevantie). Houd het beknopt en relevant.';
    }

    /**
     * Build the first user turn: the text prompt plus any readable attachments
     * (PDFs, images, text files) from the latest inbound message, so Claude can
     * read e.g. an invoice while investigating.
     *
     * @return string|array<int, array<string, mixed>>
     */
    private function userContent(EmailThread $thread, EmailAccount $account): string|array
    {
        $text = $this->prompt($thread, $account);
        $blocks = $this->attachmentBlocks($thread);

        if ($blocks === []) {
            return $text;
        }

        return [['type' => 'text', 'text' => $text], ...$blocks];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function attachmentBlocks(EmailThread $thread): array
    {
        $latest = $thread->messages->where('direction', EmailMessage::DIRECTION_INBOUND)->last();

        if ($latest === null) {
            return [];
        }

        $blocks = [];
        $budget = 12 * 1024 * 1024; // ~12 MB total cap

        foreach ($latest->loadMissing('attachments')->attachments as $attachment) {
            if (count($blocks) >= 4 || $attachment->size > 6 * 1024 * 1024 || $attachment->size > $budget) {
                continue;
            }

            $mime = (string) $attachment->mime_type;

            try {
                $raw = Storage::disk($attachment->disk)->get($attachment->path);
            } catch (\Throwable) {
                continue;
            }

            if ($raw === null) {
                continue;
            }

            if ($mime === 'application/pdf') {
                $blocks[] = ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => base64_encode($raw)]];
            } elseif (in_array($mime, ['image/png', 'image/jpeg', 'image/gif', 'image/webp'], true)) {
                $blocks[] = ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $mime, 'data' => base64_encode($raw)]];
            } elseif (str_starts_with($mime, 'text/')) {
                $blocks[] = ['type' => 'text', 'text' => "Bijlage \"{$attachment->filename}\":\n".Str::limit($raw, 4000)];
            } else {
                continue; // unsupported binary (e.g. xlsx) — leave it out, the model is told it exists
            }

            $budget -= $attachment->size;
        }

        return $blocks;
    }

    private function prompt(EmailThread $thread, EmailAccount $account): string
    {
        $latest = $thread->messages->where('direction', EmailMessage::DIRECTION_INBOUND)->last();
        $sender = $latest?->from_email ?? 'onbekend';

        $lines = [
            'E-mail om context bij te zoeken:',
            "- Afzender: {$sender}",
            '- Onderwerp: '.($thread->subject ?: '(geen onderwerp)'),
        ];

        $link = EmailContactLink::where('email_account_id', $account->id)->where('email', $sender)->first();

        if ($link !== null) {
            $lines[] = "- Bevestigde koppeling: `{$link->external_resource}` #{$link->external_id}. Start hier.";
        }

        if ($latest !== null) {
            $body = EmailBody::split($latest->text_body, $latest->html_body)['visible'];
            $lines[] = "\nBericht:\n\"\"\"\n".Str::limit($body, 1200)."\n\"\"\"";
        }

        $lines[] = "\nOnderzoek de klantdata via de support-API en leg de relevante context vast met record_findings.";

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{summary: string, entities: array<int, array{resource: string, id: string, label: string, relevance: string}>, markdown: string}
     */
    private function buildResult(array $input): array
    {
        $summary = trim((string) ($input['summary'] ?? ''));
        $entities = [];

        foreach ($input['entities'] ?? [] as $entity) {
            if (! is_array($entity)) {
                continue;
            }

            $entities[] = [
                'resource' => (string) ($entity['resource'] ?? ''),
                'id' => (string) ($entity['id'] ?? ''),
                'label' => (string) ($entity['label'] ?? ''),
                'relevance' => (string) ($entity['relevance'] ?? ''),
            ];
        }

        return [
            'summary' => $summary,
            'entities' => $entities,
            'markdown' => $this->markdown($summary, $entities),
        ];
    }

    /**
     * @param  array<int, array{resource: string, id: string, label: string, relevance: string}>  $entities
     */
    private function markdown(string $summary, array $entities): string
    {
        $lines = ['## Klantcontext (AI-onderzocht)', '', $summary];

        if ($entities !== []) {
            $lines[] = '';
            $lines[] = '### Context voor Claude Code';
            foreach ($entities as $entity) {
                $lines[] = "- `{$entity['resource']}` #{$entity['id']} — {$entity['label']} ({$entity['relevance']})";
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @return array{summary: string, entities: array<int, array{resource: string, id: string, label: string, relevance: string}>, markdown: string}
     */
    private function emptyResult(string $summary): array
    {
        $summary = $summary !== '' ? $summary : 'Geen aanvullende context gevonden.';

        return ['summary' => $summary, 'entities' => [], 'markdown' => "## Klantcontext (AI-onderzocht)\n\n".$summary];
    }

    /**
     * @param  array<int, array<string, mixed>>  $content
     */
    private function firstText(array $content): string
    {
        foreach ($content as $block) {
            if (($block['type'] ?? null) === 'text') {
                return trim((string) ($block['text'] ?? ''));
            }
        }

        return '';
    }
}
