<?php

use App\Models\QuestionOption;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $options = QuestionOption::query()->where('is_correct', true)->get()->groupBy('question_id');

        foreach ($options as $questionId => $questionOptions) {
            if ($questionOptions->count() === 1) {
                continue;
            }
            $ids = $questionOptions->pluck('id');
            $ids->shift();
            QuestionOption::query()
                ->whereIn('id', $ids)
                ->update(['is_correct' => false]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
