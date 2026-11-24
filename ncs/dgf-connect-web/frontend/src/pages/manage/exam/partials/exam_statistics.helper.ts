import { ExamQuestionStatistics } from '@/types/models/exam';

export type CorrectRateSort = 'asc' | 'desc' | null;

export type AnswerBreakdown = {
    correct: number;
    wrong: number;
    unanswered: number;
};

const roundToOneDecimal = (value: number): number => Math.round(value * 10) / 10;

export function formatRate(rate: number): string {
    return `${roundToOneDecimal(rate * 100)}%`;
}

export function formatScore(score: number): string {
    return roundToOneDecimal(score).toString();
}

export function nextCorrectRateSort(current: CorrectRateSort): CorrectRateSort {
    if (current === null) {
        return 'desc';
    }
    if (current === 'desc') {
        return 'asc';
    }
    return null;
}

export function sortQuestionStatistics(
    questions: ExamQuestionStatistics[],
    sort: CorrectRateSort,
): ExamQuestionStatistics[] {
    return [...questions].sort((a, b) => {
        if (sort !== null && a.correct_rate !== b.correct_rate) {
            return sort === 'asc' ? a.correct_rate - b.correct_rate : b.correct_rate - a.correct_rate;
        }
        return a.no - b.no;
    });
}

export function getAnswerBreakdown(question: ExamQuestionStatistics): AnswerBreakdown {
    const total = question.correct_count + question.wrong_count + question.unanswered_count;
    if (total === 0) {
        return { correct: 0, wrong: 0, unanswered: 0 };
    }
    return {
        correct: (question.correct_count / total) * 100,
        wrong: (question.wrong_count / total) * 100,
        unanswered: (question.unanswered_count / total) * 100,
    };
}
