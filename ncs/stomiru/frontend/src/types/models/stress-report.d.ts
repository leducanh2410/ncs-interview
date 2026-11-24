export interface StressReportData {
  start_date: any;
  end_date: any;
  total_employees: number;
  participated_employees: number;
  participation_rate: number;
  workload_score: number;
  previous_workload_score: null;
  control_score: number;
  previous_control_score: null;
  supervisor_support_score: number;
  previous_supervisor_support_score: null;
  coworker_support_score: number;
  previous_coworker_support_score: null;
  reaction_to_stress_score: number;
  comprehensive_risk_score: number;
  high_stress_ratio: number;
  stress_check_period: {
    id: number;
    title: string;
    year: string;
    start_date: string;
    end_date: string;
    status: string;
  };
  summary: {
    total_employees: number;
    total_respondents: number;
    response_rate: number;
    not_completed?: number;
  };
  stress_factors: {
    psychological_burden_quantity: ScoreData;
    psychological_burden_quality: ScoreData;
    physical_burden: ScoreData;
    interpersonal_stress: ScoreData;
    workplace_environment: ScoreData;
    job_control: ScoreData;
    skill_utilization: ScoreData;
    job_suitability: ScoreData;
    work_motivation: ScoreData;
  };
  support: {
    supervisor_support: ScoreData;
    colleague_support: ScoreData;
    family_support: ScoreData;
    supervisor_relationship: ScoreData;
    colleague_relationship: ScoreData;
    family_relationship: ScoreData;
  };
  satisfaction: {
    job_satisfaction: ScoreData;
    life_satisfaction: ScoreData;
    consultation_availability: ScoreData;
    personal_respect: ScoreData;
  };
  stress_response: {
    vitality: ScoreData;
    irritability: ScoreData;
    fatigue: ScoreData;
    anxiety: ScoreData;
    depression: ScoreData;
    physical_complaints: ScoreData;
  };
  health_risk: {
    total_score: number;
    evaluation: "good" | "caution" | "warning";
    previous_score: number | null;
    national_average: number;
  };
  high_stress: {
    ratio: number;
    evaluation: "good" | "caution" | "warning";
    previous_ratio: number | null;
    national_average: number;
  };
  scatter_data: {
    psychological_workload: ScatterChartData;
    support: ScatterChartData;
  };
}

export interface ScoreData {
  current: number;
  previous: number | null;
  national: number;
  historical?: HistoricalScore[];
}

export interface HistoricalScore {
  year: string;
  score: number;
}

export interface ScatterChartData {
  current: { x: number; y: number };
  previous: { x: number; y: number } | null;
  national: { x: number; y: number };
  quadrants: QuadrantData[];
  health_risk?: number | null;
  previous_health_risk?: number | null;
  national_health_risk?: number;
}

export interface QuadrantData {
  name: string;
  color: string;
  count: number;
  percentage: number;
}
