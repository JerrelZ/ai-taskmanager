<?php

namespace App\Support;

/**
 * Splits an email body into the freshly written reply and the quoted history
 * (the older messages most clients append below a reply). The visible part is
 * shown by default; the quoted part is collapsed behind a toggle in the UI.
 */
class EmailBody
{
    /**
     * Markers that signal the start of quoted history. The earliest match in the
     * body wins. Dutch and English variants of the common clients are covered.
     *
     * @var array<int, string>
     */
    private const QUOTE_MARKERS = [
        // "On Tue, 3 Jun 2026 at 10:00, Jan <jan@x.nl> wrote:"
        '/^\s*On\s.+\bwrote:\s*$/im',
        // "Op di 3 jun. 2026 om 10:00 schreef Jan <jan@x.nl>:"
        '/^\s*Op\s.+\bschreef\b.*:\s*$/im',
        // "Op 3 juni 2026 heeft Jan <jan@x.nl> het volgende geschreven:"
        '/^\s*Op\s.+\bgeschreven:?\s*$/im',
        // Outlook separators (English + Dutch).
        '/^\s*-{2,}\s*Original Message\s*-{2,}/im',
        '/^\s*-{2,}\s*Oorspronkelijk bericht\s*-{2,}/im',
        // Outlook header block.
        '/^\s*(From|Van):\s.+$/im',
        // Outlook divider rule (a long run of underscores on its own line).
        '/^\s*_{5,}\s*$/m',
    ];

    /**
     * @return array{visible: string, quoted: ?string}
     */
    public static function split(?string $text, ?string $html = null): array
    {
        $body = self::resolve($text, $html);

        if ($body === '') {
            return ['visible' => '', 'quoted' => null];
        }

        $cut = self::firstQuoteOffset($body);

        // No quoted history found.
        if ($cut === null) {
            return ['visible' => self::tidy($body), 'quoted' => null];
        }

        $visible = self::tidy(substr($body, 0, $cut));
        $quoted = self::tidy(substr($body, $cut));

        // A reply with no fresh text (forward-only) keeps everything visible so
        // the user never sees an empty message.
        if ($visible === '') {
            return ['visible' => $quoted, 'quoted' => null];
        }

        return ['visible' => $visible, 'quoted' => $quoted === '' ? null : $quoted];
    }

    /**
     * The byte offset of the earliest quote marker, or of the first run of
     * `>`-prefixed quote lines, whichever comes first.
     */
    private static function firstQuoteOffset(string $body): ?int
    {
        $earliest = null;

        foreach (self::QUOTE_MARKERS as $pattern) {
            if (preg_match($pattern, $body, $m, PREG_OFFSET_CAPTURE) === 1) {
                $offset = $m[0][1];
                $earliest = $earliest === null ? $offset : min($earliest, $offset);
            }
        }

        $angle = self::firstAngleQuoteOffset($body);

        if ($angle !== null) {
            $earliest = $earliest === null ? $angle : min($earliest, $angle);
        }

        return $earliest;
    }

    /**
     * The offset of the line that starts the first block of `>`-quoted lines.
     * Requires the line to be at the start of the body or follow a blank line so
     * a stray `>` mid-sentence does not trigger a split.
     */
    private static function firstAngleQuoteOffset(string $body): ?int
    {
        if (preg_match('/(?:\A|\n)[ \t]*(\n)?[ \t]*>/', $body, $m, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        $offset = $m[0][1];

        // Advance past the leading newline so the visible part keeps its trailing text.
        return $body[$offset] === "\n" ? $offset + 1 : $offset;
    }

    private static function resolve(?string $text, ?string $html): string
    {
        if (filled($text)) {
            return self::normaliseNewlines($text);
        }

        if (filled($html)) {
            return self::normaliseNewlines(strip_tags($html));
        }

        return '';
    }

    private static function normaliseNewlines(string $value): string
    {
        return str_replace(["\r\n", "\r"], "\n", $value);
    }

    /**
     * Trim surrounding whitespace and collapse excessive blank lines.
     */
    private static function tidy(string $value): string
    {
        $value = (string) preg_replace("/\n{3,}/", "\n\n", $value);

        return trim($value);
    }
}
