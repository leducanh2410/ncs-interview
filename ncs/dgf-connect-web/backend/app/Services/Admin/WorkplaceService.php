<?php

namespace App\Services\Admin;

use App\Actions\Workplace\AssertTestMembershipMatchAction;
use App\Enums\ErrorCode;
use App\Enums\InstructionType;
use App\Enums\ShiftUserStatus;
use App\Helpers\ImageHelper;
use App\Models\FixedSchedule;
use App\Models\ShiftUser;
use App\Models\User;
use App\Models\Workplace;
use App\Models\WorkplaceImage;
use App\Models\WorkplaceInstruction;
use App\Models\WorkplacePosition;
use App\Models\WorkplaceShift;
use App\Models\WorkplaceUser;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class WorkplaceService
{
    public function __construct(private readonly AssertTestMembershipMatchAction $assertTestMembershipMatch) {}

    public function listWorkplaces(array $conditions): LengthAwarePaginator
    {
        return Workplace::getPaginateFiltered($conditions, null, ['id' => 'asc'], $conditions['page_size'] ?? null);
    }

    public function store(array $params): Workplace
    {
        [$latitude, $longitude] = explode(',', $params['coordinates']);
        $params['latitude'] = mb_trim($latitude);
        $params['longitude'] = mb_trim($longitude);
        unset($params['coordinates']);

        return Workplace::query()->create($params);
    }

    public function show(Workplace $workplace): Workplace
    {
        return $workplace->load(['positions', 'users', 'images', 'shifts', 'instructions']);
    }

    public function update(Workplace $workplace, array $params): ?ErrorCode
    {
        if (array_key_exists('is_test', $params)) {
            $newIsTest = (bool) $params['is_test'];
            if ($newIsTest !== (bool) $workplace->is_test) {
                $userIds = $workplace->users()->pluck('users.id')->toArray();
                if (!$this->assertTestMembershipMatch->flagMatchesUsers($newIsTest, $userIds)) {
                    return ErrorCode::WorkplaceTestMembershipMismatch;
                }
            }
        }

        [$latitude, $longitude] = explode(',', $params['coordinates']);
        $params['latitude'] = mb_trim($latitude);
        $params['longitude'] = mb_trim($longitude);
        unset($params['coordinates']);

        $workplace->update($params);

        return null;
    }

    public function destroy(Workplace $workplace): array
    {
        $hasShift = ShiftUser::query()
            ->whereNot('status', ShiftUserStatus::Rejected->value)
            ->whereHas('shift', function ($query) use ($workplace) {
                $query->where('workplace_id', $workplace->id);
            })
            ->exists();
        if ($hasShift) {
            return [
                'error' => ErrorCode::WorkplaceCannotDeleteWithShift,
                'status' => 400,
            ];
        }

        $images = $workplace->images->pluck('image');
        $instructionImages = $workplace
            ->instructions()
            ->where('type', InstructionType::Image->value)
            ->get()
            ->pluck('content');

        try {
            $workplace->delete();

            foreach ($images as $image) {
                Storage::delete($image);
            }

            foreach ($instructionImages as $image) {
                Storage::delete($image);
            }
        } catch (Throwable $e) {
            Log::error($e);

            return [
                'error' => ErrorCode::BadRequest,
                'status' => 400,
            ];
        }

        return [
            'error' => null,
        ];
    }

    /****************************************
     * Image                                *
     ****************************************/

    public function listImages(Workplace $workplace): Collection
    {
        return WorkplaceImage::query()->where('workplace_id', $workplace->id)->get();
    }

    public function uploadImages(Workplace $workplace, array $params): bool
    {
        try {
            $imagePaths = collect($params['images'])
                ->map(function ($image) use ($workplace) {
                    $path = ImageHelper::handleImageUpload($image, 'workplaces/' . $workplace->id);

                    return [
                        'workplace_id' => $workplace->id,
                        'image' => $path,
                    ];
                })
                ->toArray();

            $workplace->images()->createMany($imagePaths);

            return true;
        } catch (\Exception $exception) {
            Log::error('Error storing workplace image', [$exception->getMessage()]);

            return false;
        }
    }

    public function deleteImage(WorkplaceImage $workplaceImage): ?bool
    {
        Storage::delete($workplaceImage->image);

        return $workplaceImage->delete();
    }

    /****************************************
     * Position                             *
     ****************************************/

    public function listPositions(Workplace $workplace): Collection
    {
        return WorkplacePosition::query()
            ->where('workplace_id', $workplace->id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();
    }

    public function storePosition(Workplace $workplace, array $params)
    {
        $maxSortOrder =
            WorkplacePosition::where('workplace_id', $workplace->id)->where('is_active', true)->max('sort_order') ?? 0;

        $params['sort_order'] = $maxSortOrder + 1;

        return $this->rejectDuplicateName(fn() => $workplace->positions()->create($params));
    }

    public function updatePosition(WorkplacePosition $workplacePosition, array $params): bool
    {
        return $this->rejectDuplicateName(fn() => $workplacePosition->update($params));
    }

    public function deletePosition(WorkplacePosition $workplacePosition): bool
    {
        try {
            $workplacePosition->delete();
        } catch (Throwable $e) {
            return $workplacePosition->update(['is_active' => false]);
        }

        return true;
    }

    /****************************************
     * Shift                                *
     ****************************************/

    public function listShifts(Workplace $workplace): Collection
    {
        return $workplace
            ->shifts()
            ->with(['leaders.user'])
            ->where('is_active', true)
            ->get();
    }

    public function deleteShift(WorkplaceShift $workplaceShift): bool
    {
        try {
            $workplaceShift->delete();
        } catch (Throwable $e) {
            return $workplaceShift->update(['is_active' => false]);
        }

        return true;
    }

    /****************************************
     * Instruction                          *
     ****************************************/

    public function listInstructions(Workplace $workplace): Collection
    {
        return $workplace->instructions()->get();
    }

    public function storeInstruction(Workplace $workplace, array $params)
    {
        if (isset($params['file'])) {
            $params['content'] = ImageHelper::handleImageUpload($params['file'], 'workplaces/' . $workplace->id);
            unset($params['file']);
        }

        return $workplace->instructions()->create($params);
    }

    public function updateInstruction(WorkplaceInstruction $workplaceInstruction, array $params): bool
    {
        $deletePath = null;
        if ($workplaceInstruction->type === InstructionType::Image->value) {
            $deletePath = $workplaceInstruction->content;
            if ($params['type'] !== InstructionType::Image->value) {
                Storage::delete($deletePath);
            }
        }
        if ($params['type'] === InstructionType::Image->value) {
            unset($params['content']);
        }
        if (isset($params['file'])) {
            $params['content'] = ImageHelper::handleImageUpload(
                $params['file'],
                'workplaces/' . $workplaceInstruction->workplace_id,
                deletePath: $deletePath
            );
            unset($params['file']);
        }

        return $workplaceInstruction->update($params);
    }

    public function deleteInstruction(WorkplaceInstruction $workplaceInstruction): ?bool
    {
        if ($workplaceInstruction->type === InstructionType::Image->value) {
            Storage::delete($workplaceInstruction->content);
        }

        return $workplaceInstruction->delete();
    }

    /****************************************
     * User                                 *
     ****************************************/

    public function getListUsers(
        Workplace $workplace,
        ?int $pageSize = null
    ): \Illuminate\Contracts\Pagination\LengthAwarePaginator {
        return $workplace
            ->users()
            ->with(['info'])
            ->paginate($pageSize);
    }

    public function attachUsers(Workplace $workplace, array $params): ?ErrorCode
    {
        if (!$this->assertTestMembershipMatch->flagMatchesUsers((bool) $workplace->is_test, $params['user_ids'])) {
            return ErrorCode::WorkplaceTestMembershipMismatch;
        }

        $workplaceUserIds = WorkplaceUser::query()
            ->whereIn('user_id', $params['user_ids'])
            ->where('workplace_id', $workplace->id)
            ->get()
            ->pluck('user_id')
            ->toArray();

        $userIds = array_diff($params['user_ids'], $workplaceUserIds);
        if (empty($userIds)) {
            return null;
        }

        $workplace->users()->attach($userIds);

        return null;
    }

    public function detachUser(Workplace $workplace, User $user): array
    {
        $today = Carbon::today()->format('Y-m-d');
        $hasFutureShift = ShiftUser::query()
            ->where('user_id', $user->id)
            ->where('status', ShiftUserStatus::Approved->value)
            ->whereHas('shift', function ($query) use ($workplace, $today) {
                $query->where('workplace_id', $workplace->id)->where('date', '>=', $today);
            })
            ->exists();
        if ($hasFutureShift) {
            return [
                'error' => ErrorCode::WorkplaceCannotRemoveUserWithShift,
                'status' => 400,
            ];
        }

        DB::transaction(function () use ($workplace, $user) {
            $workplace->users()->detach($user->id);
            FixedSchedule::query()->where('workplace_id', $workplace->id)->where('user_id', $user->id)->delete();
            ShiftUser::query()
                ->where('user_id', $user->id)
                ->whereHas('shift', function ($query) use ($workplace) {
                    $query->where('workplace_id', $workplace->id)->where('date', '>=', Carbon::today());
                })
                ->delete();
        });

        return [
            'error' => null,
        ];
    }

    private function rejectDuplicateName(callable $write): mixed
    {
        try {
            return $write();
        } catch (UniqueConstraintViolationException $e) {
            // The driver parses the index name out of the error message, so it can come
            // back null. The name index is the only unique constraint a write here can
            // violate, so treat an unidentified violation as a duplicate name too.
            if ($e->index !== null && $e->index !== WorkplacePosition::NAME_UNIQUE_INDEX) {
                throw $e;
            }

            throw ValidationException::withMessages([
                'name' => trans('validation.unique', ['attribute' => 'name']),
            ]);
        }
    }
}
