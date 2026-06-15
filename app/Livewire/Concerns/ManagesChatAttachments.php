<?php

namespace App\Livewire\Concerns;

use App\Models\Message;
use App\Services\AttachmentService;
use Illuminate\Support\Facades\Auth;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Shared pending-attachment handling for chat composers. The host component
 * must also use Livewire's WithFileUploads trait.
 *
 * @property array<int, TemporaryUploadedFile> $newChatAttachments
 */
trait ManagesChatAttachments
{
    /** @var array<int, TemporaryUploadedFile> */
    public array $newChatAttachments = [];

    /**
     * Drop a single file from the pending attachment tray before sending.
     */
    public function removeNewAttachment(int $index): void
    {
        unset($this->newChatAttachments[$index]);

        $this->newChatAttachments = array_values($this->newChatAttachments);
    }

    /**
     * Validation rules for the pending chat attachments.
     *
     * @return array<string, array<int, string>>
     */
    protected function chatAttachmentRules(): array
    {
        return [
            'newChatAttachments' => ['array', 'max:10'],
            'newChatAttachments.*' => ['file', 'max:25600'], // 25 MB each
        ];
    }

    protected function storeChatAttachments(AttachmentService $attachments, Message $message): void
    {
        foreach ($this->newChatAttachments as $file) {
            $attachments->storeUpload($file, $message, Auth::user());
        }
    }
}
