import React from 'react';
import { ComboboxItem, MantineProvider } from '@mantine/core';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { ParsedUrlQuery } from 'querystring';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import DSelectRemoteMultiple from '@/components/DSelectRemoteMultiple';

const apiGetMock = vi.hoisted(() => vi.fn());

vi.mock('@/helpers/api.helper', () => ({
    default: { get: apiGetMock },
}));

type Employee = { id: number; name: string };

const renderSelect = (props: {
    searchDisabled?: boolean;
    disabled?: boolean;
    value: string[];
    extraParams?: ParsedUrlQuery;
    initialOptions?: ComboboxItem[];
}) => {
    const onChange = vi.fn();
    const view = render(
        <MantineProvider>
            <DSelectRemoteMultiple<Employee>
                label="Employees"
                uri="admin/users/search"
                renderItem={(employee): ComboboxItem => ({
                    value: employee.id.toString(),
                    label: employee.name,
                })}
                initialOptions={[
                    { value: '1', label: 'NGUYEN VAN A' },
                    { value: '2', label: 'TRAN THI B' },
                ]}
                onChange={onChange}
                {...props}
            />
        </MantineProvider>,
    );

    return { onChange, ...view };
};

/** Mantine renders the chip's remove control as an aria-hidden <button>. */
const removeButtons = () => screen.queryAllByRole('button', { hidden: true });

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

describe('DSelectRemoteMultiple value resolution', () => {
    beforeEach(() => {
        apiGetMock.mockReset();
        apiGetMock.mockResolvedValue({ data: [{ id: 42, name: 'NGUYEN THI THUY' }] });
    });

    /**
     * `extraParams` narrow the suggestions while typing. Applying them to the
     * lookup that resolves already-selected values would leave a value that no
     * longer matches the filter showing its raw id instead of a name.
     */
    it('resolves the labels of preselected values without the search filters', async () => {
        renderSelect({ value: ['42'], initialOptions: [], extraParams: { permission: ['manager.auth'] } });

        await waitFor(() => expect(apiGetMock).toHaveBeenCalled());
        expect(apiGetMock).toHaveBeenCalledWith(
            'admin/users/search',
            expect.objectContaining({ params: { key: ['42'] } }),
        );
        await waitFor(() => expect(screen.getByText('NGUYEN THI THUY')).toBeInTheDocument());
    });

    /**
     * `searchDisabled` locks typing only (the exam form does this until a
     * workplace is picked). Resolving the labels of values already in the form
     * must keep working, or the admin sees raw ids exactly when they are being
     * asked to clean the list up.
     */
    it('still resolves preselected labels while the search is locked', async () => {
        renderSelect({ value: ['42'], initialOptions: [], searchDisabled: true });

        await waitFor(() =>
            expect(apiGetMock).toHaveBeenCalledWith(
                'admin/users/search',
                expect.objectContaining({ params: { key: ['42'] } }),
            ),
        );
        await waitFor(() => expect(screen.getByText('NGUYEN THI THUY')).toBeInTheDocument());
    });

    it('still narrows the suggestions by the search filters while typing', async () => {
        renderSelect({ value: [], initialOptions: [], extraParams: { permission: ['manager.auth'] } });

        fireEvent.change(searchInput(), { target: { value: 'nguyen' } });
        await pastDebounce();

        await waitFor(() =>
            expect(apiGetMock).toHaveBeenCalledWith(
                'admin/users/search',
                expect.objectContaining({ params: { permission: ['manager.auth'], q: 'nguyen' } }),
            ),
        );
    });
});

describe('DSelectRemoteMultiple searchDisabled', () => {
    beforeEach(() => {
        apiGetMock.mockReset();
        apiGetMock.mockResolvedValue({ data: [{ id: 3, name: 'LE VAN C' }] });
    });

    /**
     * The reason the prop exists: without a valid scope the request would
     * fall back to an unscoped query, so nothing may leave the component.
     * Driven with fireEvent rather than typing so it exercises the effect's
     * own guard and not merely the readonly input Mantine gives us - both
     * layers have to hold.
     */
    it('never calls the API while the search is disabled', async () => {
        renderSelect({ searchDisabled: true, value: ['1'] });

        fireEvent.change(searchInput(), { target: { value: 'nguyen' } });
        await pastDebounce();

        expect(apiGetMock).not.toHaveBeenCalled();
    });

    it('marks the search input read-only while the search is disabled', () => {
        renderSelect({ searchDisabled: true, value: ['1'] });

        expect(searchInput()).toHaveAttribute('readonly');
    });

    /**
     * The distinction from `disabled`: locking the search must leave the
     * current selection removable, otherwise clearing the scope traps the
     * admin with chips they can no longer shed.
     */
    it('still lets the user remove a selected chip while the search is disabled', async () => {
        const { onChange } = renderSelect({ searchDisabled: true, value: ['1', '2'] });

        expect(removeButtons()).toHaveLength(2);
        await userEvent.click(removeButtons()[0]);

        await waitFor(() => expect(onChange).toHaveBeenCalled());
        expect(onChange.mock.calls[0][0]).toEqual(['2']);
    });

    /**
     * The contrast that makes the two props worth keeping apart: `disabled`
     * disables the control outright, which is what also freezes the chips and
     * traps the admin. searchDisabled only makes the field read-only.
     *
     * Asserted on the attributes rather than by clicking, because Mantine
     * blocks interaction with a disabled control through `pointer-events` in
     * its stylesheet - jsdom loads no CSS, so a click here would succeed and
     * "prove" the opposite of what a browser does.
     */
    it('disables the control outright for `disabled`, unlike searchDisabled', () => {
        const { unmount } = renderSelect({ disabled: true, value: ['1', '2'] });
        expect(screen.getByRole('combobox', { hidden: true })).toBeDisabled();
        unmount();

        renderSelect({ searchDisabled: true, value: ['1', '2'] });
        expect(searchInput()).not.toBeDisabled();
    });

    /**
     * The lock is not one-way: once a scope exists the same control has to
     * search normally again, including after having been locked.
     */
    it('resumes searching once the lock is lifted', async () => {
        const { rerender } = renderSelect({ searchDisabled: true, value: [] });

        rerender(
            <MantineProvider>
                <DSelectRemoteMultiple<Employee>
                    label="Employees"
                    uri="admin/users/search"
                    renderItem={(employee): ComboboxItem => ({
                        value: employee.id.toString(),
                        label: employee.name,
                    })}
                    initialOptions={[]}
                    searchDisabled={false}
                    value={[]}
                    onChange={vi.fn()}
                />
            </MantineProvider>,
        );

        expect(searchInput()).not.toHaveAttribute('readonly');
        fireEvent.change(searchInput(), { target: { value: 'nguyen' } });

        await waitFor(() => expect(apiGetMock).toHaveBeenCalled(), { timeout: 2000 });
        expect(apiGetMock.mock.calls[0][1].params).toMatchObject({ q: 'nguyen' });
    });
});

describe('DSelectRemoteMultiple search behaviour', () => {
    beforeEach(() => {
        apiGetMock.mockReset();
        apiGetMock.mockResolvedValue({ data: [{ id: 3, name: 'LE VAN C' }] });
    });

    /**
     * A disabled input is dropped by the browser, so disabling it for the
     * duration of the request threw the caret out of the box mid-word.
     */
    it('keeps the search input focusable while the request is in flight', async () => {
        let resolveRequest: (value: unknown) => void = () => {};
        apiGetMock.mockImplementation(
            () =>
                new Promise((resolve) => {
                    resolveRequest = resolve;
                }),
        );

        renderSelect({ value: [] });

        const input = searchInput();
        input.focus();
        fireEvent.change(input, { target: { value: 'nguyen' } });
        await pastDebounce();

        await waitFor(() => expect(apiGetMock).toHaveBeenCalled());
        expect(input).not.toBeDisabled();
        expect(input).toHaveFocus();

        resolveRequest({ data: [{ id: 3, name: 'LE VAN C' }] });
    });

    /**
     * After a selection Mantine writes the option label into the search input.
     * That echo must not be searched again, but a query typed within the same
     * debounce window must not be swallowed along with it.
     */
    it('skips only the label echoed by a selection, not the query typed after it', async () => {
        renderSelect({ value: ['1'] });

        // Mantine echoes the selected chip's label into the search input...
        fireEvent.change(searchInput(), { target: { value: 'NGUYEN VAN A' } });
        // ...and the user edits it before the debounce elapses.
        fireEvent.change(searchInput(), { target: { value: 'NGUYEN VAN' } });
        await pastDebounce();

        await waitFor(() => expect(apiGetMock).toHaveBeenCalled());
        expect(apiGetMock.mock.calls[0][1].params).toMatchObject({ q: 'NGUYEN VAN' });
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
        renderSelect({ value: [] });

        // First query completes and puts options on screen.
        fireEvent.change(searchInput(), { target: { value: 'LE ' } });
        await pastDebounce();
        await waitFor(() => expect(pending).toHaveLength(1));
        pending[0].resolve({ data: [{ id: 3, name: 'LE VAN C' }] });
        await waitFor(() => expect(screen.getByText('LE VAN C')).toBeInTheDocument());

        // Second query starts, then a third supersedes it before it answers.
        fireEvent.change(searchInput(), { target: { value: 'LE V' } });
        await pastDebounce();
        await waitFor(() => expect(pending).toHaveLength(2));
        fireEvent.change(searchInput(), { target: { value: 'LE VA' } });
        await pastDebounce();
        await waitFor(() => expect(pending).toHaveLength(3));

        // The aborted second request has unwound by now; the third is still open.
        expect(loader()).toBeInTheDocument();
        expect(screen.getByText('LE VAN C')).toBeInTheDocument();

        pending[2].resolve({ data: [{ id: 4, name: 'LE VAN D' }] });
        await waitFor(() => expect(loader()).not.toBeInTheDocument());
        expect(screen.getByText('LE VAN D')).toBeInTheDocument();
    });

    /**
     * The other half of splitting the flag: clearing it only on the request
     * that owns it must not leave the loader stuck when a run bails out early
     * (query too short) instead of firing a request.
     */
    it('clears the loader when the query is shortened below the search threshold', async () => {
        const pending = deferredRequests();
        renderSelect({ value: [] });

        fireEvent.change(searchInput(), { target: { value: 'LE ' } });
        await pastDebounce();
        await waitFor(() => expect(loader()).toBeInTheDocument());

        fireEvent.change(searchInput(), { target: { value: 'L' } });
        await pastDebounce();

        await waitFor(() => expect(loader()).not.toBeInTheDocument());
        expect(pending).toHaveLength(1);
    });
});
