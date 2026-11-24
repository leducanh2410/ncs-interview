import React from 'react';
import { MantineProvider } from '@mantine/core';
import { DatesProvider } from '@mantine/dates';
import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import ExamStatistics from './ExamStatistics';

const { useGetExamStatisticsQueryMock } = vi.hoisted(() => ({
    useGetExamStatisticsQueryMock: vi.fn(),
}));

vi.mock('@/pages/manage/exam/api', () => ({
    useGetExamStatisticsQuery: (...args: unknown[]) => useGetExamStatisticsQueryMock(...args),
}));

vi.mock('jotai', () => ({
    useAtom: () => [{ workplaces: [{ id: 1, name: 'Tokyo HQ' }] }],
}));

vi.mock('@/pages/global.state', () => ({
    masterAtom: { init: { workplaces: [] } },
}));

vi.mock('react-i18next', () => ({
    initReactI18next: { type: '3rdParty', init: () => undefined },
    useTranslation: () => ({
        t: (key: string, options?: Record<string, unknown>) =>
            options?.number !== undefined ? key.replace('{{number}}', String(options.number)) : key,
        i18n: { language: 'en' },
    }),
}));

const statistics = (availableOccurrences: number[]) => ({
    data: {
        data: {
            summary: {
                total_questions: 1,
                min_score: 1,
                attempt_count: 3,
                participant_count: 1,
                passed_count: 1,
                pass_rate: 1,
                average_score: 1,
            },
            questions: [],
            available_occurrences: availableOccurrences,
        },
    },
    isPending: false,
});

const renderComponent = (isRecurring: boolean) =>
    render(
        <MantineProvider env="test">
            <DatesProvider settings={{ locale: 'en' }}>
                <ExamStatistics examId="5" isRecurring={isRecurring} />
            </DatesProvider>
        </MantineProvider>,
    );

describe('ExamStatistics occurrence filter', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('hides the occurrence selector for a one-time exam without later rounds', () => {
        useGetExamStatisticsQueryMock.mockReturnValue(statistics([1]));

        renderComponent(false);

        expect(screen.queryByLabelText('Occurrence')).not.toBeInTheDocument();
    });

    it('shows the occurrence selector for a one-time exam that has recurring history', () => {
        useGetExamStatisticsQueryMock.mockReturnValue(statistics([1, 2]));

        renderComponent(false);

        expect(screen.getAllByLabelText('Occurrence').length).toBeGreaterThan(0);
    });

    it('passes the selected occurrence to the statistics query', async () => {
        useGetExamStatisticsQueryMock.mockReturnValue(statistics([1, 2]));

        renderComponent(true);

        expect(useGetExamStatisticsQueryMock).toHaveBeenLastCalledWith(
            '5',
            expect.objectContaining({ occurrence: null }),
        );

        fireEvent.click(screen.getAllByLabelText('Occurrence')[0]);
        fireEvent.click(await screen.findByText('Round 2'));

        expect(useGetExamStatisticsQueryMock).toHaveBeenLastCalledWith(
            '5',
            expect.objectContaining({ occurrence: '2' }),
        );
    });
});
