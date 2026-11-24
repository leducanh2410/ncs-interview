import React from 'react';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError } from '@/helpers/api.helper';
import ImportOrderModal from '@/pages/manage/shift/partials/import_order_modal';

const { previewMutateMock, pushMock } = vi.hoisted(() => ({
    previewMutateMock: vi.fn(),
    pushMock: vi.fn(),
}));

vi.mock('react-i18next', () => ({
    useTranslation: () => ({ t: (key: string) => key }),
}));

vi.mock('next/router', () => ({
    useRouter: () => ({ push: pushMock }),
}));

vi.mock('dayjs', () => ({
    default: (value: unknown) => ({ format: () => String(value).slice(0, 7) }),
}));

vi.mock('@mantine/core', () => ({
    Alert: ({ children, title }: any) => React.createElement('div', { role: 'alert' }, title, children),
    Button: ({ children, onClick, loading }: any) =>
        React.createElement('button', { type: 'button', onClick, disabled: loading }, children),
    FileButton: ({ children, onChange }: any) =>
        React.createElement(
            React.Fragment,
            null,
            React.createElement('input', {
                'type': 'file',
                'aria-label': 'file-input',
                'onChange': (event: React.ChangeEvent<HTMLInputElement>) => onChange(event.target.files?.[0] ?? null),
            }),
            children({}),
        ),
    List: Object.assign(({ children }: any) => React.createElement('ul', null, children), {
        Item: ({ children }: any) => React.createElement('li', null, children),
    }),
    Modal: ({ children, opened, title }: any) =>
        opened ? React.createElement('div', { 'data-testid': 'modal' }, title, children) : null,
    Stack: ({ children }: any) => React.createElement('div', null, children),
    Text: ({ children }: any) => React.createElement('span', null, children),
}));

vi.mock('@mantine/dates', () => ({
    MonthPickerInput: ({ label, onChange }: any) =>
        React.createElement(
            'label',
            null,
            label,
            React.createElement('input', {
                'aria-label': 'month-input',
                'onChange': (event: React.ChangeEvent<HTMLInputElement>) => onChange(event.target.value),
            }),
        ),
}));

vi.mock('@/pages/manage/shift/import-order/api', () => ({
    useCreateShiftOrderPreviewMutation: () => ({
        mutateAsync: previewMutateMock,
        isPending: false,
    }),
}));

const selectFile = async () => {
    const file = new File(['content'], 'order.xlsx', {
        type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    });
    await userEvent.upload(screen.getByLabelText('file-input'), file);
};

describe('Shift order import modal', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('sends the file and opens the preview page of the created batch', async () => {
        previewMutateMock.mockResolvedValue({ data: { batch: { id: 12 } } });

        render(<ImportOrderModal isShow onClose={vi.fn()} />);
        await selectFile();

        expect(previewMutateMock).toHaveBeenCalledTimes(1);
        const formData = previewMutateMock.mock.calls[0][0] as FormData;
        expect((formData.get('file') as File).name).toBe('order.xlsx');
        expect(formData.get('month')).toBeNull();
        expect(pushMock).toHaveBeenCalledWith('/manage/shift/import-order/12');
    });

    it('sends the chosen month when the admin overrides it', async () => {
        previewMutateMock.mockResolvedValue({ data: { batch: { id: 3 } } });

        render(<ImportOrderModal isShow onClose={vi.fn()} />);
        await userEvent.type(screen.getByLabelText('month-input'), '2026-11-01');
        await selectFile();

        const formData = previewMutateMock.mock.calls[0][0] as FormData;
        expect(formData.get('month')).toBe('2026-11');
    });

    it('lists the validation errors returned by the server', async () => {
        previewMutateMock.mockRejectedValue(
            new ApiError({
                status: 422,
                message: 'SHIFT_ORDER_FILE_HAS_ERRORS',
                data: { errors: [{ code: 'workplace_not_found', cell: 'C3', value: '999' }] },
            }),
        );

        render(<ImportOrderModal isShow onClose={vi.fn()} />);
        await selectFile();

        expect(screen.getByRole('alert')).toHaveTextContent(
            'Cell {{cell}}: workplace ID {{value}} does not exist in the system',
        );
        expect(pushMock).not.toHaveBeenCalled();
    });
});
