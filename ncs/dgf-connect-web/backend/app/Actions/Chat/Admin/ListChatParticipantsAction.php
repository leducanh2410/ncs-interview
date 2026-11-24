<?php

namespace App\Actions\Chat\Admin;

use App\Actions\ActionResult;
use App\Actions\Chat\Access\ResolveConversationViewAccessAction;
use App\Actions\Chat\Concerns\ResolvesGlobalAdminUserIds;
use App\Enums\ChatParticipantStatus;
use App\Enums\ErrorCode;
use App\Models\ChatConversation;
use App\Models\ChatParticipant;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;

class ListChatParticipantsAction
{
    use ResolvesGlobalAdminUserIds;

    public function __construct(private readonly ResolveConversationViewAccessAction $resolveAccess) {}

    public function __invoke(User $user, ChatConversation $conversation, array $params): ActionResult
    {
        if (($this->resolveAccess)($user, $conversation) === null) {
            return new ActionResult(error: ErrorCode::NotFound, status: 404);
        }

        $search = isset($params['search']) ? mb_trim($params['search']) : null;
        $search = $search !== '' && $search !== null ? $search : null;
        if ($search !== null) {
            $search = addcslashes($search, '%_\\');
        }
        $perPage = (int) ($params['per_page'] ?? 20);

        /** @var LengthAwarePaginator $paginator */
        $paginator = ChatParticipant::query()
            ->where('conversation_id', $conversation->id)
            ->where('status', ChatParticipantStatus::Active->value)
            ->with(['user:id,first_name,last_name,staff_no,profile_picture,short_name'])
            ->when($search !== null, function ($query) use ($search) {
                $query->whereHas('user', function ($u) use ($search) {
                    $u->whereRaw("CONCAT(last_name, ' ', first_name) LIKE ?", ["%{$search}%"])
                        ->orWhereRaw('staff_no LIKE ?', ["%{$search}%"])
                        ->orWhere('short_name', 'like', "%{$search}%");
                });
            })
            ->orderByRaw("FIELD(role, 'admin', 'deputy', 'employee')")
            ->orderBy('joined_at')
            ->paginate($perPage);

        $globalAdminMap = $this->globalAdminUserIdMap(collect($paginator->items())->pluck('user_id'));

        $paginator->through(
            fn(ChatParticipant $participant) => [
                'user_id' => $participant->user_id,
                'name' => $participant->user?->short_name,
                'staff_no' => $participant->user?->staff_no,
                'profile_picture' => $participant->user?->profile_picture,
                'role' => $participant->role,
                'is_global_admin' => isset($globalAdminMap[$participant->user_id]),
                'joined_at' => optional($participant->joined_at)->toIso8601String(),
            ]
        );

        return new ActionResult(data: $paginator);
    }
}
