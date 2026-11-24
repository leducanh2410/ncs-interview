<?php

namespace App\Actions\Admin\AdminLeave;

use App\Helpers\UrlHelper;
use App\Models\AdminLeaveRequest;
use App\Models\AdminLeaveRequestAttachment;

/**
 * One definition of the flat row shape the web admin and the mobile app consume, so the list, the
 * "mine" list, and the detail cannot drift apart field by field.
 */
class AdminLeaveRequestPayload
{
    /**
     * @return array<string, mixed>
     */
    public static function forList(AdminLeaveRequest $request, bool $hasOverlap): array
    {
        return [
            ...self::base($request),
            'attachments_count' => $request->attachments_count ?? $request->attachments()->count(),
            'has_overlap' => $hasOverlap,
        ];
    }

    /**
     * @param array<string, mixed>|null $conflict dates and status of the conflicting request
     * @return array<string, mixed>
     */
    public static function forDetail(AdminLeaveRequest $request, ?array $conflict): array
    {
        return [
            ...self::base($request),
            'attachments_count' => $request->attachments->count(),
            'has_overlap' => $conflict !== null,
            // Named so the reviewer can judge the conflict without leaving the screen.
            'conflict' => $conflict,
            'attachments' => $request->attachments
                ->map(
                    fn(AdminLeaveRequestAttachment $attachment) => [
                        'id' => $attachment->id,
                        'original_name' => $attachment->original_name,
                        'mime_type' => $attachment->mime_type,
                        'size_bytes' => $attachment->size_bytes,
                        'is_image' => $attachment->is_image,
                        // Minted per response, never stored: the file is only ever reachable through
                        // a short-lived URL.
                        'url' => UrlHelper::getStorageUrl($attachment->path, disk: $attachment->disk),
                    ]
                )
                ->values(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function base(AdminLeaveRequest $request): array
    {
        return [
            'id' => $request->id,
            'requester_id' => $request->requester_id,
            'requester_name' => $request->requester?->name,
            'requester_staff_no' => $request->requester?->staff_no,
            'requester_profile_picture' => $request->requester?->profile_picture,
            'type' => $request->type->value,
            'start_date' => $request->start_date?->toDateString(),
            'end_date' => $request->end_date?->toDateString(),
            'leave_days' => $request->leave_days,
            'reason' => $request->reason,
            'status' => $request->status->value,
            'created_at' => $request->created_at?->toIso8601String(),
            // Null while pending; the web admin renders these as `-`.
            'decided_by_id' => $request->decided_by_id,
            'decided_by_name' => $request->decidedBy?->name,
            'decided_at' => $request->decided_at?->toIso8601String(),
            'reject_reason' => $request->reject_reason,
        ];
    }
}
