import { describe, expect, it } from 'vitest';
import { ExamQuestionStatistics } from '@/types/models/exam';
import {
    formatRate,
    formatScore,
    getAnswerBreakdown,
    nextCorrectRateSort,
    sortQuestionStatistics,
} from './exam_statistics.helper';

const makeQuestion = (overrides: Partial<ExamQuestionStatistics>): ExamQuestionStatistics => ({
    id: 1,
    no: 1,
    content: { en: 'Question' },
    correct_count: 0,
    wrong_count: 0,
    unanswered_count: 0,
    correct_rate: 0,
    options: [],
    ...overrides,
});

describe('formatRate', () => {
    it('formats a 0-1 ratio as a percentage with at most one decimal', () => {
        expect(formatRate(0)).toBe('0%');
        expect(formatRate(1)).toBe('100%');
        expect(formatRate(0.5)).toBe('50%');
        expect(formatRate(2 / 3)).toBe('66.7%');
    });
});

describe('formatScore', () => {
    it('rounds to one decimal', () => {
        expect(formatScore(7)).toBe('7');
        expect(formatScore(7.456)).toBe('7.5');
    });
});

describe('nextCorrectRateSort', () => {
    it('cycles through descending, ascending and the default order', () => {
        expect(nextCorrectRateSort(null)).toBe('desc');
        expect(nextCorrectRateSort('desc')).toBe('asc');
        expect(nextCorrectRateSort('asc')).toBeNull();
    });
});

describe('sortQuestionStatistics', () => {
    const questions = [
        makeQuestion({ id: 3, no: 3, correct_rate: 0.5 }),
        makeQuestion({ id: 1, no: 1, correct_rate: 0.9 }),
        makeQuestion({ id: 2, no: 2, correct_rate: 0.2 }),
        makeQuestion({ id: 4, no: 4, correct_rate: 0.5 }),
    ];

    it('keeps question number order without a sort', () => {
        expect(sortQuestionStatistics(questions, null).map((q) => q.no)).toEqual([1, 2, 3, 4]);
    });

    it('sorts by correct rate ascending, breaking ties by question number', () => {
        expect(sortQuestionStatistics(questions, 'asc').map((q) => q.no)).toEqual([2, 3, 4, 1]);
    });

    it('sorts by correct rate descending, breaking ties by question number', () => {
        expect(sortQuestionStatistics(questions, 'desc').map((q) => q.no)).toEqual([1, 3, 4, 2]);
    });

    it('does not mutate the input', () => {
        sortQuestionStatistics(questions, 'asc');
        expect(questions.map((q) => q.no)).toEqual([3, 1, 2, 4]);
    });
});

describe('getAnswerBreakdown', () => {
    it('returns the share of each outcome as percentages', () => {
        expect(getAnswerBreakdown(makeQuestion({ correct_count: 2, wrong_count: 1, unanswered_count: 1 }))).toEqual({
            correct: 50,
            wrong: 25,
            unanswered: 25,
        });
    });

    it('returns zeros when nobody answered', () => {
        expect(getAnswerBreakdown(makeQuestion({}))).toEqual({ correct: 0, wrong: 0, unanswered: 0 });
    });
});
