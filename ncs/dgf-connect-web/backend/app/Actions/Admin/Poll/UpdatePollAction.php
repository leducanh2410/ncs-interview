<?php

namespace App\Actions\Admin\Poll;

use App\Actions\ActionResult;
use App\Actions\Common\Poll\FlagEligibleUsersAction;
use App\Enums\ErrorCode;
use App\Enums\PollTargetType;
use App\Enums\PollType;
use App\Models\Poll;
use App\Models\PollResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class UpdatePollAction
{
    public function __construct(private readonly FlagEligibleUsersAction $flagEligibleUsers) {}

    /**
     * Update a poll.
     */
    public function __invoke(int $id, array $data): ActionResult
    {
        $poll = Poll::query()->find($id);

        if (!$poll) {
            return new ActionResult(error: ErrorCode::NotFound, status: 404);
        }

        $hasResponses = PollResponse::query()->where('poll_id', $id)->exists();

        if ($poll->is_active || $hasResponses) {
            $lockedFields = ['target_type', 'workplace_id', 'target_users', 'options', 'is_multiple', 'max_choice'];
            foreach ($lockedFields as $field) {
                if (Arr::has($data, $field)) {
                    return new ActionResult(
                        error: ErrorCode::UnprocessableEntity,
                        status: 422,
                        data: ['message' => __('poll.locked_field', ['field' => $field])]
                    );
                }
            }
        }

        $type = $poll->type;
        $options = $poll->options;

        if (!$hasResponses && !$poll->is_active && Arr::has($data, 'options')) {
            $options = $data['options'];

            if ($type === PollType::Feedback) {
                $options = $this->normalizeFeedbackOptions($options);
            }

            $data['result_counters'] = $this->initializeResultCounters($type, $options);
        }

        if (!$hasResponses && !$poll->is_active && Arr::has($data, 'is_multiple')) {
            $isMultiple = $data['is_multiple'];

            if (!$isMultiple) {
                $data['max_choice'] = 1;
            } elseif (!Arr::has($data, 'max_choice')) {
                $data['max_choice'] = count($options);
            }
        }

        Arr::forget($data, 'is_active');

        $updateData = Arr::only($data, [
            'target_type',
            'workplace_id',
            'title_vi',
            'title_en',
            'title_ja',
            'title_ne',
            'title_si',
            'description_vi',
            'description_en',
            'description_ja',
            'description_ne',
            'description_si',
            'is_required',
            'is_recurring',
            'expires_at',
            'is_multiple',
            'max_choice',
            'options',
            'result_counters',
        ]);

        $wasRecurring = $poll->is_recurring;
        $isRecurring = Arr::has($updateData, 'is_recurring') ? (bool) $updateData['is_recurring'] : $poll->is_recurring;

        if ($isRecurring) {
            $updateData['is_required'] = true;
            $updateData['expires_at'] = null;
        }

        if (Arr::has($updateData, 'options')) {
            $updateData['options'] = $options;
        }

        $poll->update($updateData);

        if ($poll->is_active && $wasRecurring && !$isRecurring) {
            ($this->flagEligibleUsers)($poll);
        }

        if (!$hasResponses && !$poll->is_active) {
            $targetType = $data['target_type'] ?? $poll->target_type?->value;
            if ($targetType === PollTargetType::SPECIFIC_USERS->value && isset($data['target_users'])) {
                $poll->targetUsers()->sync($data['target_users']);
            } elseif ($targetType !== PollTargetType::SPECIFIC_USERS->value) {
                $poll->targetUsers()->sync([]);
            }
        }

        return new ActionResult(data: $poll->fresh());
    }

    /**
     * Normalize feedback options.
     */
    private function normalizeFeedbackOptions(array $options): array
    {
        $normalized = [];
        $seenIds = [];

        foreach ($options as $option) {
            $id = Arr::get($option, 'id');

            if (!$id) {
                $id = (string) Str::ulid();
            }

            if (in_array($id, $seenIds, true)) {
                continue;
            }
            $seenIds[] = $id;

            $normalized[] = [
                'id' => $id,
                'label_vi' => $option['label_vi'],
                'label_en' => $option['label_en'],
                'label_ja' => $option['label_ja'],
                'label_ne' => Arr::get($option, 'label_ne'),
                'label_si' => Arr::get($option, 'label_si'),
            ];
        }

        return $normalized;
    }

    /**
     * Initialize result counters to zero-counts based on options.
     */
    private function initializeResultCounters(PollType $type, array $options): array
    {
        $counters = [];

        if ($type === PollType::Vote) {
            foreach ($options as $userId) {
                $key = (string) $userId;
                $counters[$key] = ['yes' => 0, 'no' => 0, 'blank' => 0];
            }
        } else {
            foreach ($options as $option) {
                $id = Arr::get($option, 'id');
                if ($id) {
                    $counters[(string) $id] = 0;
                }
            }
        }

        return $counters;
    }
}
