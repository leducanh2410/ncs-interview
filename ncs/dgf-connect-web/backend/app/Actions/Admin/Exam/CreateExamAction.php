<?php

namespace App\Actions\Admin\Exam;

use App\Enums\ExamType;
use App\Models\Exam;
use App\Services\Admin\ExamService;
use Illuminate\Support\Facades\DB;

readonly class CreateExamAction
{
    public function __construct(
        private ExamService $examService,
        private SyncExamTargetedUsersAction $syncTargetedUsers
    ) {}

    public function __invoke(array $data): Exam
    {
        return DB::transaction(function () use ($data) {
            $type = $data['type'] ?? null;
            $languages = $data['languages'];

            $typeDependentFields = $this->examService->resolveTypeDependentFields($type, $data);
            $exam = Exam::query()->create([
                'type' => $type,
                'languages' => $languages,
                ...$typeDependentFields,
                'start_at' => $data['start_at'] ?? null,
                'end_at' => $typeDependentFields['is_recurring'] ? null : $data['end_at'] ?? null,
            ]);
            $exam->replaceTranslations($data['title'], $languages, 'title');
            // Non-external types never have a link, regardless of which
            // languages are selected - prune every external_url row, not
            // just the ones for languages currently out of the selection.
            $exam->replaceTranslations(
                $this->examService->resolveExternalUrlTranslations($type, $data, $languages),
                $type === ExamType::ExternalFormPrep->value ? $languages : [],
                'external_url'
            );

            $exam->documents()->sync($data['documents']);
            $exam->workplaces()->sync($data['workplaces']);
            ($this->syncTargetedUsers)($exam, $data['targeted_users'] ?? []);

            foreach ($data['questions'] as $q) {
                $question = $exam->questions()->create([]);
                $question->replaceTranslations($q['content'], $languages, 'content');
                foreach ($q['options'] as $option) {
                    $createdOption = $question->options()->create([
                        'is_correct' => $option['is_correct'],
                    ]);
                    $createdOption->replaceTranslations($option['content'], $languages, 'content');
                }
            }

            return $this->examService->show($exam);
        });
    }
}
