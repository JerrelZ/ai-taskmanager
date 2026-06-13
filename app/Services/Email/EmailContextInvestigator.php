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
 * Lets Claude investigate a project's external database on its own to gather the
 * context behind an email. Claude is given a read-only `query_database` tool and
 * a `record_findings` tool; it explores the schema, locates the customer and the
 * relevant records, then returns a structured set of findings (entities with
 * concrete ids) that can be attached to a ticket and handed to Claude Code.
 */
class EmailContextInvestigator
{
    /** Hard cap on agent loop iterations. */
    private const MAX_STEPS = 8;

    /** Rows returned to the model per query (kept small to bound tokens). */
    private const MAX_ROWS = 40;

    public function __construct(
        private readonly ExternalProjectDb $externalDb,
        private readonly ExternalProjectApi $api,
    ) {}

    /**
     * @return array{summary: string, entities: array<int, array{table: string, id: string, label: string, relevance: string}>, markdown: string}
     */
    public function investigate(EmailThread $thread): array
    {
        $key = config('services.anthropic.key');

        if (blank($key)) {
            throw new RuntimeException('Er is geen AI-sleutel geconfigureerd.');
        }

        $thread->loadMissing(['messages', 'account']);
        $account = $thread->account;

        if ($account === null || blank($account->external_db_dsn)) {
            throw new RuntimeException('Dit project heeft geen externe database.');
        }

        $hasApi = $this->api->configured($account);

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
                'system' => $this->system($hasApi),
                'tools' => $this->tools($hasApi),
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

                if ($block['name'] === 'query_database') {
                    $toolResults[] = [
                        'type' => 'tool_result',
                        'tool_use_id' => $block['id'],
                        'content' => $this->runQuery($account, (string) ($block['input']['sql'] ?? '')),
                    ];

                    continue;
                }

                // Support-API tools (preferred when configured).
                if (in_array($block['name'], ['lookup_user_by_email', 'customer_summary', 'customer_revenue', 'customer_invoices'], true)) {
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
     * Execute one read-only query for the agent and return a JSON string result.
     */
    private function runQuery(EmailAccount $account, string $sql): string
    {
        if (trim($sql) === '') {
            return 'Fout: lege query.';
        }

        try {
            $rows = $this->externalDb->select($account, $sql);
            $clipped = array_map(fn ($row): array => (array) $row, array_slice($rows, 0, self::MAX_ROWS));

            return (string) json_encode([
                'row_count' => count($rows),
                'truncated' => count($rows) > self::MAX_ROWS,
                'rows' => $clipped,
            ]);
        } catch (\Throwable $e) {
            // Hand the error back so the model can correct its SQL.
            return 'Fout: '.Str::limit($e->getMessage(), 300);
        }
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
    private function tools(bool $hasApi = false): array
    {
        $tools = [];

        if ($hasApi) {
            $tools[] = [
                'name' => 'lookup_user_by_email',
                'description' => 'PREFERRED. Resolve an email address to a customer via the support API. Returns id, account type and status.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => ['email' => ['type' => 'string']],
                    'required' => ['email'],
                ],
            ];
            $tools[] = [
                'name' => 'customer_summary',
                'description' => 'PREFERRED. Customer-360 summary (profile, status, counts, this-month revenue) from the support API. Pass the user id.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => ['user_id' => ['type' => 'string']],
                    'required' => ['user_id'],
                ],
            ];
            $tools[] = [
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
            ];
            $tools[] = [
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
            ];
        }

        $tools[] = [
            'name' => 'query_database',
            'description' => 'Run a single READ-ONLY SQL statement (SELECT/SHOW/DESCRIBE/EXPLAIN) against the customer database. '
                .'Writes are rejected. Start by discovering the schema with "SHOW TABLES" and "DESCRIBE <table>".',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'sql' => ['type' => 'string', 'description' => 'A single read-only SQL statement.'],
                ],
                'required' => ['sql'],
            ],
        ];

        $tools[] = [
            'name' => 'record_findings',
            'description' => 'Record the final structured context once you have gathered enough. Call this exactly once when done.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'summary' => ['type' => 'string', 'description' => 'A concise Dutch summary of who the sender is and the relevant context.'],
                    'entities' => [
                        'type' => 'array',
                        'description' => 'The concrete database records relevant to this email.',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'table' => ['type' => 'string'],
                                'id' => ['type' => 'string', 'description' => 'Primary key value as a string.'],
                                'label' => ['type' => 'string', 'description' => 'Human-readable name for the record.'],
                                'relevance' => ['type' => 'string', 'description' => 'Why this record matters for the email.'],
                            ],
                            'required' => ['table', 'id', 'label', 'relevance'],
                        ],
                    ],
                ],
                'required' => ['summary', 'entities'],
            ],
        ];

        return $tools;
    }

    private function system(bool $hasApi = false): string
    {
        $apiHint = $hasApi
            ? ' Dit project heeft een support-API: gebruik bij voorkeur lookup_user_by_email, customer_summary, '
                .'customer_revenue en customer_invoices (betrouwbare, berekende cijfers). Val alleen terug op '
                .'query_database voor zaken die de API niet dekt.'
            : '';

        return 'Je bent een support-analist. Je onderzoekt de externe klantdata van een project om de juiste '
            .'context bij een binnengekomen e-mail te vinden. Werk methodisch: ontdek eerst het schema '
            .'(SHOW TABLES, DESCRIBE), zoek dan de afzender/klant en de meest relevante gerelateerde records '
            .'(bijv. account, bestellingen, facturen, status). Voer alleen read-only queries uit. Verzin geen '
            .'gegevens. Als je genoeg context hebt, roep je record_findings aan met een korte samenvatting en de '
            .'concrete records (tabel, primaire id, label, relevantie). Houd het beknopt en relevant.'.$apiHint;
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
            $lines[] = "- Bevestigde koppeling: tabel `{$link->external_table}`, {$link->external_id_column}={$link->external_id}. Start hier.";
        }

        if ($latest !== null) {
            $body = EmailBody::split($latest->text_body, $latest->html_body)['visible'];
            $lines[] = "\nBericht:\n\"\"\"\n".Str::limit($body, 1200)."\n\"\"\"";
        }

        $lines[] = "\nOnderzoek de database en leg de relevante context vast met record_findings.";

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{summary: string, entities: array<int, array{table: string, id: string, label: string, relevance: string}>, markdown: string}
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
                'table' => (string) ($entity['table'] ?? ''),
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
     * @param  array<int, array{table: string, id: string, label: string, relevance: string}>  $entities
     */
    private function markdown(string $summary, array $entities): string
    {
        $lines = ['## Klantcontext (AI-onderzocht)', '', $summary];

        if ($entities !== []) {
            $lines[] = '';
            $lines[] = '### Context voor Claude Code';
            foreach ($entities as $entity) {
                $lines[] = "- `{$entity['table']}` #{$entity['id']} — {$entity['label']} ({$entity['relevance']})";
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @return array{summary: string, entities: array<int, array{table: string, id: string, label: string, relevance: string}>, markdown: string}
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
