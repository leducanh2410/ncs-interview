import React from 'react';
import { MantineProvider } from '@mantine/core';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import CommonSearchBox from '@/components/CommonSearchBox';

const { pushMock } = vi.hoisted(() => ({ pushMock: vi.fn() }));

vi.mock('next/router', () => ({
    useRouter: () => ({ query: {}, push: pushMock }),
}));

vi.mock('react-i18next', () => ({
    initReactI18next: { type: '3rdParty', init: () => undefined },
    useTranslation: () => ({ t: (key: string) => key }),
}));

const workplaceOptions = [
    { value: '1', label: 'Tokyo' },
    { value: '2', label: 'Osaka' },
];

const renderComponent = (isSearchable: boolean) =>
    render(
        <MantineProvider>
            <CommonSearchBox
                initialValues={{ workplace_id: '0' }}
                elements={[
                    {
                        id: 'workplace_id',
                        type: 'select',
                        label: 'Workplace',
                        name: 'workplace_id',
                        options: workplaceOptions,
                        isClearable: false,
                        isSearchable,
                        showAll: true,
                        allValue: '0',
                    },
                ]}
            />
        </MantineProvider>,
    );

describe('CommonSearchBox', () => {
    // jsdom never resolves the dropdown's floating-ui position, so it keeps an
    // inline `display: none` even while open; query with `hidden: true` to read
    // the real (accessibility-tree-hidden) option list instead of asserting paint.
    it('filters the workplace options as the user types when isSearchable is true', async () => {
        const user = userEvent.setup();
        renderComponent(true);

        const input = screen.getByRole('combobox', { name: 'Workplace' });
        await user.click(input);

        expect(screen.getByRole('option', { name: 'Tokyo', hidden: true })).toBeInTheDocument();
        expect(screen.getByRole('option', { name: 'Osaka', hidden: true })).toBeInTheDocument();

        await user.clear(input);
        await user.type(input, 'Osa');

        expect(screen.queryByRole('option', { name: 'Tokyo', hidden: true })).not.toBeInTheDocument();
        expect(screen.getByRole('option', { name: 'Osaka', hidden: true })).toBeInTheDocument();
    });

    it('does not let the user type a filter into the workplace field when isSearchable is false', () => {
        renderComponent(false);

        const input = screen.getByRole('combobox', { name: 'Workplace' });

        expect(input).toHaveAttribute('readonly');
    });

    describe('visibleWhen', () => {
        const renderDependent = () =>
            render(
                <MantineProvider>
                    <CommonSearchBox
                        initialValues={{ type: '', template_id: null }}
                        elements={[
                            {
                                id: 'type',
                                type: 'select',
                                label: 'Type',
                                name: 'type',
                                options: [
                                    { value: 'pdf', label: 'PDF' },
                                    { value: 'csv', label: 'CSV' },
                                ],
                                isSearchable: false,
                            },
                            {
                                id: 'template_id',
                                type: 'select',
                                label: 'Template',
                                name: 'template_id',
                                options: [{ value: '7', label: 'Contract' }],
                                isSearchable: false,
                                visibleWhen: (values) => !values.type || values.type === 'pdf',
                            },
                        ]}
                    />
                </MantineProvider>,
            );

        it('hides and shows the dependent field as soon as the controlling value changes', async () => {
            const user = userEvent.setup();
            renderDependent();

            expect(screen.getByRole('combobox', { name: 'Template' })).toBeInTheDocument();

            await user.click(screen.getByRole('combobox', { name: 'Type' }));
            await user.click(screen.getByRole('option', { name: 'CSV', hidden: true }));
            expect(screen.queryByRole('combobox', { name: 'Template' })).not.toBeInTheDocument();

            await user.click(screen.getByRole('combobox', { name: 'Type' }));
            await user.click(screen.getByRole('option', { name: 'PDF', hidden: true }));
            expect(screen.getByRole('combobox', { name: 'Template' })).toBeInTheDocument();
        });

        it('drops the hidden field value from the submitted query', async () => {
            const user = userEvent.setup();
            pushMock.mockClear();
            renderDependent();

            await user.click(screen.getByRole('combobox', { name: 'Template' }));
            await user.click(screen.getByRole('option', { name: 'Contract', hidden: true }));
            await user.click(screen.getByRole('combobox', { name: 'Type' }));
            await user.click(screen.getByRole('option', { name: 'CSV', hidden: true }));
            await user.click(screen.getByRole('button', { name: 'Search' }));

            expect(pushMock).toHaveBeenCalledTimes(1);
            const { query } = pushMock.mock.calls[0][0];
            expect(query.type).toBe('csv');
            expect(query).not.toHaveProperty('template_id');
        });
    });
});
