import React from 'react';
import { MantineProvider } from '@mantine/core';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { NotificationWithRelation } from '@/types/models/notification';
import DateHelper from '@/helpers/date.helper';
import NotificationCreateUpdate from './notification_create_update';

// _app.page.tsx registers the dayjs utc/timezone plugins; this component is
// rendered without it, and its useEffect needs DateHelper.parse() to work.
DateHelper.initDayjs();

const {
    translateMutateAsyncMock,
    useCreateNotificationMutationMock,
    useUpdateNotificationMutationMock,
    useGetActiveShiftsQueryMock,
} = vi.hoisted(() => ({
    translateMutateAsyncMock: vi.fn(),
    useCreateNotificationMutationMock: vi.fn(),
    useUpdateNotificationMutationMock: vi.fn(),
    useGetActiveShiftsQueryMock: vi.fn(),
}));

vi.mock('@/pages/manage/notification/api', () => ({
    useCreateNotificationMutation: () => useCreateNotificationMutationMock(),
    useUpdateNotificationMutation: () => useUpdateNotificationMutationMock(),
}));

vi.mock('@/pages/manage/shift/api', () => ({
    useGetActiveShiftsQuery: () => useGetActiveShiftsQueryMock(),
}));

vi.mock('@/pages/translate.api', () => ({
    useTranslateContentMutation: () => ({ mutateAsync: translateMutateAsyncMock, isPending: false }),
}));

vi.mock('jotai', () => ({
    useAtom: () => [
        {
            workplaces: [
                { id: 11, name: 'Shop A' },
                { id: 12, name: 'Shop B' },
            ],
        },
    ],
}));

vi.mock('@/pages/global.state', () => ({
    masterAtom: { init: { workplaces: [] } },
}));

// A minimal interpolator so distinctly-labelled fields (e.g. "Title ({{lang}})" per locale)
// stay distinguishable in the DOM, unlike a plain key passthrough.
vi.mock('react-i18next', () => ({
    initReactI18next: { type: '3rdParty', init: () => undefined },
    useTranslation: () => ({
        t: (key: string, opts?: Record<string, unknown>) =>
            opts ? key.replace(/{{(\w+)}}/g, (_, k) => String(opts[k] ?? '')) : key,
    }),
}));

vi.mock('@/locales/i18n', () => ({
    default: { t: (key: string) => key },
}));

const baseTranslations = [
    { id: 1, notification_id: 1, lang: 'en', title: 'English title', content: 'English content', is_fallback: false },
    // Filler the backend generated for a locale the admin never filled in — content copied
    // from the base (Vietnamese) fields, not a real translation.
    { id: 2, notification_id: 1, lang: 'ne', title: 'Base title', content: 'Base content', is_fallback: true },
];

const makeNotification = (overrides: Partial<NotificationWithRelation> = {}): NotificationWithRelation =>
    ({
        id: 1,
        type: 'normal',
        title: 'Base title',
        content: 'Base content',
        status: 'pending',
        icon: 'announcement',
        url: null,
        is_manually_scheduled: 0,
        scheduled_at: '2026-08-05 13:08:21',
        processed_at: null,
        data: null,
        recipients_resolved_count: null,
        target_type: 'all',
        workplaces: [],
        shift: null,
        translations: baseTranslations,
        ...overrides,
    }) as unknown as NotificationWithRelation;

const renderComponent = (notification: NotificationWithRelation | null, onClose = vi.fn()) => {
    return render(
        <MantineProvider env="test">
            <NotificationCreateUpdate notification={notification} opened onClose={onClose} />
        </MantineProvider>,
    );
};

describe('NotificationCreateUpdate', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        translateMutateAsyncMock.mockReset();
        useGetActiveShiftsQueryMock.mockReturnValue({ data: { data: [] }, isLoading: false });
        useCreateNotificationMutationMock.mockReturnValue({
            mutateAsync: vi.fn().mockResolvedValue(undefined),
            isPending: false,
        });
        useUpdateNotificationMutationMock.mockReturnValue({
            mutateAsync: vi.fn().mockResolvedValue(undefined),
            isPending: false,
        });
    });

    it('loads only the genuine translation into the form, skipping the fallback row', () => {
        renderComponent(makeNotification());

        const englishCheckbox = screen.getByLabelText('Translate to enum|English') as HTMLInputElement;
        const nepaliCheckbox = screen.getByLabelText('Translate to enum|Nepali') as HTMLInputElement;

        expect(englishCheckbox.checked).toBe(true);
        expect(nepaliCheckbox.checked).toBe(false);

        // withAsterisk appends a visible " *" to the label text, so match by prefix rather
        // than the exact string (same pattern as poll_form.test.tsx).
        expect(screen.getByRole('textbox', { name: /^Title \(enum\|English\)/ })).toHaveValue('English title');
        expect(screen.getByRole('textbox', { name: /^Content \(enum\|English\)/ })).toHaveValue('English content');
        // Nepali's fields only render once its checkbox is ticked; the fallback row must not
        // have ticked it, or the filler content would be silently editable/resubmittable.
        expect(screen.queryByRole('textbox', { name: /^Title \(enum\|Nepali\)/ })).not.toBeInTheDocument();
        expect(screen.queryByRole('textbox', { name: /^Content \(enum\|Nepali\)/ })).not.toBeInTheDocument();
    });

    it('does not resubmit the fallback content when saving an edited notification', async () => {
        const updateMock = vi.fn().mockResolvedValue(undefined);
        useUpdateNotificationMutationMock.mockReturnValue({ mutateAsync: updateMock, isPending: false });

        renderComponent(makeNotification());

        await userEvent.click(screen.getByRole('button', { name: 'Save' }));

        await waitFor(() => expect(updateMock).toHaveBeenCalled());

        const payload = updateMock.mock.calls[0][0].data;
        expect(payload.title_en).toBe('English title');
        expect(payload.content_en).toBe('English content');
        // Regenerating the Nepali row as a genuine translation from stale filler content is
        // exactly the language-mismatch bug this form guards against.
        expect(payload).not.toHaveProperty('title_ne');
        expect(payload).not.toHaveProperty('content_ne');
        expect(payload).not.toHaveProperty('translated_ne');
    });

    it('requests only locales with empty fields and fills them without touching manual input', async () => {
        translateMutateAsyncMock.mockResolvedValue({
            data: {
                translations: {
                    ja: { title: 'JAタイトル', content: 'JA内容' },
                    ne: { title: 'NE शीर्षक', content: 'NE सामग्री' },
                    si: { title: 'SI මාතෘකාව', content: 'SI අන්තර්ගතය' },
                },
            },
        });

        renderComponent(
            makeNotification({
                title: 'Tiêu đề',
                content: 'Nội dung thông báo',
                translations: [
                    {
                        id: 1,
                        notification_id: 1,
                        lang: 'en',
                        title: 'Manual EN title',
                        content: 'Manual EN content',
                        is_fallback: false,
                    },
                ],
            }),
        );

        fireEvent.click(screen.getByRole('button', { name: /AI translate/ }));

        await waitFor(() => expect(translateMutateAsyncMock).toHaveBeenCalledTimes(1));
        expect(translateMutateAsyncMock).toHaveBeenCalledWith({
            items: [
                { key: 'title', text: 'Tiêu đề' },
                { key: 'content', text: 'Nội dung thông báo' },
            ],
            source_locale: 'vi',
            // en already has manual title + content, so it must not be requested again
            target_locales: ['ja', 'ne', 'si'],
            context: 'notification',
        });

        // Translated fields are revealed (checkbox toggled on) and pre-filled.
        expect(await screen.findByDisplayValue('JAタイトル')).toBeDefined();
        expect(screen.getByDisplayValue('NE शीर्षक')).toBeDefined();
        expect(screen.getByDisplayValue('SI මාතෘකාව')).toBeDefined();

        // Manual English input is untouched.
        expect(screen.getByDisplayValue('Manual EN title')).toBeDefined();
        expect(screen.getByDisplayValue('Manual EN content')).toBeDefined();
    });

    it('skips the API call when every locale is already filled in', async () => {
        renderComponent(
            makeNotification({
                translations: ['en', 'ja', 'ne', 'si'].map((lang, index) => ({
                    id: index + 1,
                    notification_id: 1,
                    lang,
                    title: `${lang} title`,
                    content: `${lang} content`,
                    is_fallback: false,
                })),
            }),
        );

        fireEvent.click(screen.getByRole('button', { name: /AI translate/ }));

        await waitFor(() => expect(translateMutateAsyncMock).not.toHaveBeenCalled());
    });

    it('does not enable a locale when the response cannot fill both required fields', async () => {
        translateMutateAsyncMock.mockResolvedValue({
            data: { translations: { ja: { title: 'JA title' } } },
        });

        renderComponent(
            makeNotification({
                translations: [
                    {
                        id: 1,
                        notification_id: 1,
                        lang: 'en',
                        title: 'Manual EN title',
                        content: 'Manual EN content',
                        is_fallback: false,
                    },
                ],
            }),
        );

        fireEvent.click(screen.getByRole('button', { name: /AI translate/ }));

        await waitFor(() => expect(translateMutateAsyncMock).toHaveBeenCalledTimes(1));
        expect(screen.getByRole('checkbox', { name: /Translate to enum\|Japanese/ })).not.toBeChecked();
        expect(screen.queryByDisplayValue('JA title')).toBeNull();
    });

    it('shows the target selector for a normal notification and defaults to all employees', () => {
        renderComponent(null);

        expect(screen.getByText('Target')).toBeInTheDocument();
        expect(screen.getByRole('radio', { name: 'All employees' })).toBeChecked();
        expect(screen.queryByLabelText(/^Workplace/)).not.toBeInTheDocument();
    });

    it('requires at least one workplace when selected workplaces is chosen', async () => {
        const createMock = vi.fn().mockResolvedValue(undefined);
        useCreateNotificationMutationMock.mockReturnValue({ mutateAsync: createMock, isPending: false });

        renderComponent(null);

        await userEvent.type(screen.getByRole('textbox', { name: /^Title \(enum\|Vietnamese\)/ }), 'Tiêu đề');
        await userEvent.type(screen.getByRole('textbox', { name: /^Content \(enum\|Vietnamese\)/ }), 'Nội dung');
        await userEvent.click(screen.getByRole('radio', { name: 'Selected workplaces' }));
        await userEvent.click(screen.getByRole('button', { name: 'Save' }));

        expect(await screen.findByText('validation|Please select at least {{min}} items')).toBeInTheDocument();
        expect(createMock).not.toHaveBeenCalled();
    });

    it('sends the selected workplaces with the workplace target', async () => {
        const createMock = vi.fn().mockResolvedValue(undefined);
        useCreateNotificationMutationMock.mockReturnValue({ mutateAsync: createMock, isPending: false });

        renderComponent(null);

        await userEvent.type(screen.getByRole('textbox', { name: /^Title \(enum\|Vietnamese\)/ }), 'Tiêu đề');
        await userEvent.type(screen.getByRole('textbox', { name: /^Content \(enum\|Vietnamese\)/ }), 'Nội dung');
        await userEvent.click(screen.getByRole('radio', { name: 'Selected workplaces' }));
        await userEvent.click(screen.getAllByLabelText(/^Workplace/)[0]);
        await userEvent.click(await screen.findByRole('option', { name: 'Shop B' }));
        await userEvent.click(screen.getByRole('button', { name: 'Save' }));

        await waitFor(() => expect(createMock).toHaveBeenCalled());
        const payload = createMock.mock.calls[0][0];
        expect(payload.target_type).toBe('workplace');
        expect(payload.workplace_ids).toEqual(['12']);
    });

    it('pre-fills the selected workplaces when editing a workplace-targeted notification', () => {
        renderComponent(
            makeNotification({
                target_type: 'workplace',
                workplaces: [{ id: 11, name: 'Shop A' }],
            }),
        );

        expect(screen.getByRole('radio', { name: 'Selected workplaces' })).toBeChecked();
        expect(screen.getAllByText('Shop A').length).toBeGreaterThan(0);
    });

    it('hides the link field and keeps the target selector for a bank account request', () => {
        renderComponent(makeNotification({ type: 'bank_account_required' }));

        expect(screen.queryByRole('textbox', { name: /^Link/ })).not.toBeInTheDocument();
        expect(screen.getByText('Target')).toBeInTheDocument();
        expect(
            screen.getByText('Tapping this notification opens the salary payout details screen in the app.'),
        ).toBeInTheDocument();
    });

    it('hides the target selector for a recruitment notification', () => {
        renderComponent(makeNotification({ type: 'recruitment' }));

        expect(screen.queryByText('Target')).not.toBeInTheDocument();
        expect(screen.queryByRole('radio', { name: 'All employees' })).not.toBeInTheDocument();
    });
});
