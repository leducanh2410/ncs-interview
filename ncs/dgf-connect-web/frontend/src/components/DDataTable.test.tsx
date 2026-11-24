import React from 'react';
import { MantineProvider } from '@mantine/core';
import { render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import DDataTable, { headerWrapClassName } from '@/components/DDataTable';

vi.mock('react-i18next', () => ({
    initReactI18next: { type: '3rdParty', init: () => undefined },
    useTranslation: () => ({ t: (key: string) => key }),
}));

/**
 * The global test setup stubs matchMedia to always report no match, which mantine-datatable
 * reads (via its own visibleMediaQuery check) to decide whether to render a column at all -
 * under the global stub every column renders as hidden. Report a match here so columns render.
 */
Object.defineProperty(window, 'matchMedia', {
    writable: true,
    value: vi.fn().mockImplementation((query: string) => ({
        matches: true,
        media: query,
        onchange: null,
        addListener: vi.fn(),
        removeListener: vi.fn(),
        addEventListener: vi.fn(),
        removeEventListener: vi.fn(),
        dispatchEvent: vi.fn(),
    })),
});

type Row = { id: number; name: string };

describe('DDataTable header wrap', () => {
    /**
     * DDataTable.module.css targets mantine-datatable's internal
     * `mantine-datatable-header-cell-sortable-text` class to override the library's
     * default header-text truncation - that class isn't part of the library's public
     * API, so a future mantine-datatable upgrade could rename it and silently break
     * the wrap styling. This renders the real library (not mocked, unlike
     * notification/index.page.test.tsx) so such a rename fails this test instead.
     */
    it('applies titleClassName to an ancestor of the header-text node the CSS targets', async () => {
        render(
            <MantineProvider>
                <DDataTable<Row>
                    columns={[{ name: 'Received', key: 'name', titleClassName: headerWrapClassName }]}
                    data={[{ id: 1, name: 'foo' }]}
                    isFetching={false}
                />
            </MantineProvider>,
        );

        const headerTextNode = (await screen.findByText('Received')).closest(
            '.mantine-datatable-header-cell-sortable-text',
        );
        expect(headerTextNode).not.toBeNull();
        expect(headerTextNode?.closest(`.${headerWrapClassName}`)).not.toBeNull();
    });
});

describe('DDataTable stored column widths', () => {
    const storage = new Map<string, string>();

    beforeEach(() => {
        storage.clear();
        vi.mocked(localStorage.getItem).mockImplementation((key: string) => storage.get(key) ?? null);
        vi.mocked(localStorage.setItem).mockImplementation((key: string, value: string) => {
            storage.set(key, value);
        });
    });

    afterEach(() => {
        vi.mocked(localStorage.getItem).mockReset();
        vi.mocked(localStorage.setItem).mockReset();
    });

    it('replaces widths stored by an earlier version with the current definitions and keeps hidden columns hidden', async () => {
        localStorage.setItem('stale-table-columns-width', JSON.stringify([{ name: 180 }, { note: 90 }]));
        localStorage.setItem(
            'stale-table-columns-toggle',
            JSON.stringify([
                { accessor: 'name', defaultToggle: true, toggleable: true, toggled: true },
                { accessor: 'note', defaultToggle: true, toggleable: true, toggled: false },
            ]),
        );

        render(
            <MantineProvider>
                <DDataTable<Row & { note: string }>
                    tableKey="stale-table"
                    columns={[
                        { name: 'Name', key: 'name', width: 110, toggleable: true },
                        { name: 'Note', key: 'note', width: 90, toggleable: true },
                    ]}
                    data={[{ id: 1, name: 'foo', note: 'bar' }]}
                    isFetching={false}
                />
            </MantineProvider>,
        );

        await waitFor(() =>
            expect(JSON.parse(localStorage.getItem('stale-table-columns-width') ?? '[]')).toEqual([
                { name: 110 },
                { note: 90 },
            ]),
        );
        expect(screen.queryByText('Note')).not.toBeInTheDocument();
    });
});
