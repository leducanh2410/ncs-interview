import React from 'react';
import { MantineProvider } from '@mantine/core';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { AnnouncementWithRelation } from '@/types/models/announcement';
import { ApiError, ApiValidationError } from '@/helpers/api.helper';
import AnnouncementCreateUpdate from './announcement_create_update';

const { translateMutateAsyncMock, createMutateAsyncMock, updateMutateAsyncMock, showSuccessMock, showErrorMock } =
    vi.hoisted(() => ({
        translateMutateAsyncMock: vi.fn(),
        createMutateAsyncMock: vi.fn(),
        updateMutateAsyncMock: vi.fn(),
        showSuccessMock: vi.fn(),
        showErrorMock: vi.fn(),
    }));

vi.mock('@/helpers/notification.helper', () => ({
    default: {
        showSuccess: (...args: unknown[]) => showSuccessMock(...args),
        showError: (...args: unknown[]) => showErrorMock(...args),
    },
}));

vi.mock('@/pages/manage/announcement/api', () => ({
    useCreateAnnouncementMutation: () => ({ mutateAsync: createMutateAsyncMock, isPending: false }),
    useUpdateAnnouncementMutation: () => ({ mutateAsync: updateMutateAsyncMock, isPending: false }),
}));

vi.mock('@/pages/translate.api', () => ({
    useTranslateContentMutation: () => ({ mutateAsync: translateMutateAsyncMock, isPending: false }),
}));

vi.mock('jotai', () => ({
    useAtom: () => [{ workplaces: [{ id: 3, name: 'Xưởng Kawasaki' }] }],
}));

vi.mock('@/pages/global.state', () => ({
    masterAtom: { init: { workplaces: [] } },
}));

vi.mock('@/pages/manage/announcement/partials/AnnouncementContentEditor', () => ({
    __esModule: true,
    default: ({ label, value, onChange, error }: any) =>
        React.createElement('label', null, [
            label,
            React.createElement('textarea', {
                key: 'input',
                value,
                onChange: (event: React.ChangeEvent<HTMLTextAreaElement>) => onChange(event.target.value),
            }),
            error ? React.createElement('span', { key: 'error' }, error) : null,
        ]),
}));

vi.mock('@mantine/dates', () => ({
    DateTimePicker: ({ label, value, onChange, error }: any) =>
        React.createElement('label', null, [
            label,
            React.createElement('input', {
                key: 'input',
                value: value ?? '',
                onChange: (event: React.ChangeEvent<HTMLInputElement>) => onChange(event.target.value),
            }),
            error ? React.createElement('span', { key: 'error' }, error) : null,
        ]),
}));

vi.mock('@mantine/dropzone', () => {
    const Dropzone = ({ onDrop }: any) =>
        React.createElement('input', {
            'type': 'file',
            'data-testid': 'dropzone',
            'onChange': (event: React.ChangeEvent<HTMLInputElement>) => onDrop(Array.from(event.target.files ?? [])),
        });
    Dropzone.Accept = () => null;
    Dropzone.Reject = () => null;
    Dropzone.Idle = () => null;
    return { Dropzone };
});

vi.mock('react-i18next', () => ({
    initReactI18next: { type: '3rdParty', init: () => undefined },
    useTranslation: () => ({
        t: (key: string, opts?: Record<string, unknown>) =>
            opts ? key.replace(/{{(\w+)}}/g, (_, k) => String(opts[k] ?? '')) : key,
    }),
}));

vi.mock('@/locales/i18n', () => ({
    default: {
        t: (key: string, opts?: Record<string, unknown>) =>
            opts ? key.replace(/{{(\w+)}}/g, (_, k) => String(opts[k] ?? '')) : key,
    },
}));

const makeAnnouncement = (overrides: Partial<AnnouncementWithRelation> = {}): AnnouncementWithRelation => ({
    id: 7,
    title: 'Bảo trì dây chuyền A',
    content: '<p>Từ 10:00</p>',
    image_path: 'announcements/7/a.jpg',
    image_url: 'https://cdn/announcements/7/a.jpg',
    start_at: '2026-10-01 08:00:00',
    end_at: '2026-10-03 18:00:00',
    is_active: true,
    state: 'scheduled',
    created_by: 1,
    created_at: '2026-09-30 00:00:00',
    updated_at: '2026-09-30 00:00:00',
    workplaces: [{ id: 3, name: 'Xưởng Kawasaki' }],
    translations: [
        { id: 1, announcement_id: 7, lang: 'en', title: 'Line A maintenance', content: '<p>From 10:00</p>' },
    ],
    ...overrides,
});

const renderComponent = (announcement: AnnouncementWithRelation | null, onClose = vi.fn()) => {
    return render(
        <MantineProvider env="test">
            <AnnouncementCreateUpdate announcement={announcement} opened onClose={onClose} />
        </MantineProvider>,
    );
};

const formDataEntries = (formData: FormData): Record<string, FormDataEntryValue[]> => {
    const entries: Record<string, FormDataEntryValue[]> = {};
    formData.forEach((value, key) => {
        entries[key] = [...(entries[key] ?? []), value];
    });
    return entries;
};

describe('AnnouncementCreateUpdate', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        createMutateAsyncMock.mockResolvedValue(undefined);
        updateMutateAsyncMock.mockResolvedValue(undefined);
        (globalThis as any).URL.createObjectURL = vi.fn(() => 'blob:preview');
        (globalThis as any).URL.revokeObjectURL = vi.fn();
    });

    it('blocks submit when the form is empty and shows the errors inline', async () => {
        renderComponent(null);

        await userEvent.click(screen.getByRole('button', { name: 'Save' }));

        expect(createMutateAsyncMock).not.toHaveBeenCalled();
        expect(screen.getByText('validation|Please enter field|title')).toBeInTheDocument();
        expect(screen.getByText('validation|Please enter field|content')).toBeInTheDocument();
        expect(screen.getByText('validation|Please select at least 1 items')).toBeInTheDocument();
        expect(screen.getByText('validation|Please select field|start_at')).toBeInTheDocument();
        expect(screen.getByText('validation|Please select field|end_at')).toBeInTheDocument();
    });

    it('rejects an editor that only holds an empty paragraph', async () => {
        renderComponent(makeAnnouncement());

        const contentEditor = screen.getByLabelText(/^Content \(enum\|Vietnamese\)/) as HTMLTextAreaElement;
        fireEvent.change(contentEditor, { target: { value: '<p></p>' } });

        await userEvent.click(screen.getByRole('button', { name: 'Save' }));

        expect(updateMutateAsyncMock).not.toHaveBeenCalled();
        expect(screen.getByText('validation|Please enter field|content')).toBeInTheDocument();
    });

    it('rejects an end time that is not after the start time', async () => {
        renderComponent(makeAnnouncement());

        const endInput = screen.getByLabelText(/^End time/) as HTMLInputElement;
        fireEvent.change(endInput, { target: { value: '2026-10-01 08:00:00' } });

        await userEvent.click(screen.getByRole('button', { name: 'Save' }));

        expect(updateMutateAsyncMock).not.toHaveBeenCalled();
        expect(screen.getByText('validation|End time must be after start time')).toBeInTheDocument();
    });

    it('submits multipart data with workplace_ids[], translations and method spoofing on update', async () => {
        renderComponent(makeAnnouncement());

        await userEvent.click(screen.getByRole('button', { name: 'Save' }));

        await waitFor(() => expect(updateMutateAsyncMock).toHaveBeenCalledTimes(1));
        const { id, data } = updateMutateAsyncMock.mock.calls[0][0];
        expect(id).toBe('7');
        const entries = formDataEntries(data);
        expect(entries.title).toEqual(['Bảo trì dây chuyền A']);
        expect(entries.content).toEqual(['<p>Từ 10:00</p>']);
        expect(entries['workplace_ids[]']).toEqual(['3']);
        expect(entries.start_at).toEqual(['2026-10-01 08:00:00']);
        expect(entries.end_at).toEqual(['2026-10-03 18:00:00']);
        expect(entries.is_active).toEqual(['1']);
        expect(entries.title_en).toEqual(['Line A maintenance']);
        expect(entries.content_en).toEqual(['<p>From 10:00</p>']);
        expect(entries._method).toEqual(['PUT']);
        expect(entries).not.toHaveProperty('title_ja');
        expect(entries).not.toHaveProperty('remove_image');
        expect(entries).not.toHaveProperty('image');
    });

    it('sends remove_image when the existing image is cleared', async () => {
        renderComponent(makeAnnouncement());

        await userEvent.click(screen.getByRole('button', { name: 'Remove image' }));
        await userEvent.click(screen.getByRole('button', { name: 'Save' }));

        await waitFor(() => expect(updateMutateAsyncMock).toHaveBeenCalledTimes(1));
        const entries = formDataEntries(updateMutateAsyncMock.mock.calls[0][0].data);
        expect(entries.remove_image).toEqual(['1']);
        expect(entries).not.toHaveProperty('image');
    });

    it('sends the picked image and omits _method on create', async () => {
        renderComponent(null);

        const file = new File(['img'], 'poster.png', { type: 'image/png' });
        fireEvent.change(screen.getByTestId('dropzone'), { target: { files: [file] } });

        fireEvent.change(screen.getByLabelText(/^Title \(enum\|Vietnamese\)/), { target: { value: 'Tiêu đề' } });
        fireEvent.change(screen.getByLabelText(/^Content \(enum\|Vietnamese\)/), {
            target: { value: '<p>Nội dung</p>' },
        });
        fireEvent.change(screen.getByLabelText(/^Start time/), { target: { value: '2026-10-01 08:00:00' } });
        fireEvent.change(screen.getByLabelText(/^End time/), { target: { value: '2026-10-02 08:00:00' } });

        await userEvent.click(screen.getAllByLabelText(/^Workplace/)[0]);
        await userEvent.click(await screen.findByRole('option', { name: 'Xưởng Kawasaki' }));

        await userEvent.click(screen.getByRole('button', { name: 'Save' }));

        await waitFor(() => expect(createMutateAsyncMock).toHaveBeenCalledTimes(1));
        const entries = formDataEntries(createMutateAsyncMock.mock.calls[0][0]);
        expect(entries['workplace_ids[]']).toEqual(['3']);
        expect(entries.image?.[0]).toBeInstanceOf(File);
        expect((entries.image?.[0] as File).name).toBe('poster.png');
        expect(entries).not.toHaveProperty('_method');
        expect(entries).not.toHaveProperty('remove_image');
    });

    it('shows field errors and no toast when the server rejects the data', async () => {
        const onClose = vi.fn();
        updateMutateAsyncMock.mockRejectedValue(
            new ApiValidationError({ status: 422, message: 'Unprocessable', data: { title: ['Please enter field'] } }),
        );
        renderComponent(makeAnnouncement(), onClose);

        await userEvent.click(screen.getByRole('button', { name: 'Save' }));

        expect(await screen.findByText('validation|Please enter field')).toBeInTheDocument();
        expect(showErrorMock).not.toHaveBeenCalled();
        expect(showSuccessMock).not.toHaveBeenCalled();
        expect(onClose).not.toHaveBeenCalled();
    });

    it('shows an error toast and keeps the form open when the server fails on update', async () => {
        const onClose = vi.fn();
        updateMutateAsyncMock.mockRejectedValue(new ApiError({ status: 500, message: 'Server Error', data: {} }));
        renderComponent(makeAnnouncement(), onClose);

        await userEvent.click(screen.getByRole('button', { name: 'Save' }));

        await waitFor(() => expect(showErrorMock).toHaveBeenCalledWith('error|Could not update announcement'));
        expect(showSuccessMock).not.toHaveBeenCalled();
        expect(onClose).not.toHaveBeenCalled();
        expect(screen.getByDisplayValue('Bảo trì dây chuyền A')).toBeInTheDocument();
    });

    it('shows an error toast when the network fails on create', async () => {
        const onClose = vi.fn();
        createMutateAsyncMock.mockRejectedValue(new TypeError('Failed to fetch'));
        renderComponent(null, onClose);

        fireEvent.change(screen.getByLabelText(/^Title \(enum\|Vietnamese\)/), { target: { value: 'Tiêu đề' } });
        fireEvent.change(screen.getByLabelText(/^Content \(enum\|Vietnamese\)/), {
            target: { value: '<p>Nội dung</p>' },
        });
        fireEvent.change(screen.getByLabelText(/^Start time/), { target: { value: '2026-10-01 08:00:00' } });
        fireEvent.change(screen.getByLabelText(/^End time/), { target: { value: '2026-10-02 08:00:00' } });
        await userEvent.click(screen.getAllByLabelText(/^Workplace/)[0]);
        await userEvent.click(await screen.findByRole('option', { name: 'Xưởng Kawasaki' }));

        await userEvent.click(screen.getByRole('button', { name: 'Save' }));

        await waitFor(() => expect(showErrorMock).toHaveBeenCalledWith('error|Could not create announcement'));
        expect(showSuccessMock).not.toHaveBeenCalled();
        expect(onClose).not.toHaveBeenCalled();
    });

    it('asks the translate API with the announcement context and fills only empty locales', async () => {
        translateMutateAsyncMock.mockResolvedValue({
            data: {
                translations: {
                    ja: { title: 'JAタイトル', content: '<p>JA内容</p>' },
                    ne: { title: 'NE शीर्षक', content: '<p>NE सामग्री</p>' },
                    si: { title: 'SI මාතෘකාව', content: '<p>SI අන්තර්ගතය</p>' },
                },
            },
        });

        renderComponent(makeAnnouncement());

        fireEvent.click(screen.getByRole('button', { name: /AI translate/ }));

        await waitFor(() => expect(translateMutateAsyncMock).toHaveBeenCalledTimes(1));
        expect(translateMutateAsyncMock).toHaveBeenCalledWith({
            items: [
                { key: 'title', text: 'Bảo trì dây chuyền A' },
                { key: 'content', text: '<p>Từ 10:00</p>' },
            ],
            source_locale: 'vi',
            target_locales: ['ja', 'ne', 'si'],
            context: 'announcement',
        });

        expect(await screen.findByDisplayValue('JAタイトル')).toBeInTheDocument();
        expect(screen.getByDisplayValue('<p>JA内容</p>')).toBeInTheDocument();
        expect(screen.getByDisplayValue('Line A maintenance')).toBeInTheDocument();
    });
});
