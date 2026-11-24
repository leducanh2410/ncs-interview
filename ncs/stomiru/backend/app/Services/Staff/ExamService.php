<?php

namespace App\Services\Staff;

use App\Enums\ExamSessionStatus;
use App\Enums\ScaleLevel;
use App\Enums\StressLevel;
use App\Models\Comment;
use App\Models\ExamAnswer;
use App\Models\ExamResult;
use App\Models\ExamResultRadarScore;
use App\Models\ExamSession;
use App\Models\Question;
use Exception;
use Illuminate\Support\Facades\DB;

class ExamService
{
    /**
     * Save exam answers.
     */
    public function saveAnswer(ExamSession $examSession, array $data): ExamSession|array
    {
        $isSubmitted = $data['is_submitted'] ?? false;
        $answers = $data['answers'] ?? [];

        if ($examSession->status === ExamSessionStatus::Submitted->value) {
            throw new Exception('Exam session has already been submitted.');
        }

        if (! $isSubmitted) {
            $examSession->update([
                'questions_answered' => $answers,
                'status' => ExamSessionStatus::InProgress->value,
                'started_at' => $examSession->started_at ?? now(),
            ]);

            return $examSession;
        }

        // Handle submission
        return DB::transaction(function () use ($examSession, $answers) {
            $savedAnswers = [];
            foreach ($answers as $answer) {
                $savedAnswers[] = ExamAnswer::updateOrCreate(
                    [
                        'exam_session_id' => $examSession->id,
                        'question_id' => $answer['question_id'],
                    ],
                    [
                        'question_option_id' => $answer['option_id'],
                    ]
                );
            }

            $examSession->update([
                'questions_answered' => $answers,
                'status' => ExamSessionStatus::Submitted->value,
                'submitted_at' => now(),
            ]);

            // Auto calculate score if all questions of question_set_id = 1 are answered
            $questionSetIds = Question::where('question_set_id', 1)->pluck('id')->toArray();
            $submittedQuestionIds = array_column($answers, 'question_id');

            if (empty(array_diff($questionSetIds, $submittedQuestionIds))) {
                $this->calculateScore($examSession);
                $examSession->unsetRelations();
            }

            return [
                'exam_session' => $examSession,
                'exam_answers' => $savedAnswers,
            ];
        });
    }

    /**
     * Calculate the score of user from their exam answers.
     */
    public function calculateScore(ExamSession $examSession): array
    {
        if ($examSession->status !== ExamSessionStatus::Submitted->value) {
            throw new Exception('Exam session is not submitted');
        }

        $examSession->load(['examAnswers.question.scale', 'examAnswers.questionOption', 'user.userProfile']);

        $scores = [];
        $rawScores = [];
        $categoryScores = [];

        $answersByScale = $examSession->examAnswers->groupBy(function ($answer) {
            return $answer->question->scale_id;
        });

        foreach ($answersByScale as $scaleId => $answers) {
            // Take the anchor_point of ONE question in that scale
            $firstQuestion = $answers->first()->question;
            $scale = $firstQuestion->scale;
            $anchorPoint = $firstQuestion->anchor_point;

            // Sum the score of each question's option
            $totalOptionScore = $answers->sum(function ($answer) {
                return $answer->questionOption->score;
            });

            $rawScore = $anchorPoint + $totalOptionScore;
            $rawScores[$scaleId] = $rawScore;

            // Calculate evaluation score
            $evaluationScore = $rawScore; // Default to raw score if no rule matches
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

            // Save Radar Score
            ExamResultRadarScore::updateOrCreate(
                [
                    'exam_session_id' => $examSession->id,
                    'scale_id' => $scaleId,
                ],
                [
                    'question_category_id' => $firstQuestion->question_category_id,
                    'score' => $evaluationScore,
                ]
            );

            // Calculate Advice Codes
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
                    $scores['advice_codes'][] = [
                        'scale_id' => $scaleId,
                        'level' => $level->value,
                    ];
                }
            }
        }

        // Save Exam Result
        $categoryAScore = $categoryScores[1] ?? 0;
        $categoryBScore = $categoryScores[2] ?? 0;
        $categoryCScore = $categoryScores[3] ?? 0;

        $stressLevel = StressLevel::Low->value;
        if ($categoryBScore <= 12 || (($categoryAScore + $categoryCScore) <= 26 && $categoryBScore <= 17)) {
            $stressLevel = StressLevel::High->value;
        }

        ExamResult::updateOrCreate(
            ['exam_session_id' => $examSession->id],
            [
                'stress_level' => $stressLevel,
                'job_demand_score' => $rawScores[1] ?? null,
                'job_control_score' => $rawScores[6] ?? null,
                'supervisor_support_score' => $rawScores[16] ?? null,
                'colleague_support_score' => $rawScores[17] ?? null,
                'advice_codes' => $scores['advice_codes'] ?? null,
            ]
        );

        return [
            'scales' => $scores,
            'categories' => $categoryScores,
        ];
    }

    public function getExamResult(ExamSession $examSession): array
    {
        $examResult = ExamResult::where('exam_session_id', $examSession->id)->first();

        if (! $examResult) {
            return [];
        }

        // Get radar scores
        $radarScores = ExamResultRadarScore::where('exam_session_id', $examSession->id)->get();

        // Calculate category scores (same logic as calculateScore)
        $categoryScores = [];
        foreach ($radarScores as $radarScore) {
            $categoryId = $radarScore->question_category_id;
            if ($categoryId) {
                if (! isset($categoryScores[$categoryId])) {
                    $categoryScores[$categoryId] = 0;
                }
                $categoryScores[$categoryId] += $radarScore->score;
            }
        }

        $categoryAScore = $categoryScores[1] ?? 0;
        $categoryBScore = $categoryScores[2] ?? 0;
        $categoryCScore = $categoryScores[3] ?? 0;

        // Get comments based on advice_codes
        $user = $examSession->user;
        $langCode = $user->lang_code ?? 'ja';
        $adviceCodes = $examResult->advice_codes ?? [];
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

        // Get previous exam session's radar scores
        $previousRadarScores = null;
        if ($examSession->submitted_at) {
            $previousExamSession = ExamSession::where('user_id', $examSession->user_id)
                ->where('id', '!=', $examSession->id)
                ->where('submitted_at', '<', $examSession->submitted_at)
                ->orderBy('submitted_at', 'desc')
                ->first();

            if ($previousExamSession) {
                $previousRadarScores = ExamResultRadarScore::where('exam_session_id', $previousExamSession->id)->get();
            }
        }

        return [
            'exam_result' => $examResult,
            'radar_scores' => $radarScores,
            'previous_radar_scores' => $previousRadarScores,
            'category_a_score' => $categoryAScore,
            'category_b_score' => $categoryBScore,
            'category_c_score' => $categoryCScore,
            'comments' => $comments,
        ];
    }
}
