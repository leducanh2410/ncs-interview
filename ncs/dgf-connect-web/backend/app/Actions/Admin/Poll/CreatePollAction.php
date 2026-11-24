<?php

namespace App\Actions\Admin\Poll;

use App\Actions\ActionResult;
use App\Actions\Common\Poll\FlagEligibleUsersAction;
use App\Enums\PollTargetType;
use App\Enums\PollType;
use App\Models\Poll;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class CreatePollAction
{
    private FlagEligibleUsersAction $flagEligibleUsersAction;

    public function __construct(FlagEligibleUsersAction $flagEligibleUsersAction)
    {
        $this->flagEligibleUsersAction = $flagEligibleUsersAction;
    }

    /**
     * Create a new poll.
     */
    public function __invoke(array $data, User $creator): ActionResult
    {
        $options = $data['options'];
        $type = PollType::from($data['type']);

        if ($type === PollType::Feedback) {
            $options = $this->normalizeFeedbackOptions($options);
        }

        $resultCounters = $this->initializeResultCounters($type, $options);

        $isMultiple = $data['is_multiple'] ?? false;
        $maxChoice = $data['max_choice'] ?? ($isMultiple ? count($options) : 1);

        if (!$isMultiple) {
            $maxChoice = 1;
        }

        $targetType = $this->resolveTargetType($data);
        $isRecurring = (bool) ($data['is_recurring'] ?? false);

        $poll = Poll::create([
            'type' => $type->value,
            'target_type' => $targetType,
            'workplace_id' => Arr::get($data, 'workplace_id'),
            'title_vi' => $data['title_vi'],
            'title_en' => $data['title_en'],
            'title_ja' => $data['title_ja'],
            'title_ne' => Arr::get($data, 'title_ne'),
            'title_si' => Arr::get($data, 'title_si'),
            'description_vi' => Arr::get($data, 'description_vi'),
            'description_en' => Arr::get($data, 'description_en'),
            'description_ja' => Arr::get($data, 'description_ja'),
            'description_ne' => Arr::get($data, 'description_ne'),
            'description_si' => Arr::get($data, 'description_si'),
            'is_active' => $data['is_active'] ?? false,
            'is_required' => $isRecurring || ($data['is_required'] ?? false),
            'is_recurring' => $isRecurring,
            'expires_at' => $isRecurring ? null : Arr::get($data, 'expires_at'),
            'is_multiple' => $isMultiple,
            'max_choice' => $maxChoice,
            'options' => $options,
            'result_counters' => $resultCounters,
            'created_by' => $creator->id,
        ]);

        if ($targetType === PollTargetType::SPECIFIC_USERS->value && !empty($data['target_users'])) {
            $poll->targetUsers()->sync($data['target_users']);
        }

        if ($poll->is_active) {
            $this->flagEligibleUsersAction->__invoke($poll);
        }

        return new ActionResult(data: $poll);
    }

    private function resolveTargetType(array $data): string
    {
        if (isset($data['target_type'])) {
            return $data['target_type'];
        }

        return Arr::get($data, 'workplace_id') !== null
            ? PollTargetType::WORKPLACE->value
            : PollTargetType::GLOBAL->value;
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
