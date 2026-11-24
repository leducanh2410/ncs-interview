import React from 'react';
import { MantineProvider } from '@mantine/core';
import { DatesProvider } from '@mantine/dates';
import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { PollTargetTypeEnum } from '@/enums/poll_target_type.enum';
import FormHelper from '@/helpers/form.helper';
import NotificationHelper from '@/helpers/notification.helper';
import PollForm from './poll_form';

const { translateMutateAsyncMock, useGetMasterDataMock, useCreatePollMutationMock, useUpdatePollMutationMock } =
    vi.hoisted(() => ({
        translateMutateAsyncMock: vi.fn(),
        useGetMasterDataMock: vi.fn(),
        useCreatePollMutationMock: vi.fn(),
        useUpdatePollMutationMock: vi.fn(),
    }));

vi.mock('@/pages/master.api', () => ({
    useGetMasterData: (...args: unknown[]) => useGetMasterDataMock(...args),
}));

vi.mock('@/pages/manage/poll/api', () => ({
    useCreatePollMutation: (...args: unknown[]) => useCreatePollMutationMock(...args),
    useUpdatePollMutation: (...args: unknown[]) => useUpdatePollMutationMock(...args),
}));

vi.mock('@/pages/translate.api', () => ({
    useTranslateContentMutation: () => ({ mutateAsync: translateMutateAsyncMock, isPending: false }),
}));

vi.mock('react-i18next', () => ({
    initReactI18next: { type: '3rdParty', init: () => undefined },
    useTranslation: () => ({
        t: (key: string) => key,
    }),
}));

vi.mock('@/helpers/notification.helper', () => ({
    default: {
        showSuccess: vi.fn(),
        showError: vi.fn(),
    },
}));
vi.mock('@/helpers/form.helper', () => ({
    default: {
        setFormError: vi.fn(),
    },
}));

// Mock DSelectRemoteMultiple to avoid network calls
vi.mock('@/components/DSelectRemoteMultiple', () => ({
    default: (props: any) => (
        <div data-testid="d-select-remote-multiple">
            <label>{props.label}</label>
            <input
                data-testid={`remote-select-${props.label}`}
                value={Array.isArray(props.value) ? props.value.join(',') : ''}
                onChange={() => {}}
            />
        </div>
    ),
}));

const successQuery = (data: any) => ({
    data,
    isLoading: false,
    isError: false,
    refetch: vi.fn(),
});

describe('PollForm', () => {
    beforeEach(() => {
        vi.clearAllMocks();

        useGetMasterDataMock.mockReturnValue(
            successQuery({
                data: {
                    workplaces: [
                        { id: 1, name: 'Workplace 1' },
                        { id: 2, name: 'Workplace 2' },
                    ],
                },
            }),
        );

        useCreatePollMutationMock.mockReturnValue({
            mutateAsync: vi.fn().mockResolvedValue({}),
            isPending: false,
        });

        useUpdatePollMutationMock.mockReturnValue({
            mutateAsync: vi.fn().mockResolvedValue({}),
            isPending: false,
        });
    });

    const renderComponent = (poll: any = null, onClose = vi.fn()) => {
        return render(
            <MantineProvider env="test">
                <DatesProvider settings={{ locale: 'en' }}>
                    <PollForm poll={poll} onClose={onClose} />
                </DatesProvider>
            </MantineProvider>,
        );
    };

    /** Switch the Type select from Vote to Feedback */
    const switchToFeedbackType = async () => {
        const typeInputs = screen.getAllByLabelText(/^Type/i);
        fireEvent.click(typeInputs[0]);
        const feedbackOption = await screen.findByText('Feedback');
        fireEvent.click(feedbackOption);
    };

    const fillTitles = () => {
        fireEvent.change(screen.getByRole('textbox', { name: /Title \(VI\)/i }), { target: { value: 'VI' } });
        fireEvent.change(screen.getByRole('textbox', { name: /Title \(EN\)/i }), { target: { value: 'EN' } });
        fireEvent.change(screen.getByRole('textbox', { name: /Title \(JA\)/i }), { target: { value: 'JA' } });
    };

    /** Fill the default empty choice labels that appear when poll=null */
    const fillDefaultChoiceLabels = () => {
        fireEvent.change(screen.getByPlaceholderText('Label (VI)'), { target: { value: 'Choice VI' } });
        fireEvent.change(screen.getByPlaceholderText('Label (EN)'), { target: { value: 'Choice EN' } });
        fireEvent.change(screen.getByPlaceholderText('Label (JA)'), { target: { value: 'Choice JA' } });
    };

    it('renders create form correctly', () => {
        renderComponent();
        expect(screen.getByText('Scope')).toBeInTheDocument();
        expect(screen.getByText('Global (all workplaces)')).toBeInTheDocument();
    });

    it('changes scope and target type', async () => {
        renderComponent();

        const scopeInputs = screen.getAllByLabelText(/Scope/i);
        fireEvent.click(scopeInputs[0]);

        // Select Workplace
        const wpOption = await screen.findByText('Specific Workplace');
        fireEvent.click(wpOption);
        expect(await screen.findByText('Workplace')).toBeInTheDocument();

        // Select Specific Users
        fireEvent.click(scopeInputs[0]);
        const usersOption = await screen.findByText('Specific Users');
        fireEvent.click(usersOption);
        expect(await screen.findByText('Target Users')).toBeInTheDocument();
    });

    it('pre-fills data when editing a poll', () => {
        renderComponent({
            id: 123,
            type: 'vote',
            target_type: PollTargetTypeEnum.WORKPLACE.value,
            workplace_id: 1,
            title_vi: 'Title VI',
            title_en: 'Title EN',
            title_ja: 'Title JA',
            options: ['Option 1'],
            is_multiple: true,
        });

        expect(screen.getByDisplayValue('Title VI')).toBeInTheDocument();
        expect(screen.getByText('Workplace')).toBeInTheDocument();
    });

    it('can add and remove poll choices', async () => {
        const user = userEvent.setup();
        // Render as edit with feedback type and one existing choice
        renderComponent({
            id: 123,
            type: 'feedback',
            target_type: PollTargetTypeEnum.GLOBAL.value,
            title_vi: 'V',
            title_en: 'E',
            title_ja: 'J',
            is_active: false,
            responses_count: 0,
            options: [{ id: '1', label_vi: 'LV', label_en: 'LE', label_ja: 'LJ' }],
        });

        // Add a choice
        const addBtn = screen.getByText('Add choice');
        await user.click(addBtn);

        // Now there should be 2 choices
        const labelInputs = screen.getAllByPlaceholderText('Label (VI)');
        expect(labelInputs).toHaveLength(2);

        // Type in the new (second) choice
        await user.type(labelInputs[1], 'New Label');
        expect(labelInputs[1]).toHaveValue('New Label');

        // Remove the second choice
        const removeBtns = screen.getAllByTestId('remove-choice-btn');
        await user.click(removeBtns[1]);

        // Back to 1 choice
        expect(screen.getAllByPlaceholderText('Label (VI)')).toHaveLength(1);
    });

    it('applies translated choice labels by stable key when an earlier choice is removed', async () => {
        let resolveTranslation: (value: unknown) => void = () => undefined;
        translateMutateAsyncMock.mockReturnValue(
            new Promise((resolve) => {
                resolveTranslation = resolve;
            }),
        );
        renderComponent({
            id: 123,
            type: 'feedback',
            target_type: PollTargetTypeEnum.GLOBAL.value,
            title_vi: 'VI',
            title_en: 'EN',
            title_ja: 'JA',
            is_active: false,
            responses_count: 0,
            options: [
                { id: '1', label_vi: 'Choice A', label_en: 'A', label_ja: 'A' },
                { id: '2', label_vi: 'Choice B', label_en: 'B', label_ja: 'B' },
            ],
        });

        fireEvent.click(screen.getByRole('button', { name: 'AI translate' }));
        await waitFor(() => expect(translateMutateAsyncMock).toHaveBeenCalledTimes(1));
        fireEvent.click(screen.getAllByTestId('remove-choice-btn')[0]);

        await act(async () => {
            resolveTranslation({
                data: {
                    translations: {
                        ne: { title: 'NE', choice_0: 'A NE', choice_1: 'B NE' },
                        si: { title: 'SI', choice_0: 'A SI', choice_1: 'B SI' },
                    },
                },
            });
        });

        await waitFor(() => expect(screen.getByPlaceholderText('Label (NE)')).toHaveValue('B NE'));
    });

    it('clamps AI-translated title, description, and choice labels to their max length', async () => {
        translateMutateAsyncMock.mockResolvedValue({
            data: {
                translations: {
                    ne: {
                        title: 'T'.repeat(300),
                        description: 'D'.repeat(1200),
                        choice_0: 'L'.repeat(300),
                    },
                },
            },
        });
        renderComponent({
            id: 123,
            type: 'feedback',
            target_type: PollTargetTypeEnum.GLOBAL.value,
            title_vi: 'VI',
            title_en: 'EN',
            title_ja: 'JA',
            description_vi: 'Desc VI',
            is_active: false,
            responses_count: 0,
            options: [{ id: '1', label_vi: 'Choice A', label_en: 'A', label_ja: 'A' }],
        });

        fireEvent.click(screen.getByRole('button', { name: 'AI translate' }));

        await waitFor(() =>
            expect(screen.getByRole('textbox', { name: /Title \(NE\)/i })).toHaveValue('T'.repeat(255)),
        );
        expect(screen.getByRole('textbox', { name: /Description \(NE\)/i })).toHaveValue('D'.repeat(1000));
        expect(screen.getByPlaceholderText('Label (NE)')).toHaveValue('L'.repeat(255));
    });

    it('calls submit correctly for create draft', async () => {
        const createMock = vi.fn().mockResolvedValue({});
        useCreatePollMutationMock.mockReturnValue({ mutateAsync: createMock, isPending: false });

        const onClose = vi.fn();
        renderComponent(null, onClose);

        // Switch from Vote to Feedback to avoid candidate validation
        await switchToFeedbackType();

        fillTitles();

        // Fill the default choice labels so validation passes
        fillDefaultChoiceLabels();

        const draftBtn = screen.getByText('Create draft');
        fireEvent.click(draftBtn);

        await waitFor(() => {
            expect(createMock).toHaveBeenCalled();
            expect(NotificationHelper.showSuccess).toHaveBeenCalled();
            expect(onClose).toHaveBeenCalled();
        });
    });

    it('calls submit correctly for create and send', async () => {
        const createMock = vi.fn().mockResolvedValue({});
        useCreatePollMutationMock.mockReturnValue({ mutateAsync: createMock, isPending: false });

        const onClose = vi.fn();
        renderComponent(null, onClose);

        await switchToFeedbackType();

        fillTitles();

        fillDefaultChoiceLabels();

        const sendBtn = screen.getByText('Create and send');
        fireEvent.click(sendBtn);

        await waitFor(() => {
            expect(createMock).toHaveBeenCalled();
            expect(NotificationHelper.showSuccess).toHaveBeenCalled();
            expect(onClose).toHaveBeenCalled();
        });
    });

    it('omits the locked targeting fields when editing an active poll', async () => {
        const user = userEvent.setup();
        const updateMock = vi.fn().mockResolvedValue({});
        useUpdatePollMutationMock.mockReturnValue({ mutateAsync: updateMock, isPending: false });

        renderComponent({
            id: 123,
            type: 'feedback',
            target_type: PollTargetTypeEnum.SPECIFIC_USERS.value,
            title_vi: 'VI',
            title_en: 'EN',
            title_ja: 'JA',
            is_active: true,
            responses_count: 0,
            target_users: [{ id: 7, first_name: 'A', last_name: 'B', staff_no: '007' }],
            options: [{ id: '1', label_vi: 'LV', label_en: 'LE', label_ja: 'LJ' }],
        });

        await user.click(screen.getByText('Save changes'));

        await waitFor(() => expect(updateMock).toHaveBeenCalled());

        const payload = updateMock.mock.calls[0][0];
        expect(payload).not.toHaveProperty('target_type');
        expect(payload).not.toHaveProperty('workplace_id');
        expect(payload).not.toHaveProperty('target_users');
        expect(payload.title_en).toBe('EN');
    });

    it('sends target_type when editing an unlocked poll', async () => {
        const user = userEvent.setup();
        const updateMock = vi.fn().mockResolvedValue({});
        useUpdatePollMutationMock.mockReturnValue({ mutateAsync: updateMock, isPending: false });

        renderComponent({
            id: 123,
            type: 'feedback',
            target_type: PollTargetTypeEnum.GLOBAL.value,
            title_vi: 'VI',
            title_en: 'EN',
            title_ja: 'JA',
            is_active: false,
            responses_count: 0,
            options: [{ id: '1', label_vi: 'LV', label_en: 'LE', label_ja: 'LJ' }],
        });

        await user.click(screen.getByText('Save changes'));

        await waitFor(() => expect(updateMock).toHaveBeenCalled());

        const payload = updateMock.mock.calls[0][0];
        expect(payload.target_type).toBe(PollTargetTypeEnum.GLOBAL.value);
    });

    it('calls update correctly for edit', async () => {
        const user = userEvent.setup();
        const updateMock = vi.fn().mockResolvedValue({});
        useUpdatePollMutationMock.mockReturnValue({ mutateAsync: updateMock, isPending: false });

        const onClose = vi.fn();
        renderComponent(
            {
                id: 123,
                type: 'vote',
                target_type: PollTargetTypeEnum.GLOBAL.value,
                title_vi: 'VI',
                title_en: 'EN',
                title_ja: 'JA',
                options: ['O1'],
                is_multiple: false,
            },
            onClose,
        );

        const saveBtn = screen.getByText('Save changes');
        await user.click(saveBtn);

        await waitFor(() => {
            expect(updateMock).toHaveBeenCalled();
            expect(NotificationHelper.showSuccess).toHaveBeenCalled();
            expect(onClose).toHaveBeenCalled();
        });
    });

    it('handles api errors on submit', async () => {
        const createMock = vi.fn().mockRejectedValue(new Error('API error'));
        useCreatePollMutationMock.mockReturnValue({ mutateAsync: createMock, isPending: false });

        renderComponent(null);

        await switchToFeedbackType();

        fillTitles();

        fillDefaultChoiceLabels();

        const draftBtn = screen.getByText('Create draft');
        fireEvent.click(draftBtn);

        await waitFor(() => {
            expect(FormHelper.setFormError).toHaveBeenCalled();
        });
    });

    it('shows validation error when feedback choices are empty', async () => {
        renderComponent(null);

        await switchToFeedbackType();

        // Fill titles but leave choice labels empty
        fillTitles();

        const draftBtn = screen.getByText('Create draft');
        fireEvent.click(draftBtn);

        await waitFor(() => {
            expect(NotificationHelper.showError).toHaveBeenCalledWith('Please fill in all choice labels');
        });
    });

    it('blocks submit of a vote poll with no candidates selected', async () => {
        const createMock = vi.fn().mockResolvedValue({});
        useCreatePollMutationMock.mockReturnValue({ mutateAsync: createMock, isPending: false });

        renderComponent(null);

        // Type defaults to Vote, and no candidate is selected.
        fillTitles();

        fireEvent.click(screen.getByText('Create draft'));

        // The error is surfaced via form.setFieldError on the mocked remote select,
        // so the observable effect is that the poll is never created.
        await waitFor(() => expect(createMock).not.toHaveBeenCalled());
        expect(NotificationHelper.showSuccess).not.toHaveBeenCalled();
    });

    it('blocks submit of a workplace poll with no workplace selected', async () => {
        const createMock = vi.fn().mockResolvedValue({});
        useCreatePollMutationMock.mockReturnValue({ mutateAsync: createMock, isPending: false });

        renderComponent(null);

        await switchToFeedbackType();

        const scopeInputs = screen.getAllByLabelText(/Scope/i);
        fireEvent.click(scopeInputs[0]);
        fireEvent.click(await screen.findByText('Specific Workplace'));
        expect(await screen.findByText('Workplace')).toBeInTheDocument();

        fillTitles();

        fillDefaultChoiceLabels();

        fireEvent.click(screen.getByText('Create draft'));

        // Mantine renders the field error next to the (real) workplace Select.
        expect(await screen.findByText('Please select a workplace')).toBeInTheDocument();
        expect(createMock).not.toHaveBeenCalled();
    });

    it('blocks submit of a specific-users poll with no target users selected', async () => {
        const createMock = vi.fn().mockResolvedValue({});
        useCreatePollMutationMock.mockReturnValue({ mutateAsync: createMock, isPending: false });

        renderComponent(null);

        await switchToFeedbackType();

        const scopeInputs = screen.getAllByLabelText(/Scope/i);
        fireEvent.click(scopeInputs[0]);
        fireEvent.click(await screen.findByText('Specific Users'));
        expect(await screen.findByText('Target Users')).toBeInTheDocument();

        fillTitles();

        fillDefaultChoiceLabels();

        fireEvent.click(screen.getByText('Create draft'));

        await waitFor(() => expect(createMock).not.toHaveBeenCalled());
        expect(NotificationHelper.showSuccess).not.toHaveBeenCalled();
    });
    const recurringFeedbackPoll = (overrides: Record<string, unknown> = {}) => ({
        id: 321,
        type: 'feedback',
        target_type: PollTargetTypeEnum.WORKPLACE.value,
        workplace_id: 1,
        title_vi: 'VI',
        title_en: 'EN',
        title_ja: 'JA',
        options: [{ id: 'c1', label_vi: 'A', label_en: 'A', label_ja: 'A' }],
        is_multiple: false,
        is_active: false,
        responses_count: 0,
        is_required: false,
        is_recurring: false,
        expires_at: '2099-12-31',
        ...overrides,
    });

    it('shows the recurring checkbox only for a feedback poll that targets a workplace', async () => {
        renderComponent();
        expect(screen.queryByLabelText('Recurring')).not.toBeInTheDocument();

        await switchToFeedbackType();
        expect(screen.queryByLabelText('Recurring')).not.toBeInTheDocument();

        const scopeInputs = screen.getAllByLabelText(/Scope/i);
        fireEvent.click(scopeInputs[0]);
        fireEvent.click(await screen.findByText('Specific Workplace'));
        expect(await screen.findByLabelText('Recurring')).toBeInTheDocument();

        fireEvent.click(scopeInputs[0]);
        fireEvent.click(await screen.findByText('Specific Users'));
        await waitFor(() => expect(screen.queryByLabelText('Recurring')).not.toBeInTheDocument());
    });

    it('checking recurring forces required and clears the expiry date', async () => {
        const user = userEvent.setup();
        const updateMock = vi.fn().mockResolvedValue({});
        useUpdatePollMutationMock.mockReturnValue({ mutateAsync: updateMock, isPending: false });
        renderComponent(recurringFeedbackPoll());

        await user.click(screen.getByLabelText('Recurring'));

        const required = screen.getByLabelText('Required');
        expect(required).toBeChecked();
        expect(required).toBeDisabled();
        expect(screen.getByLabelText('Expires at')).toBeDisabled();

        await user.click(screen.getByText('Save changes'));

        await waitFor(() => expect(updateMock).toHaveBeenCalled());
        expect(updateMock.mock.calls[0][0]).toMatchObject({
            is_recurring: true,
            is_required: true,
            expires_at: null,
        });
    });

    it('turns recurring off when the poll no longer targets a workplace', async () => {
        const user = userEvent.setup();
        const updateMock = vi.fn().mockResolvedValue({});
        useUpdatePollMutationMock.mockReturnValue({ mutateAsync: updateMock, isPending: false });
        renderComponent(recurringFeedbackPoll({ is_recurring: true, is_required: true, expires_at: null }));

        expect(screen.getByLabelText('Recurring')).toBeChecked();

        const scopeInputs = screen.getAllByLabelText(/Scope/i);
        fireEvent.click(scopeInputs[0]);
        fireEvent.click(await screen.findByText('Global (all workplaces)'));
        await waitFor(() => expect(screen.queryByLabelText('Recurring')).not.toBeInTheDocument());

        await user.click(screen.getByText('Save changes'));

        await waitFor(() => expect(updateMock).toHaveBeenCalled());
        expect(updateMock.mock.calls[0][0]).toMatchObject({ is_recurring: false, target_type: 'global' });
    });
});
