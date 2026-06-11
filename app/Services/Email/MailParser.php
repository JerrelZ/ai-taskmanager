<?php

namespace App\Services\Email;

use Illuminate\Support\Carbon;
use Webklex\PHPIMAP\Address;
use Webklex\PHPIMAP\Message;

/**
 * Parses a raw RFC822 message into a plain array, offline (no IMAP connection).
 * Wraps webklex/php-imap's offline parser so the rest of the app stays decoupled.
 */
class MailParser
{
    /**
     * @return array{
     *     from_name: ?string,
     *     from_email: ?string,
     *     to: array<int, string>,
     *     cc: array<int, string>,
     *     subject: ?string,
     *     text_body: ?string,
     *     html_body: ?string,
     *     message_id: ?string,
     *     in_reply_to: ?string,
     *     references: ?string,
     *     reference_ids: array<int, string>,
     *     sent_at: ?Carbon,
     * }
     */
    public function parse(string $raw): array
    {
        $message = Message::fromString($raw);

        $from = $message->getFrom()->first();

        return [
            'from_name' => $from instanceof Address ? ($from->personal ?: null) : null,
            'from_email' => $from instanceof Address ? ($from->mail ?: null) : null,
            'to' => $this->addresses($message->getTo()->toArray()),
            'cc' => $this->addresses($message->getCc()->toArray()),
            'subject' => $this->stringOrNull($message->getSubject()),
            'text_body' => $this->stringOrNull($message->getTextBody()),
            'html_body' => $this->stringOrNull($message->getHTMLBody()),
            'message_id' => $this->normaliseId($this->stringOrNull($message->getMessageId())),
            'in_reply_to' => $this->normaliseId($this->stringOrNull($message->getInReplyTo())),
            'references' => $this->stringOrNull($message->getReferences()),
            'reference_ids' => $this->extractIds((string) $message->getReferences()),
            'sent_at' => $this->date($message),
        ];
    }

    /**
     * Normalise a message id to a bare, lowercase form for stable comparisons.
     */
    public function normaliseId(?string $id): ?string
    {
        if ($id === null) {
            return null;
        }

        $id = strtolower(trim(trim($id), '<>'));

        return $id === '' ? null : $id;
    }

    /**
     * @return array<int, string>
     */
    public function extractIds(string $value): array
    {
        if (preg_match_all('/<[^>]+>|\S+@\S+/', $value, $matches) === false) {
            return [];
        }

        $ids = array_map(fn (string $raw): ?string => $this->normaliseId($raw), $matches[0]);

        return array_values(array_filter($ids));
    }

    /**
     * @param  array<int, mixed>  $addresses
     * @return array<int, string>
     */
    private function addresses(array $addresses): array
    {
        $mails = [];

        foreach ($addresses as $address) {
            if ($address instanceof Address && $address->mail !== '') {
                $mails[] = $address->mail;
            }
        }

        return $mails;
    }

    private function stringOrNull(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function date(Message $message): ?Carbon
    {
        try {
            $date = (string) $message->getDate();

            return $date === '' ? null : Carbon::parse($date);
        } catch (\Throwable) {
            return null;
        }
    }
}
