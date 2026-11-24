<?php

namespace App\Http\Requests\Admin\Poll;

use App\Enums\PollTargetType;
use App\Enums\PollType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StorePollRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'type' => ['bail', 'required', 'string', Rule::in(PollType::values())],
            'target_type' => ['bail', 'required', 'string', Rule::enum(PollTargetType::class)],
            'workplace_id' => [
                'bail',
                'required_if:target_type,' . PollTargetType::WORKPLACE->value,
                'nullable',
                'integer',
                'exists:workplaces,id',
            ],
            'target_users' => [
                'bail',
                'required_if:target_type,' . PollTargetType::SPECIFIC_USERS->value,
                'nullable',
                'array',
            ],
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
            'is_active' => ['bail', 'boolean'],
            'is_required' => ['bail', 'boolean'],
            'is_recurring' => ['bail', 'sometimes', 'boolean'],
            'expires_at' => ['bail', 'nullable', 'date', 'dateFormat:Y-m-d', 'after:today'],
            'is_multiple' => ['bail', 'required', 'boolean'],
            'max_choice' => ['bail', 'nullable', 'integer', 'min:1'],
            'options' => ['bail', 'required', 'array', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return [
            'workplace_id.required_if' => __('poll.workplace_required'),
            'target_users.required_if' => __('poll.target_users_required'),
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                $this->validateRecurring($validator);

                if ($validator->errors()->has('type') || $validator->errors()->has('options')) {
                    return;
                }

                $type = PollType::from($this->input('type'));
                $options = $this->input('options');

                if ($type === PollType::Vote) {
                    $this->validateVoteOptions($validator, $options);
                } else {
                    $this->validateFeedbackOptions($validator, $options);
                }

                $this->validateMaxChoice($validator, $options);
            },
        ];
    }

    private function validateRecurring(Validator $validator): void
    {
        if (!$this->boolean('is_recurring') || $validator->errors()->has('type')) {
            return;
        }

        if (
            $this->input('type') !== PollType::Feedback->value ||
            $this->input('target_type') !== PollTargetType::WORKPLACE->value
        ) {
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

        if (!$isMultiple) {
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
