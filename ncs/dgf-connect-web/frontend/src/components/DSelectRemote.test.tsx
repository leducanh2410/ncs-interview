import React, { useState } from 'react';
import { ComboboxItem, MantineProvider } from '@mantine/core';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { ParsedUrlQuery } from 'querystring';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import DSelectRemote from '@/components/DSelectRemote';

const apiGetMock = vi.hoisted(() => vi.fn());

vi.mock('@/helpers/api.helper', () => ({
    default: { get: apiGetMock },
}));

type Employee = { id: number; name: string };

const renderItem = (employee: Employee): ComboboxItem => ({
    value: employee.id.toString(),
    label: employee.name,
});

/**
 * Mirrors how the screens use it: a controlled value owned by the parent form
 * (`CommonSearchBox` passes `form.getInputProps`).
 */
const ControlledSelect = ({
    initialValue = null,
    extraParams,
}: {
    initialValue?: string | null;
    extraParams?: ParsedUrlQuery;
}) => {
    const [value, setValue] = useState<string | null>(initialValue);

    return (
        <MantineProvider>
            <DSelectRemote<Employee>
                label="Admin"
                uri="admin/master/users/search"
                renderItem={renderItem}
                value={value}
                onChange={setValue}
                extraParams={extraParams}
            />
        </MantineProvider>
    );
};

const searchInput = () => screen.getByRole('combobox');

/** Longer than the component's 300ms search debounce. */
const pastDebounce = () => new Promise((resolve) => setTimeout(resolve, 500));

const loader = () => document.querySelector('.mantine-Loader-root');

/**
 * Hands back one deferred per API call, each rejecting the way a real aborted
 * request does, so tests can interleave slow requests the way a slow
 * connection does.
 */
const deferredRequests = () => {
    const pending: { resolve: (value: unknown) => void }[] = [];

    apiGetMock.mockImplementation((_uri: string, options: { abortSignal: AbortSignal }) => {
        return new Promise((resolve, reject) => {
            pending.push({ resolve });
            options.abortSignal.addEventListener('abort', () => reject(new Error('aborted')));
        });
    });

    return pending;
};

describe('DSelectRemote', () => {
    beforeEach(() => {
        apiGetMock.mockReset();
        apiGetMock.mockResolvedValue({ data: [{ id: 1, name: 'NGUYEN THI THUY' }] });
    });

    /**
     * A disabled input is dropped by the browser, so disabling it for the
     * duration of the request threw the caret out of the box mid-word and the
     * admin had to click back in after every search.
     */
    it('keeps the search input focusable while the request is in flight', async () => {
        let resolveRequest: (value: unknown) => void = () => {};
        apiGetMock.mockImplementation(
            () =>
                new Promise((resolve) => {
                    resolveRequest = resolve;
                }),
        );

        render(<ControlledSelect />);

        const input = searchInput();
        input.focus();
        fireEvent.change(input, { target: { value: 'NGUYEN' } });
        await pastDebounce();

        await waitFor(() => expect(apiGetMock).toHaveBeenCalled());
        expect(input).not.toBeDisabled();
        expect(input).toHaveFocus();

        resolveRequest({ data: [{ id: 1, name: 'NGUYEN THI THUY' }] });
    });

    it('searches on typing and offers the fetched options', async () => {
        render(<ControlledSelect />);

        fireEvent.change(searchInput(), { target: { value: 'NGUYEN' } });
        await pastDebounce();

        await waitFor(() => expect(screen.getByText('NGUYEN THI THUY')).toBeInTheDocument());
        expect(apiGetMock).toHaveBeenCalledWith(
            'admin/master/users/search',
            expect.objectContaining({ params: expect.objectContaining({ q: 'NGUYEN' }) }),
        );
    });

    /**
     * `extraParams` narrow the suggestions while typing. Applying them to the
     * lookup that resolves an already-selected value would leave a value that no
     * longer matches the filter (an admin whose permission was revoked, say)
     * showing its raw id instead of a name.
     */
    it('resolves the label of a preselected value without the search filters', async () => {
        apiGetMock.mockResolvedValue({ data: [{ id: 42, name: 'NGUYEN THI THUY' }] });

        render(<ControlledSelect initialValue="42" extraParams={{ permission: ['manager.auth'] }} />);

        await waitFor(() => expect(apiGetMock).toHaveBeenCalled());
        expect(apiGetMock).toHaveBeenCalledWith(
            'admin/master/users/search',
            expect.objectContaining({ params: { key: '42' } }),
        );
        await waitFor(() => expect(searchInput()).toHaveValue('NGUYEN THI THUY'));
    });

    it('still narrows the suggestions by the search filters while typing', async () => {
        render(<ControlledSelect extraParams={{ permission: ['manager.auth'] }} />);

        fireEvent.change(searchInput(), { target: { value: 'nguyen' } });
        await pastDebounce();

        await waitFor(() =>
            expect(apiGetMock).toHaveBeenCalledWith(
                'admin/master/users/search',
                expect.objectContaining({ params: { permission: ['manager.auth'], q: 'nguyen' } }),
            ),
        );
    });

    /**
     * After a selection Mantine writes the option label into the search input,
     * and that echo must not trigger another request.
     */
    it('does not re-search the label written back by a selection', async () => {
        render(<ControlledSelect />);

        fireEvent.change(searchInput(), { target: { value: 'NGUYEN' } });
        await pastDebounce();
        await waitFor(() => expect(screen.getByText('NGUYEN THI THUY')).toBeInTheDocument());

        apiGetMock.mockClear();
        fireEvent.click(screen.getByText('NGUYEN THI THUY'));
        await pastDebounce();

        const searchCalls = apiGetMock.mock.calls.filter(([, options]) => 'q' in options.params);
        expect(searchCalls).toHaveLength(0);
    });

    /**
     * The reported bug: with a selection made, deleting characters fast enough
     * that the label echo and the edited text collapsed into a single debounce
     * made the skip flag swallow the edited query, leaving the dropdown empty
     * with no further keystroke to recover it.
     */
    it('searches again when the label is edited straight after selecting', async () => {
        render(<ControlledSelect />);

        fireEvent.change(searchInput(), { target: { value: 'NGUYEN' } });
        await pastDebounce();
        await waitFor(() => expect(screen.getByText('NGUYEN THI THUY')).toBeInTheDocument());

        apiGetMock.mockClear();
        apiGetMock.mockResolvedValue({ data: [{ id: 2, name: 'NGUYEN THI THU' }] });

        // Select, then edit the echoed label within the same debounce window.
        fireEvent.click(screen.getByText('NGUYEN THI THUY'));
        fireEvent.change(searchInput(), { target: { value: 'NGUYEN THI THU' } });
        await pastDebounce();

        await waitFor(() =>
            expect(apiGetMock).toHaveBeenCalledWith(
                'admin/master/users/search',
                expect.objectContaining({ params: expect.objectContaining({ q: 'NGUYEN THI THU' }) }),
            ),
        );
        await waitFor(() => expect(screen.getByText('NGUYEN THI THU')).toBeInTheDocument());
    });

    /**
     * Now that typing is no longer blocked while a request runs, a slow
     * connection routinely leaves a superseded request in flight. Its rejection
     * used to run through a `finally` that cleared the single shared loading
     * flag, so the spinner vanished while the newest request was still
     * pending - and its empty result wiped the options already on screen.
     */
    it('keeps the loader and the visible options while a superseded request unwinds', async () => {
        const pending = deferredRequests();
        render(<ControlledSelect />);

        // First query completes and puts options on screen.
        fireEvent.change(searchInput(), { target: { value: 'NGU' } });
        await pastDebounce();
        await waitFor(() => expect(pending).toHaveLength(1));
        pending[0].resolve({ data: [{ id: 1, name: 'NGUYEN THI THUY' }] });
        await waitFor(() => expect(screen.getByText('NGUYEN THI THUY')).toBeInTheDocument());

        // Second query starts, then a third supersedes it before it answers.
        fireEvent.change(searchInput(), { target: { value: 'NGUY' } });
        await pastDebounce();
        await waitFor(() => expect(pending).toHaveLength(2));
        fireEvent.change(searchInput(), { target: { value: 'NGUYE' } });
        await pastDebounce();
        await waitFor(() => expect(pending).toHaveLength(3));

        // The aborted second request has unwound by now; the third is still open.
        expect(loader()).toBeInTheDocument();
        expect(screen.getByText('NGUYEN THI THUY')).toBeInTheDocument();

        pending[2].resolve({ data: [{ id: 2, name: 'NGUYEN VAN B' }] });
        await waitFor(() => expect(loader()).not.toBeInTheDocument());
        expect(screen.getByText('NGUYEN VAN B')).toBeInTheDocument();
    });

    /**
     * The other half of splitting the flag: clearing it only on the request
     * that owns it must not leave the loader stuck when a run bails out early
     * (query too short) instead of firing a request.
     */
    it('clears the loader when the query is shortened below the search threshold', async () => {
        const pending = deferredRequests();
        render(<ControlledSelect />);

        fireEvent.change(searchInput(), { target: { value: 'NGU' } });
        await pastDebounce();
        await waitFor(() => expect(loader()).toBeInTheDocument());

        fireEvent.change(searchInput(), { target: { value: 'N' } });
        await pastDebounce();

        await waitFor(() => expect(loader()).not.toBeInTheDocument());
        expect(pending).toHaveLength(1);
    });
});
