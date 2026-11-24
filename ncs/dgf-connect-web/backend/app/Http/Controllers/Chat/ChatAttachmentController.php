<?php

namespace App\Http\Controllers\Chat;

use App\Actions\Chat\Access\ResolveConversationViewAccessAction;
use App\Http\Controllers\Controller;
use App\Models\ChatAttachment;
use App\Models\ChatConversation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves chat attachments behind the same view-access check used for the conversation itself, so a
 * removed or non-participant user cannot download files even with a direct attachment id. Shared by
 * employee and admin clients since access is membership-based.
 */
class ChatAttachmentController extends Controller
{
    public function __construct(private readonly ResolveConversationViewAccessAction $resolveViewAccess) {}

    public function download(Request $request, ChatAttachment $attachment): StreamedResponse
    {
        $this->abortUnlessCanViewAttachment($request, $attachment);

        return Storage::disk($attachment->disk)->download($attachment->path, $attachment->original_name);
    }

    public function thumbnail(Request $request, ChatAttachment $attachment): StreamedResponse
    {
        $this->abortUnlessCanViewAttachment($request, $attachment);

        if ($attachment->thumbnail_path === null || $attachment->thumbnail_disk === null) {
            abort(404);
        }

        return Storage::disk($attachment->thumbnail_disk)->download(
            $attachment->thumbnail_path,
            $this->thumbnailDownloadName($attachment)
        );
    }

    private function abortUnlessCanViewAttachment(Request $request, ChatAttachment $attachment): void
    {
        /** @var User $user */
        $user = $request->user();

        $conversation = $this->attachmentConversation($attachment);

        if ($conversation === null || ($this->resolveViewAccess)($user, $conversation) === null) {
            abort(404);
        }
    }

    private function attachmentConversation(ChatAttachment $attachment): ?ChatConversation
    {
        $link = $attachment->messageAttachment()->with('message.conversation')->first();

        return $link?->message?->conversation;
    }

    private function thumbnailDownloadName(ChatAttachment $attachment): string
    {
        $name = pathinfo($attachment->original_name, PATHINFO_FILENAME);

        return ($name !== '' ? $name : 'thumbnail') . '.jpg';
    }
}
