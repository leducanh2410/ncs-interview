<?php

namespace App\Imports;

use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\QuestionSet;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithStartRow;
use Throwable;

class QuestionSetImport implements ToCollection, WithMultipleSheets, WithStartRow
{
    protected Command $command;

    public function __construct(Command $command)
    {
        $this->command = $command;
    }

    /**
     * @throws Throwable
     */
    public function collection(Collection $collection): void
    {
        $questionSet = QuestionSet::query()->create();

        DB::transaction(function () use ($collection, $questionSet) {
            foreach ($collection as $row) {
                if (empty($row[0])) {
                    continue;
                }
                $questionData = [
                    'question_id' => $row[0],
                    'content' => $row[1],
                    'category' => $row[2],
                    'scale' => $row[3],
                    'best_option' => $row[4],
                    'topic' => $row[9],
                    'title' => $row[10],
                ];
                $choices = [
                    $row[5], $row[6], $row[7], $row[8],
                ];

                $question = Question::query()->create([
                    'question_set_id' => $questionSet->id,
                    'content' => $questionData['content'],
                    'question_number' => $questionData['question_id'],
                ]);
                $this->command->comment("Insert: {$questionData['question_id']}");

                // Insert into the Options table
                foreach ($choices as $index => $choiceText) {
                    if (! empty($choiceText)) {
                        QuestionOption::query()->create([
                            'question_id' => $question->id,
                            'content' => $choiceText,
                            'sort_order' => $index + 1,
                        ]);
                    }
                }
            }
        });
    }

    public function sheets(): array
    {
        return [
            0 => $this,
        ];
    }

    public function startRow(): int
    {
        return 2; // Skip the header row
    }
}
