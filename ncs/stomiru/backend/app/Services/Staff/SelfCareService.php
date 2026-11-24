<?php

namespace App\Services\Staff;

use App\Enums\ScaleLevel;
use App\Enums\StressLevel;
use App\Models\Comment;
use App\Models\Question;
use App\Models\QuestionCategory;
use App\Models\QuestionOption;
use Illuminate\Support\Facades\Auth;

class SelfCareService
{
    /**
     * Get exam questions for self-care (no stress check period needed).
     * Uses question_set_id = 1 by default.
     */
    public function getExam(): array
    {
        $user = Auth::user();
        $langCode = $user->lang_code ?? 'ja';

        // Get all questions from question_set_id = 1
        $questions = Question::where('question_set_id', 1)
            ->orderBy('sort_order')
            ->with([
                'translations' => fn ($q) => $q->where('lang_code', $langCode),
                'options' => fn ($q) => $q->orderBy('sort_order'),
                'options.translations' => fn ($q) => $q->where('lang_code', $langCode),
            ])
            ->get();

        // Set default values (no previous answers for self-care)
        foreach ($questions as $question) {
            $question->setAttribute('is_answered', false);
            $question->setAttribute('selected_option_id', null);
        }

        $questionCategories = QuestionCategory::query()
            ->with([
                'translations' => fn ($q) => $q->where('lang_code', $langCode),
            ])
            ->get();

        return [
            'questions' => $questions,
            'question_categories' => $questionCategories,
        ];
    }

    /**
     * Calculate exam result in memory without saving to database.
     * Returns the same format as ExamService::getExamResult().
     */
    public function calculateResult(array $answers): array
    {
        $user = Auth::user();
        $langCode = $user->lang_code ?? 'ja';

        // Build a map of question_id => option_id
        $answerMap = [];
        foreach ($answers as $answer) {
            $answerMap[$answer['question_id']] = $answer['option_id'];
        }

        // Load questions with their scales and options
        $questionIds = array_keys($answerMap);
        $questions = Question::whereIn('id', $questionIds)
            ->with(['scale', 'options'])
            ->get()
            ->keyBy('id');

        // Load question options
        $optionIds = array_values($answerMap);
        $options = QuestionOption::whereIn('id', $optionIds)->get()->keyBy('id');

        // Calculate scores per scale (same logic as ExamService::calculateScore)
        $scores = [];
        $categoryScores = [];
        $radarScores = [];

        // Group answers by scale
        $answersByScale = [];
        foreach ($answerMap as $questionId => $optionId) {
            $question = $questions->get($questionId);
            if (! $question) {
                continue;
            }
            $scaleId = $question->scale_id;
            if (! isset($answersByScale[$scaleId])) {
                $answersByScale[$scaleId] = [];
            }
            $answersByScale[$scaleId][] = [
                'question' => $question,
                'option' => $options->get($optionId),
            ];
        }

        $adviceCodes = [];

        foreach ($answersByScale as $scaleId => $scaleAnswers) {
            $firstQuestion = $scaleAnswers[0]['question'];
            $scale = $firstQuestion->scale;
            $anchorPoint = $firstQuestion->anchor_point;

            // Sum option scores
            $totalOptionScore = 0;
            foreach ($scaleAnswers as $answer) {
                if ($answer['option']) {
                    $totalOptionScore += $answer['option']->score;
                }
            }

            $rawScore = $anchorPoint + $totalOptionScore;

            // Calculate evaluation score
            $evaluationScore = $rawScore;
            if ($scale && $scale->evaluation_score) {
                $rules = is_string($scale->evaluation_score) ? json_decode($scale->evaluation_score, true) : $scale->evaluation_score;
                if (is_array($rules)) {
                    foreach ($rules as $rule) {
                        if ($rawScore >= $rule['min'] && $rawScore <= $rule['max']) {
                            $evaluationScore = $rule['score'];
                            break;
                        }
                    }
                }
            }

            $scores[$scaleId] = $evaluationScore;

            if ($firstQuestion->question_category_id) {
                if (! isset($categoryScores[$firstQuestion->question_category_id])) {
                    $categoryScores[$firstQuestion->question_category_id] = 0;
                }
                $categoryScores[$firstQuestion->question_category_id] += $evaluationScore;
            }

            // Build in-memory radar score
            $radarScores[] = [
                'id' => 0, // No DB id
                'exam_session_id' => 0,
                'scale_id' => $scaleId,
                'question_category_id' => $firstQuestion->question_category_id,
                'score' => $evaluationScore,
                'created_at' => now()->toISOString(),
                'updated_at' => now()->toISOString(),
            ];

            // Calculate Advice Codes (same logic)
            if (! in_array($scaleId, [4, 15])) {
                $level = null;
                if (in_array($evaluationScore, [1, 2])) {
                    $level = ScaleLevel::High;
                } elseif ($evaluationScore === 3) {
                    $level = ScaleLevel::Medium;
                } elseif (in_array($evaluationScore, [4, 5])) {
                    $level = ScaleLevel::Low;
                }

                if ($level) {
                    $adviceCodes[] = [
                        'scale_id' => $scaleId,
                        'level' => $level->value,
                    ];
                }
            }
        }

        // Calculate category scores
        $categoryAScore = $categoryScores[1] ?? 0;
        $categoryBScore = $categoryScores[2] ?? 0;
        $categoryCScore = $categoryScores[3] ?? 0;

        // Determine stress level
        $stressLevel = StressLevel::Low->value;
        if ($categoryBScore <= 12 || (($categoryAScore + $categoryCScore) <= 26 && $categoryBScore <= 17)) {
            $stressLevel = StressLevel::High->value;
        }

        // Build in-memory exam result (same format as ExamResult model)
        $examResult = [
            'id' => 0,
            'exam_session_id' => 0,
            'stress_level' => $stressLevel,
            'job_demand_score' => $scores[1] ?? null,
            'job_control_score' => $scores[6] ?? null,
            'supervisor_support_score' => $scores[16] ?? null,
            'colleague_support_score' => $scores[17] ?? null,
            'advice_codes' => $adviceCodes,
            'created_at' => now()->toISOString(),
            'updated_at' => now()->toISOString(),
        ];

        // Get comments based on advice_codes
        $comments = [];
        if (! empty($adviceCodes)) {
            $conditions = collect($adviceCodes)
                ->filter(fn ($ac) => isset($ac['scale_id'], $ac['level']));

            if ($conditions->isNotEmpty()) {
                $commentsQuery = Comment::with([
                    'scale',
                    'translations' => fn ($q) => $q->where('lang_code', $langCode),
                ]);

                $commentsQuery->where(function ($query) use ($conditions) {
                    foreach ($conditions as $cond) {
                        $query->orWhere(function ($q) use ($cond) {
                            $q->where('scale_id', $cond['scale_id'])
                                ->where('level', $cond['level']);
                        });
                    }
                });

                $fetchedComments = $commentsQuery->get()
                    ->keyBy(fn ($c) => $c->scale_id.'-'.$c->level);

                foreach ($adviceCodes as $adviceCode) {
                    $scaleId = $adviceCode['scale_id'] ?? null;
                    $level = $adviceCode['level'] ?? null;

                    if (! $scaleId || ! $level) {
                        continue;
                    }

                    $comment = $fetchedComments->get($scaleId.'-'.$level);

                    if ($comment) {
                        $translation = $comment->translations->first();
                        $comments[] = [
                            'scale_id' => $scaleId,
                            'scale_name' => $comment->scale->name ?? null,
                            'level' => $level,
                            'content' => $translation->content ?? null,
                        ];
                    }
                }
            }
        }

        return [
            'exam_result' => $examResult,
            'radar_scores' => $radarScores,
            'previous_radar_scores' => null,
            'category_a_score' => $categoryAScore,
            'category_b_score' => $categoryBScore,
            'category_c_score' => $categoryCScore,
            'comments' => $comments,
        ];
    }
}
