<?php

namespace App\Services\Admin;

use App\Enums\EmployeeStatus;
use App\Enums\ExamSessionStatus;
use App\Enums\RoleName;
use App\Enums\StressLevel;
use App\Models\ExamSession;
use App\Models\StressCheckPeriod;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class DashboardService
{
    // National average evaluation scores (standard 57-item questionnaire)
    private const NATIONAL_AVG = [
        'job_demand_score' => 8.7,
        'job_control_score' => 7.9,
        'supervisor_support_score' => 7.5,
        'colleague_support_score' => 8.1,
    ];

    private const NATIONAL_AVG_HEALTH_RISK = 100;

    private const NATIONAL_AVG_HIGH_STRESS_RATIO = 10.0;

    public function getDashboard(): array
    {
        $companyId = Auth::user()->company_id;

        $periods = StressCheckPeriod::where('company_id', $companyId)
            ->orderByDesc('end_date')
            ->limit(2)
            ->get();

        if ($periods->isEmpty()) {
            return [
                'is_empty' => true,
                'latest_period' => null,
                'high_stress_ratio' => null,
                'high_stress_ratio_previous' => null,
                'high_stress_evaluation' => null,
                'health_risk' => null,
                'health_risk_previous' => null,
                'health_risk_evaluation' => null,
                'chart_data' => null,
            ];
        }

        $latest = $periods->first();
        $previous = $periods->count() > 1 ? $periods->get(1) : null;

        $totalExaminees = User::where('company_id', $companyId)
            ->whereHas('roles', function ($q) {
                $q->whereIn('name', [
                    RoleName::Staff->value,
                    RoleName::StaffManager->value,
                ]);
            })
            ->where(function ($query) use ($latest) {
                $query->whereNotIn('employee_status', [
                    EmployeeStatus::Retired->value,
                    EmployeeStatus::OnLeave->value,
                ])->orWhereHas('examSessions', function ($q) use ($latest) {
                    $q->where('stress_check_period_id', $latest->id)
                        ->where('status', ExamSessionStatus::Submitted->value);
                });
            })
            ->count();

        $answeredExaminees = ExamSession::where('stress_check_period_id', $latest->id)
            ->where('status', ExamSessionStatus::Submitted->value)
            ->count();

        $highStressRatio = $this->calcHighStressRatio($latest->id);
        $healthRisk = $this->calcHealthRisk($latest->id);

        $chartCurrent = [
            'job_demand_score' => $this->avgScore($latest->id, 'job_demand_score'),
            'job_control_score' => $this->avgScore($latest->id, 'job_control_score'),
            'supervisor_support_score' => $this->avgScore($latest->id, 'supervisor_support_score'),
            'colleague_support_score' => $this->avgScore($latest->id, 'colleague_support_score'),
        ];

        $chartPrevious = $previous ? [
            'job_demand_score' => $this->avgScore($previous->id, 'job_demand_score'),
            'job_control_score' => $this->avgScore($previous->id, 'job_control_score'),
            'supervisor_support_score' => $this->avgScore($previous->id, 'supervisor_support_score'),
            'colleague_support_score' => $this->avgScore($previous->id, 'colleague_support_score'),
        ] : null;

        return [
            'is_empty' => false,
            'latest_period' => [
                'id' => $latest->id,
                'title' => $latest->title,
                'year' => $latest->year,
                'start_date' => $latest->start_date,
                'end_date' => $latest->end_date,
                'status' => $latest->status,
                'total_examinees' => $totalExaminees,
                'answered_examinees' => $answeredExaminees,
                'response_rate' => $totalExaminees > 0
                    ? round(($answeredExaminees / $totalExaminees) * 100, 1)
                    : null,
            ],
            'high_stress_ratio' => $highStressRatio,
            'high_stress_ratio_previous' => $previous ? $this->calcHighStressRatio($previous->id) : null,
            'high_stress_evaluation' => $this->evaluateHighStress($highStressRatio),
            'health_risk' => $healthRisk,
            'health_risk_previous' => $previous ? $this->calcHealthRisk($previous->id) : null,
            'health_risk_evaluation' => $this->evaluateHealthRisk($healthRisk),
            'chart_data' => [
                'psychological_burden' => [
                    'current' => $chartCurrent['job_demand_score'],
                    'previous' => $chartPrevious['job_demand_score'] ?? null,
                    'evaluation' => $this->evaluateCategory($chartCurrent['job_demand_score'], self::NATIONAL_AVG['job_demand_score']),
                ],
                'job_control' => [
                    'current' => $chartCurrent['job_control_score'],
                    'previous' => $chartPrevious['job_control_score'] ?? null,
                    'evaluation' => $this->evaluateCategory($chartCurrent['job_control_score'], self::NATIONAL_AVG['job_control_score']),
                ],
                'supervisor_support' => [
                    'current' => $chartCurrent['supervisor_support_score'],
                    'previous' => $chartPrevious['supervisor_support_score'] ?? null,
                    'evaluation' => $this->evaluateCategory($chartCurrent['supervisor_support_score'], self::NATIONAL_AVG['supervisor_support_score']),
                ],
                'colleague_support' => [
                    'current' => $chartCurrent['colleague_support_score'],
                    'previous' => $chartPrevious['colleague_support_score'] ?? null,
                    'evaluation' => $this->evaluateCategory($chartCurrent['colleague_support_score'], self::NATIONAL_AVG['colleague_support_score']),
                ],
            ],
        ];
    }

    private function calcHighStressRatio(int $periodId): ?float
    {
        $total = ExamSession::where('stress_check_period_id', $periodId)
            ->where('status', ExamSessionStatus::Submitted->value)
            ->count();

        if ($total === 0) {
            return null;
        }

        $high = ExamSession::where('stress_check_period_id', $periodId)
            ->where('status', ExamSessionStatus::Submitted->value)
            ->whereHas('examResult', function ($query) {
                $query->where('stress_level', StressLevel::High->value);
            })
            ->count();

        return round(($high / $total) * 100, 1);
    }

    /**
     * Calculate total health risk.
     * Formula: (jobDemand/jobControl risk) × (support risk) / 100
     * Higher score = higher risk. 100 = national average.
     */
    private function calcHealthRisk(int $periodId): ?float
    {
        $avgs = DB::table('exam_sessions')
            ->join('exam_results', 'exam_sessions.id', '=', 'exam_results.exam_session_id')
            ->where('exam_sessions.stress_check_period_id', $periodId)
            ->where('exam_sessions.status', ExamSessionStatus::Submitted->value)
            ->select([
                DB::raw('AVG(exam_results.job_demand_score) as avg_demand'),
                DB::raw('AVG(exam_results.job_control_score) as avg_control'),
                DB::raw('AVG(exam_results.supervisor_support_score) as avg_supervisor'),
                DB::raw('AVG(exam_results.colleague_support_score) as avg_colleague'),
            ])
            ->first();

        if (! $avgs || $avgs->avg_demand === null) {
            return null;
        }

        $demand = (float) $avgs->avg_demand;
        $control = (float) $avgs->avg_control;
        $supervisor = (float) $avgs->avg_supervisor;
        $colleague = (float) $avgs->avg_colleague;

        $demandControlRisk = (int) floor(100 * exp(($demand - 8.7) * 0.076 + ($control - 7.9) * -0.089));

        $supportRisk = (int) floor(100 * exp(($supervisor - 7.5) * -0.097 + ($colleague - 8.1) * -0.097));

        $healthRisk = ($demandControlRisk * $supportRisk) / 100;

        return round($healthRisk, 1);
    }

    private function avgScore(int $periodId, string $column): ?float
    {
        $avg = DB::table('exam_sessions')
            ->join('exam_results', 'exam_sessions.id', '=', 'exam_results.exam_session_id')
            ->where('exam_sessions.stress_check_period_id', $periodId)
            ->where('exam_sessions.status', ExamSessionStatus::Submitted->value)
            ->whereNotNull("exam_results.{$column}")
            ->avg("exam_results.{$column}");

        return $avg !== null ? round((float) $avg, 1) : null;
    }

    /**
     * Evaluate high stress ratio: lower is better.
     * good: <= 10%, caution: > 10%
     */
    private function evaluateHighStress(?float $ratio): ?string
    {
        if ($ratio === null) {
            return null;
        }

        return $ratio <= self::NATIONAL_AVG_HIGH_STRESS_RATIO ? 'good' : 'caution';
    }

    /**
     * Evaluate health risk: lower is better (100 = national average).
     * good: <= 100, caution: > 100
     */
    private function evaluateHealthRisk(?float $risk): ?string
    {
        if ($risk === null) {
            return null;
        }

        return $risk <= self::NATIONAL_AVG_HEALTH_RISK ? 'good' : 'caution';
    }

    /**
     * Evaluate category score: higher is better (means less stress / more support).
     * good: >= national average, caution: < national average
     */
    private function evaluateCategory(?float $score, float $nationalAvg): ?string
    {
        if ($score === null) {
            return null;
        }

        return $score >= $nationalAvg ? 'good' : 'caution';
    }
}
