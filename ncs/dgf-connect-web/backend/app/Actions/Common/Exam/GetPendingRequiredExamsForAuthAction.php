<?php

namespace App\Actions\Common\Exam;

use App\Models\Exam;
use App\Models\User;
use Illuminate\Support\Facades\App;

class GetPendingRequiredExamsForAuthAction
{
    public function __construct(private readonly GetPendingRequiredExamsAction $getPendingRequiredExamsAction) {}

    public function __invoke(User $user): array
    {
        $locale = App::getLocale();

        return $this->getPendingRequiredExamsAction
            ->__invoke($user)
            ->map(
                fn(Exam $exam) => [
                    'id' => $exam->id,
                    'title' => $exam->translation($locale),
                    'type' => $exam->type->value,
                    'is_required' => (bool) $exam->is_required,
                    'min_score' => (int) $exam->min_score,
                    'time_limit' => (int) $exam->time_limit,
                    'start_at' => $exam->start_at?->format('Y-m-d H:i:s'),
                    'end_at' => $exam->end_at?->format('Y-m-d H:i:s'),
                ]
            )
            ->values()
            ->toArray();
    }
}
