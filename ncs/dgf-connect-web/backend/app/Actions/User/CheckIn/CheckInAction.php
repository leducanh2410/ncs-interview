<?php

namespace App\Actions\User\CheckIn;

use App\Actions\ActionResult;
use App\Actions\Common\CheckIn\CoreCheckInAction;
use App\Models\CheckIn;
use Illuminate\Support\Facades\Auth;

readonly class CheckInAction
{
    public function __construct(private CoreCheckInAction $core) {}

    public function __invoke(array $data): ActionResult
    {
        $result = ($this->core)($data, Auth::id());
        if ($result->getError()) {
            return $result;
        }

        $data = $result->getData();
        $checkIn = CheckIn::query()->findOrFail($data['id'])->withReasonRequirements();

        return new ActionResult(
            data: array_merge(
                $data,
                $checkIn->only([
                    'late_minutes',
                    'early_minutes',
                    'late_reason',
                    'early_reason',
                    'late_reason_required',
                    'early_reason_required',
                ])
            )
        );
    }
}
