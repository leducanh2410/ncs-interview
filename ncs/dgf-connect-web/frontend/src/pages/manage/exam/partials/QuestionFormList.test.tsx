import React, { useState } from 'react';
import { MantineProvider } from '@mantine/core';
import { useForm } from '@mantine/form';
import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import QuestionFormList from './QuestionFormList';
import { ExamFormType, QuestionList } from './exam_form';

vi.mock('react-i18next', () => ({
    initReactI18next: { type: '3rdParty', init: () => undefined },
    useTranslation: () => ({ t: (key: string) => key }),
}));

vi.mock('@/components/DLanguageTabsInput', () => ({
    __esModule: true,
    default: ({ path, disabled }: any) =>
        React.createElement('input', { 'aria-label': `content-${path}`, 'disabled': disabled }),
}));

const questions = [
    {
        id: 1,
        content: { en: 'Question' },
        options: [
            { id: 10, content: { en: 'A' }, is_correct: true },
            { id: 11, content: { en: 'B' }, is_correct: false },
        ],
    },
];

const Harness = ({ structureLocked }: { structureLocked: boolean }) => {
    const form = useForm<ExamFormType>({
        mode: 'uncontrolled',
        initialValues: { questions } as unknown as ExamFormType,
    });
    const [questionList, setQuestionList] = useState<QuestionList>({
        order: [1],
        question_order: { 1: [10, 11] },
    });

    return (
        <MantineProvider>
            <QuestionFormList
                form={form}
                questionList={questionList}
                setQuestionList={setQuestionList}
                enabledLanguages={['en']}
                structureLocked={structureLocked}
            />
        </MantineProvider>
    );
};

describe('QuestionFormList structure lock', () => {
    it('disables adding, removing and re-marking answers while keeping text editable', () => {
        render(<Harness structureLocked />);

        expect(screen.getByRole('button', { name: 'Add question' })).toBeDisabled();
        expect(screen.getByRole('button', { name: 'Delete question' })).toBeDisabled();
        expect(screen.getByRole('button', { name: 'Add answer' })).toBeDisabled();
        screen.getAllByRole('checkbox').forEach((checkbox) => expect(checkbox).toBeDisabled());
        expect(screen.getByLabelText('content-questions.0.content')).not.toBeDisabled();
        expect(screen.getByLabelText('content-questions.0.options.1.content')).not.toBeDisabled();
    });

    it('leaves the structure editable when not locked', () => {
        render(<Harness structureLocked={false} />);

        expect(screen.getByRole('button', { name: 'Add question' })).not.toBeDisabled();
        expect(screen.getByRole('button', { name: 'Delete question' })).not.toBeDisabled();
        expect(screen.getByRole('button', { name: 'Add answer' })).not.toBeDisabled();
        screen.getAllByRole('checkbox').forEach((checkbox) => expect(checkbox).not.toBeDisabled());
    });
});
