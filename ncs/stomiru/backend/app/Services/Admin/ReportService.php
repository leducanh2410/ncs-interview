<?php

namespace App\Services\Admin;

use App\Enums\EmployeeStatus;
use App\Enums\ExamSessionStatus;
use App\Enums\RoleName;
use App\Enums\ScaleLevel;
use App\Models\AdminReport;
use App\Models\AdminReportComment;
use App\Models\AdminReportScaleDistribution;
use App\Models\ExamResult;
use App\Models\ExamResultRadarScore;
use App\Models\ExamSession;
use App\Models\StressCheckPeriod;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use App\Enums\StressCheckPeriodStatus;

class ReportService
{
    /**
     * Calculate the participation rate and high stress ratio for a given company and stress check period.
     */
    public function calculateRates(int $companyId, int $stressCheckPeriodId): array
    {
        $totalParticipants = User::where('company_id', $companyId)
            ->whereHas('roles', function ($q) {
                $q->whereIn('name', [
                    RoleName::Staff->value,
                    RoleName::StaffManager->value,
                ]);
            })
            ->where(function ($query) use ($stressCheckPeriodId) {
                $query->whereNotIn('employee_status', [
                    EmployeeStatus::Retired->value,
                    EmployeeStatus::OnLeave->value,
                ])->orWhereHas('examSessions', function ($q) use ($stressCheckPeriodId) {
                    $q->where('stress_check_period_id', $stressCheckPeriodId)
                        ->where('status', ExamSessionStatus::Submitted->value);
                });
            })
            ->count();

        if ($totalParticipants === 0) {
            return [
                'total_employees' => 0,
                'participated_employees' => 0,
                'not_completed_employees' => 0,
                'participation_rate' => 0.0,
                'high_stress_ratio' => 0.0,
            ];
        }

        $completedParticipants = ExamSession::where('stress_check_period_id', $stressCheckPeriodId)
            ->where('status', ExamSessionStatus::Submitted->value)
            ->whereHas('user', function ($query) use ($companyId) {
                $query->where('company_id', $companyId);
            })
            ->count();

        if ($completedParticipants === 0) {
            return [
                'total_employees' => $totalParticipants,
                'participated_employees' => 0,
                'not_completed_employees' => $totalParticipants,
                'participation_rate' => 0.0,
                'high_stress_ratio' => 0.0,
            ];
        }

        $rate = ($completedParticipants / $totalParticipants) * 100;

        $highStressEmployees = ExamSession::where('stress_check_period_id', $stressCheckPeriodId)
            ->whereHas('user', function ($query) use ($companyId) {
                $query->where('company_id', $companyId);
            })
            ->whereHas('examResult', function ($query) {
                $query->where('stress_level', ScaleLevel::High->value);
            })
            ->count();

        $highStressRatio = ($highStressEmployees / $completedParticipants) * 100;

        return [
            'total_employees' => $totalParticipants,
            'participated_employees' => $completedParticipants,
            'not_completed_employees' => $totalParticipants - $completedParticipants,
            'participation_rate' => round($rate, 1),
            'high_stress_ratio' => round($highStressRatio, 1),
        ];
    }

    /**
     * Calculate average scores for workload, control, supervisor support, and coworker support.
     */
    public function calculateAverageScores(int $companyId, int $stressCheckPeriodId): array
    {
        $averages = ExamResult::whereHas('examSession', function ($query) use ($companyId, $stressCheckPeriodId) {
            $query->where('stress_check_period_id', $stressCheckPeriodId)
                ->whereHas('user', function ($userQuery) use ($companyId) {
                    $userQuery->where('company_id', $companyId);
                });
        })
            ->selectRaw('
            AVG(job_demand_score) as workload_score,
            AVG(job_control_score) as control_score,
            AVG(supervisor_support_score) as supervisor_support_score,
            AVG(colleague_support_score) as coworker_support_score
        ')
            ->first();

        $currentPeriod = StressCheckPeriod::find($stressCheckPeriodId);
        $previousPeriod = $currentPeriod ? StressCheckPeriod::where('company_id', $companyId)
            ->where('end_date', '<', $currentPeriod->end_date)
            ->orderBy('end_date', 'desc')
            ->first() : null;

        $previousAverages = null;
        if ($previousPeriod) {
            $previousAverages = ExamResult::whereHas('examSession', function ($query) use ($companyId, $previousPeriod) {
                $query->where('stress_check_period_id', $previousPeriod->id)
                    ->whereHas('user', function ($userQuery) use ($companyId) {
                        $userQuery->where('company_id', $companyId);
                    });
            })
                ->selectRaw('
                AVG(job_demand_score) as workload_score,
                AVG(job_control_score) as control_score,
                AVG(supervisor_support_score) as supervisor_support_score,
                AVG(colleague_support_score) as coworker_support_score
            ')
                ->first();
        }

        $calcRiskWorkload = function ($workload, $control) {
            if ($workload === null || $control === null) {
                return null;
            }

            return (int) floor(100 * exp(((float) $workload - 8.7) * 0.076 + ((float) $control - 7.9) * -0.089));
        };

        $calcRiskSupport = function ($supervisorSupport, $coworkerSupport) {
            if ($supervisorSupport === null || $coworkerSupport === null) {
                return null;
            }

            return (int) floor(100 * exp(((float) $supervisorSupport - 7.5) * -0.097 + ((float) $coworkerSupport - 8.1) * -0.097));
        };

        return [
            'workload_score' => round((float) ($averages->workload_score ?? 0), 1),
            'control_score' => round((float) ($averages->control_score ?? 0), 1),
            'supervisor_support_score' => round((float) ($averages->supervisor_support_score ?? 0), 1),
            'coworker_support_score' => round((float) ($averages->coworker_support_score ?? 0), 1),
            'calculated_risk_workload' => $calcRiskWorkload($averages->workload_score ?? null, $averages->control_score ?? null),
            'calculated_risk_support' => $calcRiskSupport($averages->supervisor_support_score ?? null, $averages->coworker_support_score ?? null),
            'previous_workload_score' => $previousPeriod ? round((float) ($previousAverages->workload_score ?? 0), 1) : null,
            'previous_control_score' => $previousPeriod ? round((float) ($previousAverages->control_score ?? 0), 1) : null,
            'previous_supervisor_support_score' => $previousPeriod ? round((float) ($previousAverages->supervisor_support_score ?? 0), 1) : null,
            'previous_coworker_support_score' => $previousPeriod ? round((float) ($previousAverages->coworker_support_score ?? 0), 1) : null,
            'previous_calculated_risk_workload' => $previousPeriod ? $calcRiskWorkload($previousAverages->workload_score ?? null, $previousAverages->control_score ?? null) : null,
            'previous_calculated_risk_support' => $previousPeriod ? $calcRiskSupport($previousAverages->supervisor_support_score ?? null, $previousAverages->coworker_support_score ?? null) : null,
        ];
    }

    /**
     * Calculate the average reaction to stress score for a given company and stress check period.
     * calculation formula: calculate the total score of question that has question_category_id=2,
     * then get the average by getting the sum of the total score of every user in the company
     * that participating that stress check, then divide by the number of participants.
     */
    public function calculateAverageReactionToStressScore(int $companyId, int $stressCheckPeriodId): ?float
    {
        $participantsCount = ExamSession::where('stress_check_period_id', $stressCheckPeriodId)
            ->where('status', ExamSessionStatus::Submitted->value)
            ->whereHas('user', function ($query) use ($companyId) {
                $query->where('company_id', $companyId);
            })
            ->count();

        if ($participantsCount === 0) {
            return null;
        }

        $totalScore = ExamResultRadarScore::where('question_category_id', 2)
            ->whereHas('examSession', function ($query) use ($companyId, $stressCheckPeriodId) {
                $query->where('stress_check_period_id', $stressCheckPeriodId)
                    ->whereHas('user', function ($userQuery) use ($companyId) {
                        $userQuery->where('company_id', $companyId);
                    });
            })
            ->sum('score');

        return round($totalScore / $participantsCount, 1);
    }

    /**
     * Calculate the distribution of the score in each scale.
     * 1: bad, 2: slightly bad, 3: average, 4: slightly good, 5: good.
     */
    public function calculateScaleDistributions(int $companyId, int $stressCheckPeriodId): array
    {
        $participantsCount = ExamSession::where('stress_check_period_id', $stressCheckPeriodId)
            ->where('status', ExamSessionStatus::Submitted->value)
            ->whereHas('user', function ($query) use ($companyId) {
                $query->where('company_id', $companyId);
            })
            ->count();

        if ($participantsCount === 0) {
            return [];
        }

        $radarScores = ExamResultRadarScore::whereHas('examSession', function ($query) use ($companyId, $stressCheckPeriodId) {
            $query->where('stress_check_period_id', $stressCheckPeriodId)
                ->where('status', ExamSessionStatus::Submitted->value)
                ->whereHas('user', function ($userQuery) use ($companyId) {
                    $userQuery->where('company_id', $companyId);
                });
        })->get();

        $distributions = [];
        $groupedScores = $radarScores->groupBy('scale_id');

        foreach ($groupedScores as $scaleId => $scores) {
            $counts = [
                1 => 0,
                2 => 0,
                3 => 0,
                4 => 0,
                5 => 0,
            ];

            foreach ($scores as $item) {
                $scoreValue = (int) $item->score;
                if (isset($counts[$scoreValue])) {
                    $counts[$scoreValue]++;
                }
            }

            $distributions[] = [
                'scale_id' => $scaleId,
                'bad_percentage' => round(($counts[1] / $participantsCount) * 100, 1),
                'slightly_bad_percentage' => round(($counts[2] / $participantsCount) * 100, 1),
                'average_percentage' => round(($counts[3] / $participantsCount) * 100, 1),
                'slightly_good_percentage' => round(($counts[4] / $participantsCount) * 100, 1),
                'good_percentage' => round(($counts[5] / $participantsCount) * 100, 1),
            ];
        }

        return $distributions;
    }

    public function getAdminReport(int $stressCheckPeriodId, int $companyId)
    {
        $stressCheckPeriod = StressCheckPeriod::find($stressCheckPeriodId);

        if (! $stressCheckPeriod || $stressCheckPeriod->status !== StressCheckPeriodStatus::Finished->value) {
            return null;
        }

        $previousPeriod = StressCheckPeriod::where('company_id', $companyId)
            ->where('end_date', '<', $stressCheckPeriod->end_date)
            ->orderBy('end_date', 'desc')
            ->first();

        /** @var User $user */
        $user = Auth::user();

        $rates = $this->calculateRates($companyId, $stressCheckPeriodId);
        $previousRates = $previousPeriod ? $this->calculateRates($companyId, $previousPeriod->id) : null;
        $averageScores = $this->calculateAverageScores($companyId, $stressCheckPeriodId);
        $averageReactionToStressScore = $this->calculateAverageReactionToStressScore($companyId, $stressCheckPeriodId);
        $scaleDistributions = $this->calculateScaleDistributions($companyId, $stressCheckPeriodId);

        $adminReport = AdminReport::updateOrCreate(
            [
                'company_id' => $companyId,
                'stress_check_period_id' => $stressCheckPeriodId,
            ],
            [
                'participation_rate' => $rates['participation_rate'],
                'high_stress_ratio' => $rates['high_stress_ratio'],
                'workload_score' => $averageScores['workload_score'],
                'control_score' => $averageScores['control_score'],
                'supervisor_support_score' => $averageScores['supervisor_support_score'],
                'coworker_support_score' => $averageScores['coworker_support_score'],
                'calculated_risk_workload' => $averageScores['calculated_risk_workload'],
                'calculated_risk_support' => $averageScores['calculated_risk_support'],
                'comprehensive_risk_score' => ($averageScores['calculated_risk_workload'] !== null && $averageScores['calculated_risk_support'] !== null) ? round(($averageScores['calculated_risk_workload'] * $averageScores['calculated_risk_support']) / 100, 1) : null,
            ]
        );

        foreach ($scaleDistributions as $distribution) {
            AdminReportScaleDistribution::updateOrCreate(
                [
                    'admin_report_id' => $adminReport->id,
                    'scale_id' => $distribution['scale_id'],
                ],
                [
                    'bad_percentage' => $distribution['bad_percentage'],
                    'slightly_bad_percentage' => $distribution['slightly_bad_percentage'],
                    'average_percentage' => $distribution['average_percentage'],
                    'slightly_good_percentage' => $distribution['slightly_good_percentage'],
                    'good_percentage' => $distribution['good_percentage'],
                ]
            );
        }

        $thresholds = [
            'workload' => 8.7,
            'control' => 7.9,
            'supervisor_support' => 7.5,
            'coworker_support' => 8.1,
        ];

        $factorLabelsJa = [
            'workload' => '心理的な仕事の負担(量)',
            'control' => '仕事のコントロール度',
            'supervisor_support' => '上司からのサポート',
            'coworker_support' => '同僚からのサポート',
        ];

        $actualScores = [
            'workload' => (float) $averageScores['workload_score'],
            'control' => (float) $averageScores['control_score'],
            'supervisor_support' => (float) $averageScores['supervisor_support_score'],
            'coworker_support' => (float) $averageScores['coworker_support_score'],
        ];

        $belowAverageItems = [];
        foreach ($thresholds as $key => $threshold) {
            if ($actualScores[$key] < $threshold) {
                $belowAverageItems[$key] = $actualScores[$key];
            }
        }

        $badItemName = null;
        if (! empty($belowAverageItems)) {
            $minScore = min($belowAverageItems);
            $worstItems = array_keys(array_filter($belowAverageItems, function ($score) use ($minScore) {
                return abs($score - $minScore) < 0.0001;
            }));
            $badItemName = implode(', ', array_map(function ($item) use ($factorLabelsJa) {
                return $factorLabelsJa[$item] ?? $item;
            }, $worstItems));
        }

        $compRisk = $adminReport->comprehensive_risk_score;
        $compRiskStr = null;
        if ($compRisk !== null) {
            if ($compRisk < 90) {
                $compRiskStr = 'low';
            } elseif ($compRisk <= 110) {
                $compRiskStr = 'medium';
            } else {
                $compRiskStr = 'high';
            }
        }

        $workloadRisk = $adminReport->calculated_risk_workload;
        $workloadRiskStr = null;
        if ($workloadRisk !== null) {
            $workloadRiskStr = $workloadRisk < 100 ? 'low' : 'high';
        }

        $supportRisk = $adminReport->calculated_risk_support;
        $supportRiskStr = null;
        if ($supportRisk !== null) {
            $supportRiskStr = $supportRisk < 100 ? 'low' : 'high';
        }

        $reactionStr = null;
        if ($averageReactionToStressScore !== null) {
            if ($averageReactionToStressScore >= 6 && $averageReactionToStressScore <= 12) {
                $reactionStr = 'low';
            } elseif ($averageReactionToStressScore > 12 && $averageReactionToStressScore < 24) {
                $reactionStr = 'medium';
            } elseif ($averageReactionToStressScore >= 24 && $averageReactionToStressScore <= 30) {
                $reactionStr = 'high';
            }
        }

        $highStressRatio = $rates['high_stress_ratio'] ?? null;
        $highStressStr = null;
        if ($highStressRatio !== null) {
            $highStressStr = (float) $highStressRatio > 10.0 ? 'high' : 'low';
        }

        $adminReport->comments = json_encode([
            'comprehensive_risk_score' => $compRiskStr,
            'calculated_risk_workload' => $workloadRiskStr,
            'calculated_risk_support' => $supportRiskStr,
            'bad_item_name' => $badItemName,
            'reaction_to_stress_point' => $reactionStr,
            'high_stress_ratio' => $highStressStr,
        ]);
        $adminReport->save();

        $adminReport->setAttribute('bad_item_name', $badItemName);
        $adminReport->setAttribute('reaction_to_stress_score', $averageReactionToStressScore);

        $langCode = $user->lang_code ?: 'ja';

        $conditions = [
            'overview_health_risk' => $compRiskStr,
            'matrix_analysis_workload' => $workloadRiskStr,
            'matrix_analysis_support' => $supportRiskStr,
            'detailed_factors' => $badItemName === null ? 'null' : 'not_null',
            'reaction_evaluation' => $reactionStr,
            'recommendation_action' => $highStressStr,
        ];

        $commentsQuery = AdminReportComment::with(['translations' => function ($query) use ($langCode) {
            $query->whereIn('lang_code', [$langCode, 'ja']);
        }])->whereIn('section_id', array_keys($conditions))->get();

        $reportComments = [];
        $reactionEvaluationText = '';

        $replaceVars = [
            '{{comprehensive_risk_score}}' => $adminReport->comprehensive_risk_score ?? '',
            '{{calculated_risk_workload}}' => $adminReport->calculated_risk_workload ?? '',
            '{{calculated_risk_support}}' => $adminReport->calculated_risk_support ?? '',
            '{{high_stress_ratio}}' => $rates['high_stress_ratio'] ?? '',
            '{{bad_item_name}}' => $badItemName ?? '',
        ];

        foreach ($commentsQuery as $comment) {
            $sectionId = $comment->section_id;
            if (isset($conditions[$sectionId]) && $comment->condition === $conditions[$sectionId]) {
                $translation = $comment->translations->firstWhere('lang_code', $langCode)
                    ?? $comment->translations->firstWhere('lang_code', 'ja');

                if ($translation) {
                    $text = str_replace(array_keys($replaceVars), array_values($replaceVars), $translation->content);

                    if ($sectionId === 'reaction_evaluation') {
                        $reactionEvaluationText = $text;
                    } else {
                        $reportComments[$sectionId] = $text;
                    }
                }
            }
        }

        if (isset($reportComments['detailed_factors'])) {
            if ($reactionEvaluationText !== '') {
                $reportComments['detailed_factors'] = str_replace('{{reaction_evaluation}}', $reactionEvaluationText, $reportComments['detailed_factors']);
            } else {
                $sentencesToRemove = [
                    'ストレス反応や満足度に関しては、{{reaction_evaluation}}。',
                    ' Regarding stress reactions and satisfaction, it is {{reaction_evaluation}}.',
                    '关于压力反应和满意度，则{{reaction_evaluation}}。',
                    '關於壓力反應和滿意度，則{{reaction_evaluation}}。',
                    ' Về phản ứng với căng thẳng và mức độ hài lòng, đó là {{reaction_evaluation}}.',
                    ' Tungkol sa mga reaksyon sa stress at kasiyahan, ito ay {{reaction_evaluation}}.',
                    ' Em relação à reação ao estresse e satisfação, é {{reaction_evaluation}}.',
                    ' Con respecto a las reacciones al estrés y la satisfacción, esta es {{reaction_evaluation}}.',
                    ' ในส่วนของปฏิกิริยาตอบสนองความเครียดและความพึงพอใจนั้นคือ {{reaction_evaluation}}',
                    ' Mengenai reaksi stres dan kepuasan, statusnya {{reaction_evaluation}}.',
                ];
                $reportComments['detailed_factors'] = str_replace($sentencesToRemove, '', $reportComments['detailed_factors']);
            }
        }

        $adminReport->setAttribute('report_comments', $reportComments);

        $adminReport->setAttribute('start_date', $stressCheckPeriod->start_date);
        $adminReport->setAttribute('end_date', $stressCheckPeriod->end_date);
        $adminReport->setAttribute('total_employees', $rates['total_employees'] ?? 0);
        $adminReport->setAttribute('participated_employees', $rates['participated_employees'] ?? 0);
        $adminReport->setAttribute('not_completed_employees', $rates['not_completed_employees'] ?? 0);

        $adminReport->setAttribute('previous_workload_score', $averageScores['previous_workload_score'] ?? null);
        $adminReport->setAttribute('previous_control_score', $averageScores['previous_control_score'] ?? null);
        $adminReport->setAttribute('previous_supervisor_support_score', $averageScores['previous_supervisor_support_score'] ?? null);
        $adminReport->setAttribute('previous_coworker_support_score', $averageScores['previous_coworker_support_score'] ?? null);
        $adminReport->setAttribute('previous_calculated_risk_workload', $averageScores['previous_calculated_risk_workload'] ?? null);
        $adminReport->setAttribute('previous_calculated_risk_support', $averageScores['previous_calculated_risk_support'] ?? null);

        $previousRiskWorkload = $averageScores['previous_calculated_risk_workload'] ?? null;
        $previousRiskSupport = $averageScores['previous_calculated_risk_support'] ?? null;
        $previousComprehensiveRiskScore = ($previousRiskWorkload !== null && $previousRiskSupport !== null) ? round(($previousRiskWorkload * $previousRiskSupport) / 100, 1) : null;

        $adminReport->setAttribute('previous_comprehensive_risk_score', $previousComprehensiveRiskScore);
        $adminReport->setAttribute('previous_high_stress_ratio', $previousRates['high_stress_ratio'] ?? null);

        $adminReport->load([
            'scaleDistributions.scale' => function ($query) {
                $query->select('id', 'name')
                    ->with([
                        'translations' => function ($translationQuery) {
                            $translationQuery->select('id', 'scale_id', 'lang_code', 'content')
                                ->where('lang_code', 'ja');
                        },
                    ]);
            },
        ]);

        $adminReport->scaleDistributions->each(function ($distribution) {
            $distribution->setAttribute('scale_name', $distribution->scale?->name);
            $distribution->setAttribute(
                'scale_translation_ja',
                $distribution->scale?->translations->first()?->content
            );
            $distribution->unsetRelation('scale');
        });

        return $adminReport;
    }
}
