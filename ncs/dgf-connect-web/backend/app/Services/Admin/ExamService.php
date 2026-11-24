<?php

namespace App\Services\Admin;

use App\Actions\Admin\Exam\GetExamCompletionStatsAction;
use App\Enums\ExamType;
use App\Models\Exam;
use App\Models\WorkplaceUser;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Validation\ValidationException;

class ExamService
{
    public function __construct(private readonly GetExamCompletionStatsAction $getExamCompletionStatsAction) {}

    public function list(array $conditions): LengthAwarePaginator
    {
        $exams = Exam::getPaginateFiltered(
            $conditions,
            ['workplaces', 'translations'],
            ['start_at' => 'desc', 'id' => 'desc'],
            $conditions['page_size'] ?? null
        );

        $statsByExamId = $this->getExamCompletionStatsAction->forExams(collect($exams->items()));

        $exams->through(function (Exam $exam) use ($statsByExamId) {
            $exam->title = $exam->translationsMap();
            $exam->completion_stats = $statsByExamId[$exam->id];

            return $exam;
        });

        return $exams;
    }

    public function show(Exam $exam): Exam
    {
        $exam->load([
            'documents',
            'workplaces',
            'translations',
            'questions.translations',
            'questions.options.translations',
            // Explicit column list: without it every non-hidden `users`
            // column (email, phone, paper_status, settings, ...) would be
            // serialized per targeted employee. The kana columns are needed
            // because `$appends` contains `name_kana`, whose accessor reads
            // them. `short_name` is needed so User::toArray() can resolve the
            // Sri Lanka shortened display name instead of silently falling
            // back to the full name (missing column reads as unset, not null).
            'targetedUsers' => fn(BelongsToMany $query) => $query->select([
                'users.id',
                'users.first_name',
                'users.last_name',
                'users.first_name_kana',
                'users.last_name_kana',
                'users.staff_no',
                'users.short_name',
            ]),
        ]);

        $this->flagTargetedUserMembership($exam);
        $exam->has_results = $exam->results()->exists();

        return $this->attachTranslations($exam);
    }

    /**
     * `external_form_prep` exams ignore score/retry/time-limit gating and are
     * always mandatory, so these columns are never taken from the admin
     * payload for this type — they're forced to safe values here instead of
     * relying on the client to omit/default them correctly. `min_score` and
     * `time_limit` are NOT NULL columns, so this also guards against a
     * database-level failure if the admin UI ever sends nothing for them.
     *
     * @throws ValidationException when the payload lacks the fields the
     *                             resolved type needs
     */
    public function resolveTypeDependentFields(?string $type, array $data): array
    {
        if ($type !== ExamType::ExternalFormPrep->value) {
            // CreateUpdateExamRequest only makes `min_score`/`time_limit`
            // required for non-external types; for an `external_form_prep`
            // exam they may legitimately be omitted. It decides that from the
            // exam as it was read for validation, so a payload validated
            // against the external branch can reach this one - reading the
            // keys blindly would raise "Undefined array key" and a 500.
            // Reject with a controlled 422 telling the admin to reload.
            if (!isset($data['min_score']) || !isset($data['time_limit'])) {
                throw ValidationException::withMessages([
                    'type' => ['The exam type was changed by another user. Please reload the exam and try again.'],
                ]);
            }

            $isRecurring = (bool) ($data['is_recurring'] ?? false);

            return [
                'is_required' => $isRecurring || ($data['is_required'] ?? false),
                'is_recurring' => $isRecurring,
                'show_result_immediately' => $data['show_result_immediately'] ?? false,
                'retry_limit' => $data['retry_limit'] ?? 0,
                'min_score' => $data['min_score'],
                'time_limit' => $data['time_limit'],
            ];
        }

        return [
            'is_required' => true,
            'is_recurring' => false,
            'show_result_immediately' => false,
            'retry_limit' => 0,
            'min_score' => 0,
            'time_limit' => config('exam.default_external_time_limit'),
        ];
    }

    /**
     * Resolve the per-language `external_url` map for the given type. A
     * payload that omits the `external_url` key entirely (same "doesn't
     * resend every field" case as `type`) keeps the exam's current
     * per-language URLs on update rather than wiping them - falls back to
     * empty only on create, where there's no prior value to preserve. Every
     * type other than `external_form_prep` force-clears the field (returns
     * []) instead of trusting the client to omit it (e.g. stale form state
     * left over from switching the type dropdown).
     *
     * @throws ValidationException when an `external_form_prep` exam would end
     *                             up without an `external_url` for one of its
     *                             selected languages - the request layer
     *                             decides whether the key may be omitted, but
     *                             this is the last line of defense for any
     *                             caller that doesn't go through it (or a
     *                             newly-selected language the exam never had
     *                             a link for).
     */
    public function resolveExternalUrlTranslations(
        ?string $type,
        array $data,
        array $languages,
        ?Exam $exam = null
    ): array {
        if ($type !== ExamType::ExternalFormPrep->value) {
            return [];
        }

        $submitted = array_key_exists('external_url', $data) ? $data['external_url'] : null;
        $existing = $submitted === null ? $exam?->translationsMap('external_url') ?? [] : [];

        $resolved = [];
        foreach ($languages as $lang) {
            $value = $submitted[$lang] ?? ($existing[$lang] ?? null);
            if ($value === null) {
                throw ValidationException::withMessages([
                    "external_url.{$lang}" => ["The external url field is required for {$lang}."],
                ]);
            }
            $resolved[$lang] = $value;
        }

        return $resolved;
    }

    /**
     * Mark each named employee with whether they still belong to one of the
     * exam's workplaces, so the admin form can show a stale selection rather
     * than silently dropping it - an empty selector means "everyone", so a
     * silent drop would widen the audience on the next save.
     *
     * Resolved in one query over the exam's workplace ids, not per employee.
     */
    private function flagTargetedUserMembership(Exam $exam): void
    {
        if ($exam->targetedUsers->isEmpty()) {
            return;
        }

        $workplaceIds = $exam->workplaces->pluck('id');
        $currentMemberIds = $workplaceIds->isEmpty()
            ? collect()
            : WorkplaceUser::query()
                ->whereIn('workplace_id', $workplaceIds)
                ->whereIn('user_id', $exam->targetedUsers->pluck('id'))
                ->pluck('user_id')
                ->unique();

        foreach ($exam->targetedUsers as $user) {
            $user->is_current_member = $currentMemberIds->contains($user->id);
        }
    }

    private function attachTranslations(Exam $exam): Exam
    {
        $exam->title = $exam->translationsMap();
        $exam->external_url = $exam->translationsMap('external_url');
        foreach ($exam->questions as $question) {
            $question->content = $question->translationsMap();
            foreach ($question->options as $option) {
                $option->content = $option->translationsMap();
            }
        }

        return $exam;
    }
}
