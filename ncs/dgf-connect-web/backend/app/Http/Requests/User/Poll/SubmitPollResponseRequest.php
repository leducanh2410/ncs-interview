<?php

namespace App\Http\Requests\User\Poll;

use App\Enums\PollType;
use App\Models\Poll;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class SubmitPollResponseRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'answer' => ['bail', 'required'],
            'device_info' => ['bail', 'nullable', 'array'],
            'device_info.model' => ['bail', 'nullable', 'string', 'max:100'],
            'device_info.os' => ['bail', 'nullable', 'string', 'max:50'],
            'device_info.app_version' => ['bail', 'nullable', 'string', 'max:20'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->has('answer')) {
                    return;
                }

                $poll = $this->route('poll');
                if (!$poll) {
                    $validator->errors()->add('answer', 'Poll not found.');

                    return;
                }

                $answer = $this->input('answer');

                if ($poll->type === PollType::Vote) {
                    $this->validateVoteAnswer($validator, $poll, $answer);
                } else {
                    $this->validateFeedbackAnswer($validator, $poll, $answer);
                }
            },
        ];
    }

    private function validateVoteAnswer(Validator $validator, Poll $poll, $answer): void
    {
        if (!is_array($answer)) {
            $validator->errors()->add('answer', 'Vote answer must be an object with candidate evaluations.');

            return;
        }

        $options = $poll->options;
        $candidateIds = collect($options)->map(fn($id) => (string) $id)->toArray();
        $answerKeys = collect($answer)->keys()->map(fn($key) => (string) $key)->toArray();

        if (count($candidateIds) !== count($answerKeys) || !empty(array_diff($candidateIds, $answerKeys))) {
            $validator->errors()->add('answer', __('poll.missing_candidates'));

            return;
        }

        $validValues = [1, -1, 0, '1', '-1', '0'];
        foreach ($answer as $key => $value) {
            if (!in_array($value, $validValues, true)) {
                $validator->errors()->add("answer.{$key}", __('poll.invalid_vote_value'));
            }
        }

        $yesCount = collect($answer)->filter(fn($v) => $v === 1 || $v === '1')->count();
        if ($yesCount > $poll->max_choice) {
            $validator->errors()->add('answer', __('poll.exceeds_max_choice'));
        }
    }

    private function validateFeedbackAnswer(Validator $validator, Poll $poll, $answer): void
    {
        if (!is_array($answer)) {
            $validator->errors()->add('answer', 'Feedback answer must be an array of selected choice IDs.');

            return;
        }

        if (empty($answer)) {
            $validator->errors()->add('answer', __('poll.empty_answer'));

            return;
        }

        $options = $poll->options;
        $choiceIds = collect($options)->pluck('id')->toArray();

        foreach ($answer as $index => $selectedId) {
            if (!in_array($selectedId, $choiceIds, true)) {
                $validator->errors()->add("answer.{$index}", __('poll.invalid_choice'));
            }
        }

        $selectedCount = count($answer);
        if (count(array_unique($answer)) !== $selectedCount) {
            $validator->errors()->add('answer', __('poll.duplicate_choices'));
        }

        if (!$poll->is_multiple && $selectedCount !== 1) {
            $validator->errors()->add('answer', __('poll.single_choice_required'));
        }

        if ($poll->is_multiple && $selectedCount > $poll->max_choice) {
            $validator->errors()->add('answer', __('poll.exceeds_max_choice'));
        }
    }
}
