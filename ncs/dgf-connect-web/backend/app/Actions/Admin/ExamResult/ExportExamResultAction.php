<?php

namespace App\Actions\Admin\ExamResult;

use App\Actions\ActionResult;
use App\Exports\ExamResultExport;
use App\Models\ExamResult;
use Illuminate\Support\Facades\App;

class ExportExamResultAction
{
    public function __invoke(array $conditions): ActionResult
    {
        $examResults = ExamResult::getFiltered(
            $conditions,
            ['exam.translations', 'workplace:id,name'],
            ['id' => 'desc']
        );
        $locale = App::getLocale();

        return new ActionResult(
            data: [
                'export' => new ExamResultExport($examResults, $locale),
                'name' => 'exam_result.csv',
            ]
        );
    }
}
