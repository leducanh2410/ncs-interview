import { Document } from '@/types/models/document';
import { Question } from '@/types/models/question';
import { Workplace } from '@/types/models/workplace';
import { ExamContentLanguage, TranslatableText } from '@/configs/exam_language.config';

export type ExamStatus = 'scheduled' | 'active' | 'ended';

export type ExamType = 'assessment' | 'external_form_prep';

export interface Exam {
    id: number;
    title: TranslatableText;
    languages: ExamContentLanguage[];
    type: ExamType;
    is_required: boolean;
    is_recurring: boolean;
    // Server-assigned (0/0/config default) and not user-editable when type = 'external_form_prep'.
    retry_limit: number;
    min_score: number;
    time_limit: number;
    external_url: TranslatableText;
    start_at: string | null;
    end_at: string | null;
    notified_at?: string | null;
    status?: ExamStatus;
    show_result_immediately: boolean;
}

/**
 * An employee explicitly named for an exam. `is_current_member` is false when
 * the employee no longer belongs to any of the exam's workplaces - the chip is
 * still shown, marked stale, because an empty selector means "everyone" and
 * silently dropping it would widen the audience on the next save.
 */
export interface ExamTargetedUser {
    id: number;
    name: string;
    name_kana: string;
    staff_no: string | null;
    is_current_member: boolean;
}

export interface ExamWithRelation extends Exam {
    workplaces: Workplace[];
    documents: Document[];
    questions: Question[];
    targeted_users: ExamTargetedUser[];
    has_results?: boolean;
}

export interface ExamStatisticsSummary {
    total_questions: number;
    min_score: number;
    attempt_count: number;
    participant_count: number;
    passed_count: number;
    pass_rate: number;
    average_score: number;
}

export interface ExamOptionStatistics {
    id: number;
    content: TranslatableText;
    is_correct: boolean;
    selected_count: number;
}

export interface ExamQuestionStatistics {
    id: number;
    no: number;
    content: TranslatableText;
    correct_count: number;
    wrong_count: number;
    unanswered_count: number;
    correct_rate: number;
    options: ExamOptionStatistics[];
}

export interface ExamStatistics {
    summary: ExamStatisticsSummary;
    questions: ExamQuestionStatistics[];
    available_occurrences: number[];
}
