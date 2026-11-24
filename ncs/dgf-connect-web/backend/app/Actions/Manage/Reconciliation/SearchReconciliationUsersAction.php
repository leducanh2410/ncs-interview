<?php

namespace App\Actions\Manage\Reconciliation;

use App\Actions\ActionResult;
use App\Actions\Workplace\ShouldShowTestWorkplacesAction;
use App\Models\CheckIn;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class SearchReconciliationUsersAction
{
    public function __construct(
        private readonly ApplyReconciliationMatchAction $applyMatch,
        private readonly ShouldShowTestWorkplacesAction $shouldShowTestWorkplaces
    ) {}

    public function __invoke(
        User $actor,
        ?string $query,
        ?string $workDate,
        int $pageSize = 20,
        ?int $key = null
    ): ActionResult {
        if ($key !== null) {
            $users = User::query()->where('id', $key)->get();
        } else {
            $users = $this->searchUsers($actor, (string) $query, $workDate, $pageSize);
        }

        $showTest = ($this->shouldShowTestWorkplaces)($actor);
        $parsedWorkDate = $workDate ? Carbon::parse($workDate)->toDateString() : null;
        $checkInsByUser = $this->loadCheckIns($users, $parsedWorkDate, $showTest);

        $data = $users
            ->map(
                fn(User $user) => [
                    'id' => $user->id,
                    'staff_no' => $user->staff_no,
                    'name' => $user->short_name ?: $user->name,
                    'name_kana' => $user->name_kana,
                    'profile_picture' => $user->profile_picture,
                    'check_ins' => ($checkInsByUser[$user->id] ?? collect())
                        ->map(fn(CheckIn $checkIn) => $this->checkInSummary($checkIn))
                        ->all(),
                ]
            )
            ->values();

        return new ActionResult(data: $data->all());
    }

    /**
     * @return Collection<int, User>
     */
    private function searchUsers(User $actor, string $query, ?string $workDate, int $pageSize): Collection
    {
        $matching = function () use ($query) {
            $like = '%' . $query . '%';

            return User::query()->where(function ($builder) use ($like) {
                $builder
                    ->where('first_name', 'like', $like)
                    ->orWhere('last_name', 'like', $like)
                    ->orWhere('first_name_kana', 'like', $like)
                    ->orWhere('last_name_kana', 'like', $like)
                    ->orWhere('short_name', 'like', $like)
                    ->orWhere('staff_no', 'like', $like);
            });
        };

        if (!$workDate) {
            return $matching()->orderBy('id')->limit($pageSize)->get();
        }

        $priorityIds = array_keys($this->applyMatch->priorityUserIds([$workDate], $actor));
        if ($priorityIds === []) {
            return $matching()->orderBy('id')->limit($pageSize)->get();
        }

        $priority = $matching()->whereIn('id', $priorityIds)->orderBy('id')->limit($pageSize)->get();
        $remaining = $pageSize - $priority->count();
        if ($remaining <= 0) {
            return $priority->values();
        }

        $rest = $matching()
            ->when($priority->isNotEmpty(), fn($builder) => $builder->whereNotIn('id', $priority->pluck('id')))
            ->orderBy('id')
            ->limit($remaining)
            ->get();

        return $priority->concat($rest)->values();
    }

    /**
     * @param Collection<int, User> $users
     * @return array<int, Collection<int, CheckIn>>
     */
    private function loadCheckIns(Collection $users, ?string $workDate, bool $showTest): array
    {
        if ($workDate === null || $users->isEmpty()) {
            return [];
        }

        $userIds = $users->pluck('id')->all();
        $indexed = $this->applyMatch->indexCheckInsByUserAndDate(
            $this->applyMatch->findCheckInsForUsers($userIds, [$workDate], $showTest),
            array_fill_keys($userIds, [$workDate => true])
        );

        $byUser = [];
        foreach ($indexed as $userId => $byDate) {
            $byUser[$userId] = $byDate[$workDate] ?? collect();
        }

        return $byUser;
    }

    /**
     * @return array<string, mixed>
     */
    private function checkInSummary(CheckIn $checkIn): array
    {
        $shift = $checkIn->shift;
        $template = $shift->workplaceShift;

        return [
            'id' => $checkIn->id,
            'workplace_id' => $shift->workplace_id,
            'workplace_name' => $shift->workplace->name,
            'shift_time' => mb_trim(($template?->start_time ?? '') . '-' . ($template?->end_time ?? ''), '-'),
            'check_in_time' => $checkIn->check_in_time,
            'check_out_time' => $checkIn->check_out_time,
        ];
    }
}
