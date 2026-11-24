<?php

namespace App\Actions\Chat\Support;

use App\Actions\ActionResult;
use App\Enums\SupportType;
use App\Models\SupportTypeAdmin;

class GetSupportTypeAdminsAction
{
    public function __invoke(): ActionResult
    {
        $rows = SupportTypeAdmin::with(['user:id,first_name,last_name,staff_no,profile_picture,short_name'])
            ->get()
            ->groupBy('support_type');

        $data = collect(SupportType::cases())
            ->map(function (SupportType $type) use ($rows) {
                return [
                    'type' => $type->value,
                    'admins' => ($rows[$type->value] ?? collect())
                        ->map(
                            fn($row) => [
                                'user_id' => $row->user_id,
                                'name' => $row->user?->short_name,
                                'staff_no' => $row->user?->staff_no,
                                'profile_picture' => $row->user?->profile_picture,
                            ]
                        )
                        ->values()
                        ->all(),
                ];
            })
            ->values()
            ->all();

        return new ActionResult(data: $data);
    }
}
