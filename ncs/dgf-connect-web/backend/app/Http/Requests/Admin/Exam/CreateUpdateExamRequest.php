<?php

namespace App\Http\Requests\Admin\Exam;

use App\Enums\ExamType;
use App\Enums\Locale;
use App\Models\Exam;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\User;
use App\Models\WorkplaceUser;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Validator;

class CreateUpdateExamRequest extends FormRequest
{
    public function rules(): array
    {
        $isExternalFormPrep = $this->effectiveType() === ExamType::ExternalFormPrep->value;
        $languages = $this->selectedLanguages();

        // `required` would also reject an update payload that omits the key
        // entirely, but the update Action preserves the current URLs in that
        // case - so absence is only acceptable when the persisted exam is
        // already `external_form_prep`. On create, or when switching an
        // `assessment` exam to this type, there is nothing to preserve and
        // the key stays required for every selected language - otherwise the
        // update would pass and leave an external exam whose users get
        // `url: null`. The Action still throws if omission resolves to a
        // missing URL for any selected language (e.g. a language just added
        // to the selection that the exam never had a link for).
        $exam = $this->route('exam');
        $externalUrlMayBeOmitted =
            $exam instanceof Exam &&
            !$this->has('external_url') &&
            $exam->getRawOriginal('type') === ExamType::ExternalFormPrep->value;

        return [
            // Only the selected languages are required for title/content
            // below - unselected ones stay optional so the payload doesn't
            // have to omit their keys, but their values are ignored at
            // persistence time (see CreateExamAction/UpdateExamAction).
            'languages' => [
                'required',
                'array',
                'min:1',
                function (string $attribute, mixed $value, Closure $fail) {
                    if (is_array($value) && !in_array(Locale::English->value, $value, true)) {
                        $fail('The languages field must include English.');
                    }
                },
            ],
            'languages.*' => [new Enum(Locale::class)],
            ...$this->translatableRules('title', 3, 255, $languages),
            // Nullable, not required: omitting it (existing API consumers,
            // legacy rows) is treated as `assessment` end-to-end (Exam::type
            // accessor), so the request contract doesn't force every caller
            // to start sending it.
            'type' => ['nullable', new Enum(ExamType::class)],
            'is_required' => ['boolean'],
            'is_recurring' => ['sometimes', 'boolean'],
            'show_result_immediately' => ['boolean'],
            'retry_limit' => $isExternalFormPrep ? ['nullable', 'integer', 'min:0'] : ['integer', 'min:0'],
            'min_score' => $isExternalFormPrep
                ? ['nullable', 'integer', 'between:0,100']
                : ['required', 'integer', 'between:0,100'],
            'time_limit' => $isExternalFormPrep ? ['nullable', 'integer', 'min:1'] : ['required', 'integer', 'min:1'],
            'external_url' => ['nullable', 'array'],
            ...$this->translatableUrlRules(
                'external_url',
                $isExternalFormPrep && !$externalUrlMayBeOmitted,
                $languages
            ),
            'start_at' => ['nullable', 'date_format:Y-m-d H:i:s', 'before:end_at'],
            'end_at' => ['nullable', 'date_format:Y-m-d H:i:s', 'after:start_at'],
            'workplaces' => ['array'],
            'workplaces.*' => ['integer', 'exists:workplaces,id'],
            // Absent or empty means "every member of the selected
            // workplaces" - the form blocks "named employees with nobody
            // named" client-side, because the two are indistinguishable here.
            'targeted_users' => ['array'],
            'targeted_users.*' => ['integer', 'exists:users,id'],
            'documents' => ['array'],
            'documents.*' => ['integer', 'exists:documents,id'],
            'questions' => ['array'],
            'questions.*.id' => ['nullable'],
            ...$this->translatableRules('questions.*.content', 1, 2000, $languages),
            'questions.*.options' => ['required', 'array'],
            'questions.*.options.*.id' => ['nullable'],
            ...$this->translatableRules('questions.*.options.*.content', 1, 1000, $languages),
            'questions.*.options.*.is_correct' => ['required', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                $this->validateQuestions($validator);
                if ($this->effectiveType() !== ExamType::ExternalFormPrep->value) {
                    $this->validateMinScore($validator);
                }
                $this->validateLockedStartAt($validator);
                $this->validateTypeChangeWithResults($validator);
                $this->validateQuestionStructureWithResults($validator);
                $this->validateTargetedUsers($validator);
                $this->validateRecurring($validator);
            },
        ];
    }

    /**
     * Named employees are only expressible in terms of workplace membership,
     * so every submitted id must currently belong to one of the submitted
     * workplaces. The one exemption covers links that were *already* stale
     * before this request: an employee who left the workplace after being
     * named still comes back from the show endpoint as a chip, and rejecting
     * it would make the exam unsaveable for any unrelated edit. Such a link
     * grants nothing - the audience rule re-derives membership at query time
     * - so keeping it is inert.
     *
     * Offenders are named rather than silently dropped, so the admin never
     * ends up with a narrower audience than the one they submitted.
     */
    private function validateTargetedUsers(Validator $validator): void
    {
        $targetedUserIds = array_values(array_unique(array_map('intval', (array) $this->input('targeted_users', []))));
        if (empty($targetedUserIds)) {
            return;
        }

        $workplaceIds = array_map('intval', (array) $this->input('workplaces', []));
        if (empty($workplaceIds)) {
            $validator
                ->errors()
                ->add('targeted_users', 'At least one workplace must be selected to target specific employees.');

            return;
        }

        $idsToCheck = array_diff($targetedUserIds, $this->alreadyStaleTargetedUserIds());
        if (empty($idsToCheck)) {
            return;
        }

        $memberIds = WorkplaceUser::query()
            ->whereIn('user_id', $idsToCheck)
            ->whereIn('workplace_id', $workplaceIds)
            ->pluck('user_id')
            ->unique()
            ->all();

        $invalidIds = array_diff($idsToCheck, $memberIds);
        if (empty($invalidIds)) {
            return;
        }

        $names = User::query()
            ->whereIn('id', $invalidIds)
            ->get(['id', 'first_name', 'last_name', 'short_name'])
            ->mapWithKeys(fn(User $user) => [$user->id => $user->short_name]);

        // One message listing every offender rather than one message each:
        // FormHelper.setFormError() surfaces only errors[field][0], so
        // separate entries would reveal the offenders one save at a time.
        $labels = array_map(fn(int $invalidId) => $names->get($invalidId, "#{$invalidId}"), $invalidIds);
        $sentence =
            count($labels) > 1
                ? ' are not members of any selected workplace and cannot be targeted.'
                : ' is not a member of any selected workplace and cannot be targeted.';
        $validator->errors()->add('targeted_users', implode(', ', $labels) . $sentence);
    }

    /**
     * The persisted links this request inherited that were already unreachable
     * before it: named on the exam, but no longer a member of any workplace
     * the exam is *currently saved with*. Only those may skip the membership
     * check.
     *
     * Measuring staleness against the saved workplaces rather than the linked
     * set as a whole is the point: an employee who is still a valid member
     * today, but whom this very request pushes out of scope by replacing the
     * workplaces (saved W1/U1 -> submit W2 while keeping the U1 chip), must
     * still be rejected. Exempting them would save an exam that reaches
     * nobody - the audience rule drops U1 for failing membership and drops
     * every W2 member for not being named - with no warning anywhere, since
     * the form also hides its stale marker as soon as the workplace selection
     * differs from the saved one.
     *
     * An exam with no saved workplaces at all (deleting the last workplace
     * cascades exam_workplace but leaves exam_user behind) grants no
     * exemption either. There is no saved scope for a link to have gone stale
     * against, and the caller has already rejected the only request that
     * keeps the scope empty - so this is always an admin establishing a new
     * scope, exactly when the chips need checking against it.
     *
     * @return int[]
     */
    private function alreadyStaleTargetedUserIds(): array
    {
        $exam = $this->route('exam');
        if (!$exam instanceof Exam) {
            return [];
        }

        $linkedIds = $exam->targetedUsers()->pluck('users.id')->all();
        if (empty($linkedIds)) {
            return [];
        }

        $savedWorkplaceIds = $exam->workplaces()->pluck('workplaces.id')->all();
        if (empty($savedWorkplaceIds)) {
            return [];
        }

        $stillMemberIds = WorkplaceUser::query()
            ->whereIn('user_id', $linkedIds)
            ->whereIn('workplace_id', $savedWorkplaceIds)
            ->pluck('user_id')
            ->all();

        return array_values(array_diff($linkedIds, $stillMemberIds));
    }

    private function validateRecurring(Validator $validator): void
    {
        if (!$this->boolean('is_recurring')) {
            return;
        }

        if ($this->effectiveType() === ExamType::ExternalFormPrep->value) {
            $validator->errors()->add('is_recurring', 'An external form exam cannot be recurring.');
        }

        if (empty($this->input('workplaces', []))) {
            $validator->errors()->add('is_recurring', 'A recurring exam must have at least one workplace.');
        }
    }

    /**
     * The type the exam will effectively have after this request, mirroring
     * ExamService: a submitted `type` wins, but a payload that omits the key
     * keeps the exam's current type (ExamService::update() reads
     * `getRawOriginal('type')` in that case). Type-dependent rules must be
     * derived from this same value - deriving them from the raw input alone
     * would validate an `external_form_prep` exam updated without `type` as
     * an `assessment` one (re-requiring min_score/time_limit that the
     * service overrides anyway, and downgrading external_url to nullable).
     * Returns null on create or for legacy rows without a type, which is
     * treated as `assessment` everywhere.
     */
    private function effectiveType(): ?string
    {
        if ($this->has('type')) {
            return $this->input('type');
        }

        $exam = $this->route('exam');

        return $exam instanceof Exam ? $exam->getRawOriginal('type') : null;
    }

    /**
     * Only languages in $languages (the exam's selected languages) are
     * required; the rest stay optional so an unselected language's key can
     * be present (ignored at persistence time) or absent without a 422.
     */
    private function translatableRules(string $field, int $min, int $max, array $languages): array
    {
        $rules = [$field => ['required', 'array']];
        foreach (Locale::values() as $lang) {
            $rules["{$field}.{$lang}"] = in_array($lang, $languages, true)
                ? ['required', 'string', "min:{$min}", "max:{$max}"]
                : ['nullable', 'string', "max:{$max}"];
        }

        return $rules;
    }

    /**
     * Same shape as translatableRules() but for URL fields (external_url),
     * only required per language when $required is true (type is
     * external_form_prep and the value isn't allowed to be omitted).
     *
     * The `url` format check only applies to selected languages. An
     * unselected language's tab is hidden in the form (DLanguageTabsInput
     * only renders selected tabs), so a stale draft value left over from
     * before it was deselected (e.g. a half-typed URL) must not be format
     * -validated - the admin would have no field to fix a 422 on.
     */
    private function translatableUrlRules(string $field, bool $required, array $languages): array
    {
        $rules = [];
        foreach (Locale::values() as $lang) {
            if (!in_array($lang, $languages, true)) {
                $rules["{$field}.{$lang}"] = ['nullable'];

                continue;
            }
            $rules["{$field}.{$lang}"] = $required ? ['required', 'url'] : ['nullable', 'url'];
        }

        return $rules;
    }

    /**
     * Defaults to English-only when `languages` is missing/invalid, so a
     * malformed payload still gets sane required-field rules instead of
     * requiring nothing at all.
     */
    private function selectedLanguages(): array
    {
        $languages = $this->input('languages');

        return is_array($languages) ? $languages : [Locale::English->value];
    }

    private function validateQuestions(Validator $validator): void
    {
        $questions = $this->get('questions', []);
        foreach ($questions as $index => $question) {
            $options = $question['options'] ?? [];
            if (count($options) < 2) {
                $validator->errors()->add("questions.{$index}.content", 'At least two options are required.');

                continue;
            }
            $correctCount = count(
                array_filter($options, function ($option) {
                    return isset($option['is_correct']) && $option['is_correct'] === true;
                })
            );
            if ($correctCount !== 1) {
                $validator
                    ->errors()
                    ->add("questions.{$index}.content", 'Exactly one option must be marked as correct.');
            }
        }
    }

    /**
     * Each question is worth one point, so the passing score cannot exceed the
     * total number of questions in the exam.
     */
    private function validateMinScore(Validator $validator): void
    {
        $minScore = $this->input('min_score');
        $questionCount = count($this->get('questions', []));
        if (is_numeric($minScore) && (int) $minScore > $questionCount) {
            $validator
                ->errors()
                ->add('min_score', "The passing score cannot exceed the number of questions ({$questionCount}).");
        }
    }

    /**
     * When the persisted exam already has a start_at that has passed, the
     * start_at field is locked. Any update payload must either omit the field
     * or submit the exact same value; otherwise we reject the request.
     */
    private function validateLockedStartAt(Validator $validator): void
    {
        /** @var Exam|null $exam */
        $exam = $this->route('exam');
        if (!$exam instanceof Exam) {
            return;
        }
        if ($exam->start_at === null || $exam->start_at->greaterThan(now())) {
            return;
        }

        if (!$this->has('start_at')) {
            return;
        }

        $submitted = $this->input('start_at');
        $existing = $exam->start_at->format('Y-m-d H:i:s');
        if ($submitted !== $existing) {
            $validator->errors()->add('start_at', 'The start date is locked because the exam has already started.');
        }
    }

    /**
     * Switching `type` on an exam that already has exam_results is unsafe
     * regardless of direction: assessment <-> external_form_prep changes
     * what a persisted result's status/score even mean (e.g. a
     * `pending_external` row becomes unreachable once the exam is no longer
     * `external_form_prep` - see GetExamResultAction/ExamResultCalculation).
     * Once results exist, the type is locked; a new exam must be created to
     * change it.
     */
    private function validateTypeChangeWithResults(Validator $validator): void
    {
        /** @var Exam|null $exam */
        $exam = $this->route('exam');
        if (!$exam instanceof Exam) {
            return;
        }
        if (!$this->has('type')) {
            return;
        }
        // The submitted `type` can be `null` (explicitly, or omitted from a
        // legacy payload) while `$exam->type` is the accessor that always
        // maps a `null`/legacy raw value to `ExamType::Assessment` -
        // normalize before comparing, otherwise a save that doesn't touch
        // type at all (e.g. `"type": null` on an already-assessment exam)
        // reads as a type change.
        $newType = $this->input('type') ?? ExamType::Assessment->value;
        if ($newType === $exam->type->value) {
            return;
        }
        if ($exam->results()->exists()) {
            $validator
                ->errors()
                ->add('type', 'The exam type cannot be changed because it already has submitted exam results.');
        }
    }

    private function validateQuestionStructureWithResults(Validator $validator): void
    {
        $exam = $this->route('exam');
        if (
            !$exam instanceof Exam ||
            $this->effectiveType() === ExamType::ExternalFormPrep->value ||
            !$exam->results()->exists()
        ) {
            return;
        }

        $existing = $exam
            ->questions()
            ->with('options')
            ->get()
            ->mapWithKeys(
                fn(Question $question) => [
                    "q{$question->id}" => $question->options
                        ->mapWithKeys(fn(QuestionOption $option) => ["o{$option->id}" => $option->is_correct])
                        ->sortKeys()
                        ->all(),
                ]
            )
            ->sortKeys()
            ->all();

        $submitted = [];
        foreach ((array) $this->input('questions', []) as $question) {
            $options = [];
            foreach ((array) ($question['options'] ?? []) as $option) {
                $options['o' . ($option['id'] ?? '')] = (bool) ($option['is_correct'] ?? false);
            }
            ksort($options);
            $submitted['q' . ($question['id'] ?? '')] = $options;
        }
        ksort($submitted);

        if ($existing !== $submitted) {
            $validator
                ->errors()
                ->add(
                    'questions',
                    'Questions, answers and correct answers cannot be added, removed or changed because the exam already has results.'
                );
        }
    }
}
