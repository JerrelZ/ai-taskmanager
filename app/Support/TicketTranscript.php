<?php

namespace App\Support;

use App\Models\Attachment;
use App\Models\Comment;
use App\Models\Task;
use Illuminate\Support\Collection;

/**
 * Renders a ticket — its title, description and every reply — as a single
 * Markdown document suitable for pasting into an AI assistant as a prompt.
 *
 * Inline images embedded in the rich-text bodies are kept as Markdown image
 * links so the visual context survives the copy, and any attached files not
 * already referenced inline are listed beneath their section.
 */
class TicketTranscript
{
    /**
     * Build the full Markdown transcript for a ticket.
     */
    public static function for(Task $task): string
    {
        $task->loadMissing(['comments.user', 'comments.attachments', 'attachments']);

        $sections = [];

        $sections[] = '# '.$task->identifier().' — '.$task->title;

        $description = self::htmlToMarkdown($task->description);
        $taskFiles = self::attachmentLines($task->attachments, $description);

        $body = trim($description."\n\n".$taskFiles);
        $sections[] = "## Omschrijving\n\n".($body !== '' ? $body : '_Geen omschrijving._');

        $replies = self::replies($task->comments);

        if ($replies !== '') {
            $sections[] = "## Reacties\n\n".$replies;
        }

        return self::tidy(implode("\n\n", $sections));
    }

    /**
     * Render every reply as a Markdown sub-section with author, date and body.
     *
     * @param  Collection<int, Comment>  $comments
     */
    private static function replies(Collection $comments): string
    {
        return $comments
            ->map(function (Comment $comment): string {
                $author = $comment->user?->name ?? __('Onbekend');
                $date = $comment->created_at?->format('d-m-Y H:i');
                $heading = '### '.trim($author.($date ? ' — '.$date : ''));

                $markdown = self::htmlToMarkdown($comment->body);
                $files = self::attachmentLines($comment->attachments, $markdown);

                $body = trim($markdown."\n\n".$files);

                return $heading."\n\n".($body !== '' ? $body : '_Geen tekst._');
            })
            ->implode("\n\n");
    }

    /**
     * List attachments that are not already referenced inline in the given body,
     * as Markdown image links for images and plain links for other files.
     *
     * @param  Collection<int, Attachment>  $attachments
     */
    private static function attachmentLines(Collection $attachments, string $renderedBody): string
    {
        return $attachments
            ->reject(fn (Attachment $attachment): bool => str_contains($renderedBody, route('attachments.show', $attachment)))
            ->map(function (Attachment $attachment): string {
                $url = route('attachments.show', $attachment);

                return $attachment->isImage()
                    ? '!['.$attachment->filename.']('.$url.')'
                    : '['.$attachment->filename.']('.$url.')';
            })
            ->implode("\n");
    }

    /**
     * Convert the editor's sanitized HTML into Markdown-ish plain text, keeping
     * images as Markdown image links and hyperlinks as Markdown links. Inline
     * base64 images are replaced with a placeholder since they carry no shareable
     * URL and would bloat the clipboard.
     */
    private static function htmlToMarkdown(?string $html): string
    {
        if ($html === null || trim($html) === '') {
            return '';
        }

        $text = $html;

        // Images: data URIs become a placeholder, everything else keeps its URL.
        $text = preg_replace_callback('#<img[^>]*\bsrc=("|\')(.*?)\1[^>]*>#is', function (array $m): string {
            $src = html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5);

            return str_starts_with($src, 'data:') ? "\n![inline afbeelding]\n" : "\n![]({$src})\n";
        }, $text) ?? $text;

        // Hyperlinks: keep the visible text and its href as a Markdown link.
        $text = preg_replace_callback('#<a[^>]*\bhref=("|\')(.*?)\1[^>]*>(.*?)</a>#is', function (array $m): string {
            $href = html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5);
            $label = trim(strip_tags($m[3]));

            if ($label === '' || $label === $href) {
                return $href;
            }

            return "[{$label}]({$href})";
        }, $text) ?? $text;

        $text = preg_replace('#<li[^>]*>#i', '- ', $text) ?? $text;
        $text = preg_replace('#<br\s*/?>#i', "\n", $text) ?? $text;
        $text = preg_replace('#</(p|div|li|h[1-6]|blockquote|tr)>#i', "\n", $text) ?? $text;

        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5);

        return trim($text);
    }

    /**
     * Collapse runs of blank lines and trim trailing whitespace per line.
     */
    private static function tidy(string $value): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $value = (string) preg_replace('/[ \t]+\n/', "\n", $value);
        $value = (string) preg_replace("/\n{3,}/", "\n\n", $value);

        return trim($value);
    }
}
