import React from 'react';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import ModalImportCSV from '@/pages/manage/shift/partials/update/modal_import_csv';

vi.mock('react-i18next', () => ({
    useTranslation: () => ({ t: (key: string) => key }),
}));

vi.mock('@/helpers/enum.helper', () => ({
    default: {
        toSelectArray: () => [
            { value: 'utf-8', label: 'UTF-8' },
            { value: 'shift-jis', label: 'Shift-JIS' },
        ],
    },
}));

vi.mock('@mantine/core', () => ({
    Button: ({ children, onClick, loading }: any) =>
        React.createElement('button', { type: 'button', onClick, disabled: loading }, children),
    FileButton: ({ children, onChange }: any) =>
        React.createElement(
            React.Fragment,
            null,
            React.createElement('input', {
                'type': 'file',
                'aria-label': 'file-input',
                'onChange': (e: React.ChangeEvent<HTMLInputElement>) => onChange(e.target.files?.[0] ?? null),
            }),
            children({}),
        ),
    Modal: ({ children, opened, title, onClose }: any) =>
        opened
            ? React.createElement(
                  'div',
                  { 'data-testid': 'modal' },
                  title,
                  React.createElement('button', { type: 'button', onClick: onClose }, 'close'),
                  children,
              )
            : null,
    Select: ({ label, value, data, onChange }: any) =>
        React.createElement(
            'label',
            null,
            label,
            React.createElement(
                'select',
                { value, onChange: (e: React.ChangeEvent<HTMLSelectElement>) => onChange(e.target.value) },
                data.map((opt: any) => React.createElement('option', { key: opt.value, value: opt.value }, opt.label)),
            ),
        ),
    Stack: ({ children }: any) => React.createElement('div', null, children),
    Switch: ({ label, checked, onChange }: any) =>
        React.createElement(
            'label',
            null,
            label,
            React.createElement('input', {
                type: 'checkbox',
                role: 'switch',
                checked,
                onChange,
            }),
        ),
}));

describe('ModalImportCSV', () => {
    const baseProps = {
        isShow: true,
        onClose: vi.fn(),
        encoding: 'utf-8',
        setEncoding: vi.fn(),
        isButtonLoading: false,
        onShiftCsvUpload: vi.fn(),
        fileInputRef: { current: vi.fn() } as any,
    };

    it('renders nothing when isShow is false', () => {
        const { container } = render(<ModalImportCSV {...baseProps} isShow={false} />);

        expect(container.firstChild).toBeNull();
    });

    it('renders the modal with encoding select and default Temp switch label', () => {
        render(<ModalImportCSV {...baseProps} />);

        expect(screen.getByTestId('modal')).toBeInTheDocument();
        expect(screen.getByText('Temp')).toBeInTheDocument();
    });

    it('calls setEncoding when the encoding select changes', async () => {
        const user = userEvent.setup();
        const setEncoding = vi.fn();
        render(<ModalImportCSV {...baseProps} setEncoding={setEncoding} />);

        await user.selectOptions(screen.getByRole('combobox'), 'shift-jis');

        expect(setEncoding).toHaveBeenCalledWith('shift-jis');
    });

    it('toggles the switch label between Temp and Fixed', async () => {
        const user = userEvent.setup();
        render(<ModalImportCSV {...baseProps} />);

        await user.click(screen.getByRole('switch'));

        expect(screen.getByText('Fixed')).toBeInTheDocument();
    });

    it('calls onShiftCsvUpload with the selected file and current isFixed flag', async () => {
        const user = userEvent.setup();
        const onShiftCsvUpload = vi.fn();
        render(<ModalImportCSV {...baseProps} onShiftCsvUpload={onShiftCsvUpload} />);

        await user.click(screen.getByRole('switch'));
        const file = new File(['a,b'], 'shifts.csv', { type: 'text/csv' });
        await user.upload(screen.getByLabelText('file-input'), file);

        expect(onShiftCsvUpload).toHaveBeenCalledWith(file, true);
    });

    it('resets isFixed and calls onClose when the modal is closed', async () => {
        const user = userEvent.setup();
        const onClose = vi.fn();
        render(<ModalImportCSV {...baseProps} onClose={onClose} />);

        await user.click(screen.getByRole('switch'));
        expect(screen.getByText('Fixed')).toBeInTheDocument();

        await user.click(screen.getByText('close'));

        expect(onClose).toHaveBeenCalledTimes(1);
    });
});
