<?php

namespace App\Console\Commands\Email;

use App\Models\EmailMessage;
use App\Services\AttachmentService;
use App\Services\Email\MailParser;
use App\Services\Email\RawEmailStore;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('email:backfill-attachments {--account= : Limit to a single email account id}')]
#[Description('Extract and store attachments for already-ingested email messages')]
class BackfillAttachments extends Command
{
    public function handle(RawEmailStore $store, MailParser $parser, AttachmentService $attachments): int
    {
        $stored = 0;
        $processed = 0;

        EmailMessage::query()
            ->whereNotNull('raw_path')
            ->whereDoesntHave('attachments')
            ->when($this->option('account'), fn ($q, $id) => $q->where('email_account_id', $id))
            ->chunkById(100, function ($messages) use ($store, $parser, $attachments, &$stored, &$processed): void {
                foreach ($messages as $message) {
                    $processed++;

                    try {
                        foreach ($parser->extractAttachments($store->get($message->raw_path)) as $attachment) {
                            $attachments->storeRaw($attachment['content'], $attachment['name'], $attachment['mime'], $message);
                            $stored++;
                        }
                    } catch (\Throwable $e) {
                        $this->warn("Bericht #{$message->id}: {$e->getMessage()}");
                    }
                }
            });

        $this->info("Verwerkt: {$processed} berichten, {$stored} bijlagen opgeslagen.");

        return self::SUCCESS;
    }
}
