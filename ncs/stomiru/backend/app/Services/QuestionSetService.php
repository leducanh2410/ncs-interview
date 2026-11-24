<?php

namespace App\Services;

use App\Models\Question;
use App\Models\QuestionCategory;

class QuestionSetService
{
    public function getQuestionSet(int $questionSetId, string $langCode = 'ja'): array
    {
        $cacheKey = "question_set:{$questionSetId}:lang:{$langCode}";

        return cache()->remember($cacheKey, now()->addHours(24), function () use ($questionSetId, $langCode) {
            $questions = Question::where('question_set_id', $questionSetId)
                ->orderBy('sort_order')
                ->with([
                    'translations' => fn ($q) => $q->where('lang_code', $langCode),
                    'options' => fn ($q) => $q->orderBy('sort_order'),
                    'options.translations' => fn ($q) => $q->where('lang_code', $langCode),
                ])
                ->get()
                ->map(function ($question) {
                    $qTranslation = $question->translations->first();

                    return [
                        'id' => $question->id,
                        'question_category_id' => $question->question_category_id,
                        'content' => $qTranslation?->content,

                        'options' => $question->options->map(function ($option) {
                            $oTranslation = $option->translations->first();

                            return [
                                'id' => $option->id,
                                'content' => $oTranslation?->content,
                            ];
                        })->values(), // reset index
                    ];
                })
                ->values(); // reset index

            $questionCategories = QuestionCategory::query()
                ->with([
                    'translations' => fn ($q) => $q->where('lang_code', $langCode),
                ])
                ->get()
                ->map(function ($category) {
                    $translation = $category->translations->first();

                    return [
                        'id' => $category->id,
                        'name' => $category->name,
                        'topic' => $translation?->topic,
                        'title' => $translation?->title,
                    ];
                });

            return [
                'questions' => $questions,
                'question_categories' => $questionCategories,
            ];
        });
    }
}
