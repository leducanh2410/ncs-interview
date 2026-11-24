<?php

namespace App\Http\Requests\Admin\Poll;

use App\Enums\PollTargetType;
use App\Enums\PollType;
use App\Models\Poll;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdatePollRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'type' => ['bail', 'sometimes', 'string', Rule::in(PollType::values())],
            'target_type' => ['bail', 'sometimes', 'string', Rule::enum(PollTargetType::class)],
            'workplace_id' => ['bail', 'nullable', 'integer', 'exists:workplaces,id'],
            'target_users' => ['bail', 'nullable', 'array'],
            'target_users.*' => ['integer', 'exists:users,id'],
            'title_vi' => ['bail', 'required', 'string', 'max:255'],
            'title_en' => ['bail', 'required', 'string', 'max:255'],
            'title_ja' => ['bail', 'required', 'string', 'max:255'],
            'title_ne' => ['bail', 'nullable', 'string', 'max:255'],
            'title_si' => ['bail', 'nullable', 'string', 'max:255'],
            'description_vi' => ['bail', 'nullable', 'string', 'max:1000'],
            'description_en' => ['bail', 'nullable', 'string', 'max:1000'],
            'description_ja' => ['bail', 'nullable', 'string', 'max:1000'],
            'description_ne' => ['bail', 'nullable', 'string', 'max:1000'],
            'description_si' => ['bail', 'nullable', 'string', 'max:1000'],
            'is_required' => ['bail', 'boolean'],
            'is_recurring' => ['bail', 'sometimes', 'boolean'],
            'expires_at' => ['bail', 'nullable', 'date', 'dateFormat:Y-m-d', 'after:today'],
            'is_multiple' => ['bail', 'boolean'],
            'max_choice' => ['bail', 'nullable', 'integer', 'min:1'],
            'options' => ['bail', 'array', 'min:1'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->has('options')) {
                    return;
                }

                $poll = $this->route('poll');
                if (!$poll) {
                    return;
                }

                $this->validateRecurring($validator, $poll);

                $hasResponses = \App\Models\PollResponse::query()->where('poll_id', $poll->id)->exists();

                if ($hasResponses) {
                    $lockedFields = [
                        'target_type',
                        'workplace_id',
                        'target_users',
                        'options',
                        'is_multiple',
                        'max_choice',
                    ];
                    foreach ($lockedFields as $field) {
                        if ($this->has($field)) {
                            $validator->errors()->add($field, __('poll.locked_field', ['field' => $field]));
                        }
                    }

                    return;
                }

                $targetType = $this->has('target_type') ? $this->input('target_type') : $poll->target_type?->value;
                $workplaceId = $this->has('workplace_id') ? $this->input('workplace_id') : $poll->workplace_id;

                if ($targetType === PollTargetType::WORKPLACE->value && $workplaceId === null) {
                    $validator->errors()->add('workplace_id', __('poll.workplace_required'));
                }

                if (
                    $targetType !== PollTargetType::WORKPLACE->value &&
                    $this->has('workplace_id') &&
                    $this->input('workplace_id') !== null
                ) {
                    $validator->errors()->add('workplace_id', __('poll.workplace_not_allowed'));
                }

                // The action only syncs targetUsers when the key is submitted, so an omitted key
                // means the poll keeps whichever recipients it already has.
                $targetUsers = $this->has('target_users')
                    ? (array) $this->input('target_users')
                    : $poll->targetUsers()->pluck('users.id')->all();

                if ($targetType === PollTargetType::SPECIFIC_USERS->value && count($targetUsers) === 0) {
                    $validator->errors()->add('target_users', __('poll.target_users_required'));
                }

                $options = $this->input('options');
                if ($options === null) {
                    return;
                }

                $type = $poll->type;

                if ($type === PollType::Vote) {
                    $this->validateVoteOptions($validator, $options);
                } else {
                    $this->validateFeedbackOptions($validator, $options);
                }

                $this->validateMaxChoice($validator, $options);
            },
        ];
    }

    private function validateRecurring(Validator $validator, Poll $poll): void
    {
        $isRecurring = $this->has('is_recurring') ? $this->boolean('is_recurring') : $poll->is_recurring;
        if (!$isRecurring) {
            return;
        }

        $targetType = $this->has('target_type') ? $this->input('target_type') : $poll->target_type?->value;

        if ($poll->type !== PollType::Feedback || $targetType !== PollTargetType::WORKPLACE->value) {
            $validator->errors()->add('is_recurring', __('poll.recurring_not_allowed'));
        }
    }

    private function validateVoteOptions(Validator $validator, array $options): void
    {
        foreach ($options as $index => $userId) {
            if (!is_int($userId)) {
                $validator->errors()->add("options.{$index}", 'Vote options must be user IDs (integers).');

                continue;
            }

            if (!\App\Models\User::where('id', $userId)->exists()) {
                $validator->errors()->add("options.{$index}", 'Selected user does not exist.');
            }
        }

        $uniqueIds = array_unique($options);
        if (count($uniqueIds) !== count($options)) {
            $validator->errors()->add('options', 'Duplicate candidate IDs are not allowed.');
        }
    }

    private function validateFeedbackOptions(Validator $validator, array $options): void
    {
        foreach ($options as $index => $option) {
            if (!is_array($option)) {
                $validator->errors()->add("options.{$index}", 'Feedback options must be objects with labels.');

                continue;
            }

            $requiredLabels = ['label_vi', 'label_en', 'label_ja'];
            foreach ($requiredLabels as $label) {
                if (!isset($option[$label]) || !is_string($option[$label]) || mb_strlen($option[$label]) === 0) {
                    $validator->errors()->add("options.{$index}.{$label}", "Label {$label} is required.");
                }

                if (isset($option[$label]) && mb_strlen($option[$label]) > 255) {
                    $validator
                        ->errors()
                        ->add("options.{$index}.{$label}", "Label {$label} must not exceed 255 characters.");
                }
            }

            // ne/si labels are optional (filled by AI translate or left blank), but must be
            // valid strings when present.
            foreach (['label_ne', 'label_si'] as $label) {
                if (!isset($option[$label])) {
                    continue;
                }
                if (!is_string($option[$label])) {
                    $validator->errors()->add("options.{$index}.{$label}", "Label {$label} must be a string.");
                } elseif (mb_strlen($option[$label]) > 255) {
                    $validator
                        ->errors()
                        ->add("options.{$index}.{$label}", "Label {$label} must not exceed 255 characters.");
                }
            }

            if (isset($option['id'])) {
                if (!is_string($option['id'])) {
                    $validator->errors()->add("options.{$index}.id", 'Option ID must be a string.');
                }
            }
        }

        $ids = array_filter(array_column($options, 'id'));
        $uniqueIds = array_unique($ids);
        if (count($uniqueIds) !== count($ids)) {
            $validator->errors()->add('options', 'Duplicate option IDs are not allowed.');
        }
    }

    private function validateMaxChoice(Validator $validator, array $options): void
    {
        $isMultiple = $this->input('is_multiple');
        $maxChoice = $this->input('max_choice');
        $optionCount = count($options);

        if ($isMultiple === false) {
            return;
        }

        if ($maxChoice === null) {
            return;
        }

        if ($maxChoice > $optionCount) {
            $validator->errors()->add('max_choice', 'Max choice cannot exceed the number of options.');
        }
    }
}
