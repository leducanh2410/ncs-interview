<?php

namespace App\Actions\Admin\Workplace;

use App\Actions\ActionResult;
use App\Models\Workplace;
use App\Models\WorkplacePosition;
use Illuminate\Support\Facades\DB;

class ReorderPositionAction
{
    public function __invoke(Workplace $workplace, array $params): ActionResult
    {
        $positionIds = $params['positions_order'] ?? [];

        DB::transaction(function () use ($workplace, $positionIds) {
            $order = 1;

            foreach ($positionIds as $id) {
                WorkplacePosition::where('id', $id)
                    ->where('workplace_id', $workplace->id)
                    ->update(['sort_order' => $order++]);
            }
        });

        $sortedPositions = WorkplacePosition::where('workplace_id', $workplace->id)->orderBy('sort_order')->get();

        return new ActionResult(data: $sortedPositions);
    }
}
