import { Exam } from '@/types/models/exam';
import { User } from '@/types/models/user';
import { WorkplaceSimple } from '@/types/models/workplace';

export interface ExamResult {
    id: number;
    user_id: number;
    exam_id: number;
    workplace_id: number | null;
    occurrence: number;
    workplace?: WorkplaceSimple | null;
    score: number;
    total_questions: number;
    correct_answers: number;
    started_at: string;
    completed_at: string | null;
    external_completed_at: string | null;
    expires_at: string;
    status: string;
}

export interface ExamResultWithRelation extends ExamResult {
    exam: Exam;
    questions: {
        question_id: number;
        question_option_id: number | null;
        is_correct: boolean;
        is_carried_over: boolean;
    }[];
    user: User;
}
