import type { Question } from "./question";
import type { User } from "./user";

export interface ExamSessionAnswer {
  question_id: number;
  option_id: number;
}

export interface ExamSession {
  id: number;
  stress_check_period_id: number;
  user_id: number;
  started_at: string | null;
  submitted_at: string | null;
  status: string;
  questions_answered?: ExamSessionAnswer[] | null;
}

export interface StressCheck {
  id: number;
  title: string;
  year: string;
  period: string;
  status: string;
  start_date: any;
  end_date: any;
  company_id: number;
  exam_sessions?: ExamSession[];
}

export interface IStressCheckExam extends StressCheck {
  question_set: {
    question_categories: QuestionCategory[];
    questions: Question[];
  };
  current_exam_session?: ExamSession;
}

export interface ExamResult {
  id: number;
  exam_session_id: number;
  stress_level: string | null;
  job_demand_score: number | null;
  job_control_score: number | null;
  supervisor_support_score: number | null;
  colleague_support_score: number | null;
  advice_codes: { scale_id: number; level: string }[] | null;
}

export interface RadarScore {
  id: number;
  exam_session_id: number;
  scale_id: number;
  question_category_id: number | null;
  score: number | null;
}

export interface ExamComment {
  scale_id: number;
  scale_name: string | null;
  level: string;
  content: string | null;
}

export interface ExamResultData {
  exam_result: ExamResult;
  radar_scores: RadarScore[];
  previous_radar_scores: RadarScore[] | null;
  category_a_score: number;
  category_b_score: number;
  category_c_score: number;
  comments: ExamComment[];
}

export interface Examinee extends User {
  judgement: string;
  response_status: string;
}
