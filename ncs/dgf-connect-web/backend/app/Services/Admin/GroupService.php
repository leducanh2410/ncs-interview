<?php

namespace App\Services\Admin;

use App\Actions\Admin\Group\AttachManualGroupMemberAction;
use App\Actions\Admin\Group\BatchAttachManualGroupMembersAction;
use App\Actions\Admin\Group\CreateAdminGroupAction;
use App\Actions\Admin\Group\DeleteAdminGroupAction;
use App\Actions\Admin\Group\DetachManualGroupMemberAction;
use App\Actions\Admin\Group\UpdateAdminGroupAction;
use App\Helpers\ResponseHelper;
use App\Models\Group;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\App;

class GroupService
{
    public function __construct(
        private readonly CreateAdminGroupAction $createAdminGroup,
        private readonly UpdateAdminGroupAction $updateAdminGroup,
        private readonly DeleteAdminGroupAction $deleteAdminGroup,
        private readonly AttachManualGroupMemberAction $attachManualGroupMember,
        private readonly BatchAttachManualGroupMembersAction $batchAttachManualGroupMembers,
        private readonly DetachManualGroupMemberAction $detachManualGroupMember
    ) {}

    public function list(array $conditions): LengthAwarePaginator
    {
        $search = $conditions['search'] ?? null;
        $locale = $this->resolveDisplayNameLocale();

        return Group::query()
            ->with(['workplace:id,name'])
            ->withCount([
                'users as member_count' => function (Builder $query) {
                    $query->where('users.is_disabled', false);
                },
            ])
            ->when(
                !empty($conditions['kind']),
                fn(Builder $query) => $query->whereIn('groups.kind', $conditions['kind'])
            )
            ->when($search, function (Builder $query, string $search) use ($locale) {
                $query->whereRaw("LOWER(JSON_UNQUOTE(JSON_EXTRACT(display_name, '$." . $locale . "')) ) LIKE ?", [
                    '%' . mb_strtolower($search) . '%',
                ]);
            })
            ->orderBy('groups.kind')
            ->orderBy('groups.id')
            ->paginate($conditions['per_page'] ?? null)
            ->through(fn(Group $group) => $this->serializeGroup($group));
    }

    public function show(Group $group, ?int $membersPerPage = null, ?int $membersPage = null): array
    {
        $group->load(['workplace:id,name'])->loadCount([
            'users as member_count' => function (Builder $query) {
                $query->where('users.is_disabled', false);
            },
        ]);

        $members = $group
            ->users()
            ->where('users.is_disabled', false)
            ->with([
                'workplaces' => function ($query) {
                    $query->select(['workplaces.id', 'workplaces.name']);
                },
            ])
            ->select('users.*')
            ->orderBy('users.id')
            ->paginate($membersPerPage ?? 15, ['users.*'], 'members_page', $membersPage)
            ->through(fn(User $user) => $this->serializeMember($user));

        return [...$this->serializeGroup($group), 'members' => ResponseHelper::transformPagination($members)];
    }

    public function store(array $payload): array
    {
        $group = ($this->createAdminGroup)($payload);

        return $this->serializeGroup($this->loadGroupSummary($group));
    }

    public function update(Group $group, array $payload): array
    {
        $group = ($this->updateAdminGroup)($group, $payload);

        return $this->serializeGroup($this->loadGroupSummary($group));
    }

    public function destroy(Group $group): void
    {
        ($this->deleteAdminGroup)($group);
    }

    public function listUserGroups(User $user): array
    {
        return $user
            ->groups()
            ->with(['workplace:id,name'])
            ->withCount([
                'users as member_count' => function (Builder $query) {
                    $query->where('users.is_disabled', false);
                },
            ])
            ->orderBy('groups.kind')
            ->orderBy('groups.source_workplace_id')
            ->orderBy('groups.source_tier')
            ->orderBy('groups.id')
            ->get()
            ->map(fn(Group $group) => $this->serializeGroup($group))
            ->all();
    }

    public function attachMember(Group $group, int $userId): void
    {
        $user = User::query()->findOrFail($userId);

        ($this->attachManualGroupMember)($group, $user);
    }

    public function batchAttachMembers(Group $group, array $userIds): void
    {
        ($this->batchAttachManualGroupMembers)($group, $userIds);
    }

    public function detachMember(Group $group, int $userId): void
    {
        $user = User::query()->findOrFail($userId);

        ($this->detachManualGroupMember)($group, $user);
    }

    private function resolveDisplayNameLocale(): string
    {
        $locale = mb_strtolower((string) App::getLocale());
        $base = explode('_', explode('-', $locale)[0])[0];

        return in_array($base, ['en', 'ja', 'vi'], true) ? $base : 'en';
    }

    private function loadGroupSummary(Group $group): Group
    {
        return $group->load(['workplace:id,name'])->loadCount([
            'users as member_count' => function (Builder $query) {
                $query->where('users.is_disabled', false);
            },
        ]);
    }

    private function serializeGroup(Group $group): array
    {
        return [
            'id' => $group->id,
            'kind' => $group->kind->value,
            'name' => $group->name,
            'display_name' => $group->display_name ?? ['en' => '', 'ja' => '', 'vi' => ''],
            'source_workplace_id' => $group->source_workplace_id,
            'source_workplace' =>
                $group->workplace === null
                    ? null
                    : [
                        'id' => $group->workplace->id,
                        'name' => $group->workplace->name,
                    ],
            'source_prefecture' => $group->source_prefecture,
            'source_tier' => $group->source_tier?->value,
            'predicate' => $group->predicate,
            'member_count' => (int) ($group->member_count ?? 0),
        ];
    }

    private function serializeMember(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->short_name,
            'staff_no' => $user->staff_no,
            'current_workplaces' => $user->workplaces
                ->map(
                    fn($workplace) => [
                        'id' => $workplace->id,
                        'name' => $workplace->name,
                    ]
                )
                ->values()
                ->all(),
        ];
    }
}
