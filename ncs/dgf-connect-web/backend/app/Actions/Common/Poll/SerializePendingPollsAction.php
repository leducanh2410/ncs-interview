<?php

namespace App\Actions\Common\Poll;

use App\Enums\PollType;
use App\Models\Poll;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

class SerializePendingPollsAction
{
    /**
     * Serialize pending configs for employee auth payloads.
     */
    public function __invoke(Collection|EloquentCollection $pendingConfigs): array
    {
        return $pendingConfigs->map(fn(Poll $poll) => $this->serializePoll($poll))->values()->toArray();
    }

    /**
     * Serialize a single poll for payload.
     */
    private function serializePoll(Poll $poll): array
    {
        $base = [
            'id' => $poll->id,
            'type' => $poll->type->value,
            'title_vi' => $poll->title_vi,
            'title_en' => $poll->title_en,
            'title_ja' => $poll->title_ja,
            'title_ne' => $poll->title_ne ?? $poll->title_en,
            'title_si' => $poll->title_si ?? $poll->title_en,
            'description_vi' => $poll->description_vi,
            'description_en' => $poll->description_en,
            'description_ja' => $poll->description_ja,
            'description_ne' => $poll->description_ne ?? $poll->description_en,
            'description_si' => $poll->description_si ?? $poll->description_en,
            'is_required' => $poll->is_required,
            'expires_at' => $poll->expires_at?->toIso8601String(),
            'is_multiple' => $poll->is_multiple,
            'max_choice' => $poll->max_choice,
        ];

        if ($poll->type === PollType::Vote) {
            $candidateIds = $poll->options;
            $candidates = User::query()
                ->whereIn('id', $candidateIds)
                ->get(['id', 'first_name', 'last_name', 'short_name', 'profile_picture'])
                ->keyBy('id');

            $base['options'] = collect($candidateIds)
                ->map(
                    fn($id) => [
                        'id' => $id,
                        'name' => $candidates->get($id)?->short_name ?? '',
                        'profile_picture' => $candidates->get($id)?->profile_picture ?? '',
                    ]
                )
                ->values()
                ->toArray();
        } else {
            $base['options'] = collect($poll->options)
                ->map(
                    fn(array $option) => array_merge($option, [
                        'label_ne' => $option['label_ne'] ?? $option['label_en'],
                        'label_si' => $option['label_si'] ?? $option['label_en'],
                    ])
                )
                ->values()
                ->toArray();
        }

        return $base;
    }
}
