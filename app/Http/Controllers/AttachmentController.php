<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use App\Models\Conversation;
use App\Models\EmailMessage;
use App\Models\Message;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttachmentController extends Controller
{
    public function download(Request $request, Attachment $attachment): StreamedResponse
    {
        abort_unless($this->canView($request->user(), $attachment), 403);
        abort_unless(Storage::disk($attachment->disk)->exists($attachment->path), 404);

        return Storage::disk($attachment->disk)->download($attachment->path, $attachment->filename);
    }

    /**
     * Serve the file inline so images and videos can be embedded and previewed.
     *
     * Local files are served as a BinaryFileResponse so the browser gets Range
     * support — required to seek/scrub video. Remote disks fall back to a
     * streamed response.
     */
    public function show(Request $request, Attachment $attachment): Response
    {
        abort_unless($this->canView($request->user(), $attachment), 403);

        return $this->serveInline($attachment);
    }

    /**
     * Serve a file inline using only its unguessable token — no authentication.
     *
     * The token is the capability: anyone holding the link may view the file.
     * This is what powers shareable links embedded in copied AI prompts.
     */
    public function public(string $token): Response
    {
        $attachment = Attachment::where('public_token', $token)->firstOrFail();

        return $this->serveInline($attachment);
    }

    private function serveInline(Attachment $attachment): Response
    {
        $disk = Storage::disk($attachment->disk);
        abort_unless($disk->exists($attachment->path), 404);

        $headers = [
            'Content-Type' => $attachment->mime_type ?: 'application/octet-stream',
            'Content-Disposition' => 'inline; filename="'.str_replace('"', '', $attachment->filename).'"',
        ];

        if (method_exists($disk, 'path')) {
            return response()->file($disk->path($attachment->path), $headers);
        }

        return $disk->response($attachment->path, $attachment->filename, $headers);
    }

    private function canView(User $user, Attachment $attachment): bool
    {
        $attachable = $attachment->attachable;

        return match (true) {
            $attachable instanceof EmailMessage => $this->canSeeProject($user, $attachable->thread?->project_id),
            $attachable instanceof Task => $this->canSeeProject($user, $attachable->project_id),
            $attachable instanceof Message => ($conversation = Conversation::find($attachable->conversation_id)) !== null
                && $conversation->canAccess($user),
            default => false,
        };
    }

    private function canSeeProject(User $user, ?int $projectId): bool
    {
        if ($user->isTeam()) {
            return true;
        }

        if ($projectId === null) {
            return false;
        }

        return Project::where('id', $projectId)
            ->where('client_id', $user->client_id)
            ->exists();
    }
}
