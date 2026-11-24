<?php

namespace App\Actions\Admin\Exam;

use App\Enums\ExamType;
use App\Models\Exam;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Services\Admin\ExamService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

readonly class UpdateExamAction
{
    public function __construct(
        private ExamService $examService,
        private SyncExamTargetedUsersAction $syncTargetedUsers
    ) {}

    public function __invoke(Exam $exam, array $data): Exam
    {
        return DB::transaction(function () use ($exam, $data) {
            // Defensive lock: if the exam already started, ignore any submitted
            // start_at and keep the persisted value. Validation should catch a
            // mismatch first, but we double-check at this layer.
            $startAt = $data['start_at'] ?? null;
            if ($exam->start_at !== null && $exam->start_at->lessThanOrEqualTo(now())) {
                $startAt = $exam->start_at->format('Y-m-d H:i:s');
            }

            // A payload that omits `type` (legacy API client, or any caller
            // that doesn't resend every field) must not silently downgrade
            // the exam to `assessment` and wipe `external_url` - that would
            // orphan any `pending_external` exam_results already tied to
            // this exam. Only an explicit `type` in the payload changes it.
            $type = array_key_exists('type', $data) ? $data['type'] : $exam->getRawOriginal('type');
            $languages = $data['languages'];

            // Guard mirroring CreateUpdateExamRequest's so the rule also
            // holds for callers that don't go through that request. Both are
            // check-then-act: a start that inserts the exam's first
            // ExamResult between this check and the write below still slips
            // through. Accepted - the worst case is a single result left on
            // an exam whose type moved under it, which the admin override on
            // the exam-result page can settle; serializing the two paths
            // with a row lock would make every start of the exam queue
            // behind admin saves, a far likelier problem.
            $newType = $type ?? ExamType::Assessment->value;
            if ($newType !== $exam->type->value && $exam->results()->exists()) {
                throw ValidationException::withMessages([
                    'type' => ['The exam type cannot be changed because it already has submitted exam results.'],
                ]);
            }

            $typeDependentFields = $this->examService->resolveTypeDependentFields($type, $data);
            $exam->update([
                'type' => $type,
                'languages' => $languages,
                ...$typeDependentFields,
                'start_at' => $startAt,
                'end_at' => $typeDependentFields['is_recurring'] ? null : $data['end_at'] ?? null,
            ]);

            // replaceTranslations() prunes any language no longer in
            // $languages, so unchecking a language on this save removes its
            // stale title/content/link instead of leaving it reachable by a
            // user on that locale.
            $exam->replaceTranslations($data['title'], $languages, 'title');
            // Non-external types never have a link, regardless of which
            // languages are selected - prune every external_url row, not
            // just the ones for languages currently out of the selection.
            $exam->replaceTranslations(
                $this->examService->resolveExternalUrlTranslations($type, $data, $languages, $exam),
                $type === ExamType::ExternalFormPrep->value ? $languages : [],
                'external_url'
            );

            $exam->documents()->sync($data['documents']);
            $exam->workplaces()->sync($data['workplaces']);
            // Like `workplaces` and `documents`, an omitted key clears the
            // set - a partial update that means to keep its named employees
            // has to resend them.
            ($this->syncTargetedUsers)($exam, $data['targeted_users'] ?? []);

            $questions = $exam->questions()->with('options')->get()->keyBy('id');
            $deleteQuestionIds = $questions
                ->keys()
                ->diff(Arr::pluck($data['questions'], 'id'))
                ->toArray();
            $deleteOptionIds = [];

            foreach ($data['questions'] as $q) {
                if ($questions->has($q['id'])) {
                    $question = $questions[$q['id']];
                    $question->replaceTranslations($q['content'], $languages, 'content');
                    $options = $question->options->keyBy('id');
                    $deleteOptionIds = array_merge(
                        $deleteOptionIds,
                        $options
                            ->keys()
                            ->diff(Arr::pluck($q['options'], 'id'))
                            ->toArray()
                    );
                    foreach ($q['options'] as $opt) {
                        if ($options->has($opt['id'])) {
                            $option = $options[$opt['id']];
                            $option->update([
                                'is_correct' => $opt['is_correct'],
                            ]);
                            $option->replaceTranslations($opt['content'], $languages, 'content');
                        } else {
                            unset($opt['id']);
                            $createdOption = $question->options()->create([
                                'is_correct' => $opt['is_correct'],
                            ]);
                            $createdOption->replaceTranslations($opt['content'], $languages, 'content');
                        }
                    }
                } else {
                    $question = $exam->questions()->create([]);
                    $question->replaceTranslations($q['content'], $languages, 'content');
                    foreach ($q['options'] as $option) {
                        $createdOption = $question->options()->create([
                            'is_correct' => $option['is_correct'],
                        ]);
                        $createdOption->replaceTranslations($option['content'], $languages, 'content');
                    }
                }
            }

            // question_options cascade-delete at the DB level when their parent
            // question is destroyed, bypassing Eloquent's `deleting` event, so
            // their exam_translations rows must be cleaned up explicitly here.
            foreach ($deleteQuestionIds as $deleteQuestionId) {
                foreach ($questions[$deleteQuestionId]->options as $option) {
                    $option->translations()->delete();
                }
            }

            Question::destroy($deleteQuestionIds);
            QuestionOption::destroy($deleteOptionIds);

            return $this->examService->show($exam);
        });
    }
}
