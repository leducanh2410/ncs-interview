import React from 'react';
import { act, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { ExamWithRelation } from '@/types/models/exam';
import { ApiValidationError } from '@/helpers/api.helper';
import DateHelper from '@/helpers/date.helper';
import ExamForm from './exam_form';

// _app.page.tsx registers the dayjs utc/timezone plugins; this component is
// rendered without it, and isStartAtLocked() needs DateHelper.parse() to work.
DateHelper.initDayjs();

vi.mock('react-i18next', () => ({
    initReactI18next: { type: '3rdParty', init: () => undefined },
    useTranslation: () => ({ t: (key: string) => key }),
}));

vi.mock('next/router', () => ({
    useRouter: () => ({ push: vi.fn(), query: { id: '7' } }),
}));

vi.mock('next/link', () => ({
    __esModule: true,
    default: ({ children }: any) => React.createElement('a', null, children),
}));

vi.mock('jotai', () => ({
    useAtom: () => [
        {
            workplaces: [
                { id: 1, name: 'Workplace A' },
                { id: 2, name: 'Workplace B' },
            ],
        },
        vi.fn(),
    ],
    atom: (init: any) => ({ init }),
}));

const { createMutateAsyncMock, mockUpdateExamMutation, translateMutateAsyncMock } = vi.hoisted(() => ({
    createMutateAsyncMock: vi.fn(),
    mockUpdateExamMutation: vi.fn().mockResolvedValue(undefined),
    translateMutateAsyncMock: vi.fn(),
}));

vi.mock('@/helpers/notification.helper', () => ({
    __esModule: true,
    default: { showSuccess: vi.fn(), showError: vi.fn(), showInfo: vi.fn() },
}));

vi.mock('@/pages/manage/exam/api', () => ({
    useCreateExamMutation: () => ({ mutateAsync: createMutateAsyncMock }),
    useUpdateExamMutation: () => ({ mutateAsync: mockUpdateExamMutation }),
}));

vi.mock('@/pages/translate.api', () => ({
    useTranslateContentMutation: () => ({ mutateAsync: translateMutateAsyncMock, isPending: false }),
}));

vi.mock('@/components/DLanguageTabsInput', () => ({
    __esModule: true,
    default: ({ label }: any) => React.createElement('div', null, label),
}));

vi.mock('@/components/DSelectRemoteMultiple', () => ({
    __esModule: true,
    default: ({ label, defaultValue, initialOptions }: any) =>
        React.createElement('div', null, [
            React.createElement('span', { key: 'label' }, label),
            React.createElement(
                'span',
                { 'key': 'value', 'data-testid': `remote-multiselect-${label}` },
                JSON.stringify(defaultValue ?? []),
            ),
            ...(initialOptions ?? []).map((option: any) =>
                React.createElement('span', { key: `option-${option.value}` }, option.label),
            ),
        ]),
}));

vi.mock('@/pages/manage/document/partials/create_document_modal', () => ({
    __esModule: true,
    default: ({ isOpened, onSubmitted, onClose, withAudience }: any) =>
        isOpened
            ? React.createElement('div', { 'data-testid': 'create-document-modal' }, [
                  React.createElement(
                      'span',
                      { 'key': 'audience', 'data-testid': 'create-document-modal-audience' },
                      withAudience === false ? 'hidden' : 'shown',
                  ),
                  React.createElement(
                      'button',
                      {
                          key: 'submit',
                          type: 'button',
                          onClick: () => {
                              onSubmitted?.({ id: 99, title: 'Uploaded handbook' });
                              onClose?.();
                          },
                      },
                      'Submit document',
                  ),
              ])
            : null,
}));

// Stand-in for the remote employee picker: renders the labels it was given
// for the restored chips (so the stale marker is assertable), exposes the
// search scope it would query with, and lets a test name employees.
vi.mock('@/components/DUserSelectRemote', () => ({
    DUserSelectRemoteMultiple: ({
        label,
        defaultValue,
        onChange,
        extraParams,
        initialOptions,
        error,
        disabled,
        searchDisabled,
    }: any) =>
        React.createElement('div', { 'data-testid': 'employee-selector' }, [
            React.createElement('span', { key: 'label' }, label),
            React.createElement(
                'span',
                { 'key': 'disabled', 'data-testid': 'employee-selector-disabled' },
                disabled ? 'disabled' : 'enabled',
            ),
            React.createElement(
                'span',
                { 'key': 'search-disabled', 'data-testid': 'employee-selector-search-disabled' },
                searchDisabled ? 'disabled' : 'enabled',
            ),
            React.createElement(
                'span',
                { 'key': 'scope', 'data-testid': 'employee-selector-scope' },
                JSON.stringify(extraParams?.workplace_ids ?? []),
            ),
            ...(initialOptions ?? []).map((option: any) =>
                React.createElement('span', { key: option.value }, option.label),
            ),
            React.createElement('input', {
                'key': 'input',
                'aria-label': label,
                'defaultValue': (defaultValue ?? []).join(','),
                'onChange': (e: any) => onChange?.(e.target.value ? e.target.value.split(',') : []),
            }),
            React.createElement('span', { key: 'error' }, error),
        ]),
}));

vi.mock('./QuestionFormList', () => ({
    __esModule: true,
    default: ({ disabled, structureLocked }: any) =>
        React.createElement('div', {
            'data-testid': 'question-list',
            'data-disabled': disabled ? 'true' : 'false',
            'data-structure-locked': structureLocked ? 'true' : 'false',
        }),
}));

vi.mock('@mantine/dates', () => ({
    DateTimePicker: ({ label, defaultValue, disabled }: any) =>
        React.createElement('div', null, [
            label,
            React.createElement('span', { 'key': 'value', 'data-testid': `date-${label}` }, String(defaultValue ?? '')),
            disabled ? React.createElement('span', { 'key': 'disabled', 'data-testid': `disabled-${label}` }) : null,
        ]),
}));

// Mantine's Radio.Group passes the selected value to its Radio children
// through context; the stand-ins below do the same so a radio can be clicked.
const RadioGroupContext = React.createContext<{ value: string; onChange: (value: string) => void } | null>(null);

// Lightweight stand-ins for Mantine inputs. @mantine/form is intentionally
// NOT mocked: the regression under test lives in how the real uncontrolled
// form decides when the component re-renders.
vi.mock('@mantine/core', () => ({
    Alert: ({ children }: any) => React.createElement('div', { role: 'alert' }, children),
    Box: ({ children, display }: any) => React.createElement('div', { 'data-display': display }, children),
    Stepper: Object.assign(
        ({ children, active, onStepClick }: any) =>
            React.createElement(
                'div',
                { 'data-testid': 'stepper', 'data-active': String(active) },
                React.Children.map(children, (child: any, index: number) =>
                    React.cloneElement(child, { index, onStepClick }),
                ),
            ),
        {
            Step: ({ label, index, onStepClick }: any) =>
                React.createElement(
                    'button',
                    { 'type': 'button', 'data-step': 'true', 'onClick': () => onStepClick?.(index) },
                    label,
                ),
        },
    ),
    Text: ({ children }: any) => React.createElement('span', null, children),
    Button: ({ children, onClick, type }: any) =>
        React.createElement('button', { onClick, type: type ?? 'button' }, children),
    Checkbox: ({ label, checked, disabled, onChange }: any) =>
        React.createElement('label', null, [
            React.createElement('input', {
                key: 'cb',
                type: 'checkbox',
                checked: !!checked,
                disabled,
                onChange: () => onChange?.({ currentTarget: { checked: !checked } }),
            }),
            label,
        ]),
    Group: ({ children }: any) => React.createElement('div', null, children),
    // Two probes in one: a typable input (so a test can change the selection)
    // and a read-only span exposing the value the form pushed in (so a test
    // can assert what setValues() seeded). The span sits outside the <label>
    // on purpose - inside it, its JSON would become part of the label's
    // accessible name and break getByLabelText().
    MultiSelect: ({ label, defaultValue, onChange }: any) =>
        React.createElement('div', null, [
            React.createElement(
                'span',
                { 'key': 'value', 'data-testid': `multiselect-${label}` },
                JSON.stringify(defaultValue ?? []),
            ),
            React.createElement('label', { key: 'label' }, [
                label,
                React.createElement('input', {
                    'key': 'input',
                    'aria-label': label,
                    'defaultValue': (defaultValue ?? []).join(','),
                    'onChange': (e: any) => onChange?.(e.target.value ? e.target.value.split(',') : []),
                }),
            ]),
        ]),
    Radio: Object.assign(
        ({ value, label, disabled, description }: any) => {
            const group = React.useContext(RadioGroupContext);
            return React.createElement('label', null, [
                React.createElement('input', {
                    'key': 'radio',
                    'type': 'radio',
                    'aria-label': label,
                    'checked': group?.value === value,
                    disabled,
                    'onChange': () => group?.onChange(value),
                }),
                label,
                description ? React.createElement('span', { key: 'description' }, description) : null,
            ]);
        },
        {
            Group: ({ label, children, defaultValue, onChange }: any) => {
                const [value, setValue] = React.useState(defaultValue);
                return React.createElement(RadioGroupContext.Provider, {
                    value: {
                        value,
                        onChange: (next: string) => {
                            setValue(next);
                            onChange?.(next);
                        },
                    },
                    children: [React.createElement('span', { key: 'label' }, label), children],
                });
            },
        },
    ),
    NumberInput: ({ label, defaultValue, onChange }: any) =>
        React.createElement('label', null, [
            label,
            React.createElement('input', {
                'key': 'input',
                'aria-label': label,
                'defaultValue': defaultValue ?? '',
                'onChange': (e: any) => onChange?.(Number(e.target.value)),
            }),
        ]),
    Paper: ({ children }: any) => React.createElement('div', null, children),
    Select: ({ label, data, defaultValue, onChange }: any) =>
        React.createElement('label', null, [
            label,
            React.createElement(
                'select',
                {
                    'key': 'select',
                    'aria-label': label,
                    'defaultValue': defaultValue ?? '',
                    'onChange': (e: any) => onChange?.(e.target.value),
                },
                (data ?? []).map((option: any) =>
                    React.createElement('option', { key: option.value, value: option.value }, option.label),
                ),
            ),
        ]),
    TextInput: ({ label, defaultValue, onChange }: any) =>
        React.createElement('label', null, [
            label,
            React.createElement('input', {
                'key': 'input',
                'aria-label': label,
                'defaultValue': defaultValue ?? '',
                'onChange': (e: any) => onChange?.(e.target.value),
            }),
        ]),
    Title: ({ children }: any) => React.createElement('h5', null, children),
    Tooltip: ({ children }: any) => children,
}));

const translatable = (value: string) => ({ vi: value, en: value, ja: value, ne: value, si: value });

function makeExternalExamData(): ExamWithRelation {
    return {
        id: 7,
        title: translatable('External exam'),
        languages: ['en'],
        type: 'external_form_prep',
        is_required: true,
        start_at: null,
        end_at: null,
        retry_limit: 0,
        min_score: 0,
        time_limit: 120,
        external_url: translatable('https://docs.google.com/forms/d/e/example/viewform'),
        workplaces: [],
        documents: [],
        questions: [],
    } as unknown as ExamWithRelation;
}

describe('ExamForm exam type switching', () => {
    it('shows the external-only fields for a loaded external_form_prep exam', () => {
        render(<ExamForm examData={makeExternalExamData()} />);

        expect(screen.getByText('Google Form link')).toBeTruthy();
        expect(screen.queryByLabelText('Min score')).toBeNull();
    });

    /**
     * Regression: the form is uncontrolled and its initial snapshot is
     * always `assessment`, while a loaded external exam only updates the
     * values ref. Selecting `assessment` back therefore doesn't flip the
     * field's dirty status, and without an explicit watch on `type` the
     * component never re-rendered — the UI stayed in external mode and
     * saving persisted external defaults onto an assessment exam.
     */
    it('re-renders scoring fields when a loaded external exam is switched back to assessment', async () => {
        render(<ExamForm examData={makeExternalExamData()} />);

        await userEvent.selectOptions(screen.getByRole('combobox', { name: 'Exam type' }), 'assessment');

        expect(screen.getByLabelText('Min score')).toBeTruthy();
        expect(screen.getByLabelText('Retry limit')).toBeTruthy();
        expect(screen.getByLabelText('Time limit (minutes)')).toBeTruthy();
        expect(screen.queryByText('Google Form link')).toBeNull();
    });

    /**
     * Regression: the Google Form link tabs unmount once the type leaves
     * external_form_prep, but a stale/invalid draft value left in the
     * (uncontrolled) form state still got submitted - the backend's
     * per-language `nullable|url` rule then 422'd a save the admin no
     * longer had a visible field to fix.
     */
    it('clears the external URL map when a loaded external exam is switched to assessment', async () => {
        mockUpdateExamMutation.mockClear();
        render(<ExamForm examData={makeExternalExamData()} />);

        await userEvent.selectOptions(screen.getByRole('combobox', { name: 'Exam type' }), 'assessment');
        await userEvent.click(screen.getByRole('button', { name: 'Save' }));

        expect(mockUpdateExamMutation).toHaveBeenCalledTimes(1);
        const submitted = mockUpdateExamMutation.mock.calls[0][0];
        expect(submitted.external_url).toEqual({ vi: '', en: '', ja: '', ne: '', si: '' });
    });

    it('reveals the external URL field when a new exam is switched to external_form_prep', async () => {
        render(<ExamForm />);

        expect(screen.queryByText('Google Form link')).toBeNull();

        await userEvent.selectOptions(screen.getByRole('combobox', { name: 'Exam type' }), 'external_form_prep');

        expect(screen.getByText('Google Form link')).toBeTruthy();
        expect(screen.queryByLabelText('Min score')).toBeNull();
    });
});

function makeAssessmentExamData(overrides: Partial<ExamWithRelation> = {}): ExamWithRelation {
    return {
        id: 7,
        title: translatable('Assessment exam'),
        languages: ['en'],
        type: 'assessment',
        is_required: false,
        start_at: null,
        end_at: null,
        retry_limit: 0,
        min_score: 0,
        time_limit: 30,
        external_url: translatable(''),
        workplaces: [{ id: 1, name: 'Workplace A' }],
        documents: [],
        questions: [],
        targeted_users: [],
        ...overrides,
    } as unknown as ExamWithRelation;
}

const selectWorkplaces = async (value: string) =>
    userEvent.type(screen.getByLabelText('Workplace') as HTMLInputElement, value);

describe('ExamForm delivery mode', () => {
    it('defaults a new exam to whole workplaces with no employee selector', () => {
        render(<ExamForm />);

        expect((screen.getByLabelText('Every employee of the selected workplaces') as HTMLInputElement).checked).toBe(
            true,
        );
        expect(screen.queryByTestId('employee-selector')).toBeNull();
    });

    it('disables the named-employees option until a workplace is selected', async () => {
        render(<ExamForm />);

        expect((screen.getByLabelText('Only the employees named below') as HTMLInputElement).disabled).toBe(true);
        expect(screen.getByText('Select a workplace first')).toBeTruthy();

        await selectWorkplaces('1');

        expect((screen.getByLabelText('Only the employees named below') as HTMLInputElement).disabled).toBe(false);
    });

    it('reveals the employee selector when named employees is chosen', async () => {
        render(<ExamForm />);

        await selectWorkplaces('1');
        await userEvent.click(screen.getByLabelText('Only the employees named below'));

        expect(screen.getByTestId('employee-selector')).toBeTruthy();
    });

    it('scopes the employee search to every selected workplace', async () => {
        render(<ExamForm />);

        await selectWorkplaces('1,2');
        await userEvent.click(screen.getByLabelText('Only the employees named below'));

        expect(screen.getByTestId('employee-selector-scope').textContent).toBe(JSON.stringify(['1', '2']));
    });

    /**
     * Removing the last workplace leaves the mode on `users` while the search
     * scope becomes empty - and an empty array serializes to no query
     * parameter at all, so the picker would search every employee in the
     * system and offer candidates this exam could never reach.
     */
    it('locks the employee search when the last workplace is removed', async () => {
        render(<ExamForm />);

        await selectWorkplaces('1');
        await userEvent.click(screen.getByLabelText('Only the employees named below'));
        expect(screen.getByTestId('employee-selector-search-disabled').textContent).toBe('enabled');

        await userEvent.clear(screen.getByLabelText('Workplace') as HTMLInputElement);

        expect(screen.getByTestId('employee-selector-search-disabled').textContent).toBe('disabled');
        expect(screen.getByTestId('employee-selector-scope').textContent).toBe(JSON.stringify([]));
    });

    /**
     * Locking the search must not lock the selection: clearing the workplaces
     * and then clearing the employees they scoped is the natural order of
     * reworking the audience, and `disabled` on the whole control would block
     * it - leaving the admin with chips they can only shed by switching to
     * "every employee" mode, which drops the whole list on save.
     */
    it('still lets the admin clear named employees after the last workplace is removed', async () => {
        render(<ExamForm />);

        await selectWorkplaces('1');
        await userEvent.click(screen.getByLabelText('Only the employees named below'));
        await userEvent.clear(screen.getByLabelText('Workplace') as HTMLInputElement);

        expect(screen.getByTestId('employee-selector-disabled').textContent).toBe('enabled');
    });

    /**
     * The API reads an empty `targeted_users` as "every member of the
     * selected workplaces", so an empty submit in this mode would silently
     * send the exam to the whole workplace instead of the intended subset.
     */
    it('blocks submission when named employees is chosen but nobody is named', async () => {
        mockUpdateExamMutation.mockClear();
        render(<ExamForm examData={makeAssessmentExamData()} />);

        await userEvent.click(screen.getByLabelText('Only the employees named below'));
        await userEvent.click(screen.getByRole('button', { name: 'Save' }));

        expect(mockUpdateExamMutation).not.toHaveBeenCalled();
        expect(screen.getByText('Select at least one employee')).toBeTruthy();
    });

    it('submits targeted_users as a flat id array without delivery_mode', async () => {
        mockUpdateExamMutation.mockClear();
        render(<ExamForm examData={makeAssessmentExamData()} />);

        await userEvent.click(screen.getByLabelText('Only the employees named below'));
        await userEvent.type(screen.getByLabelText('Employees') as HTMLInputElement, '10,11');
        await userEvent.click(screen.getByRole('button', { name: 'Save' }));

        expect(mockUpdateExamMutation).toHaveBeenCalledTimes(1);
        const submitted = mockUpdateExamMutation.mock.calls[0][0];
        expect(submitted.targeted_users).toEqual(['10', '11']);
        expect(submitted).not.toHaveProperty('delivery_mode');
    });

    it('submits an empty targeted_users after switching back to whole workplaces', async () => {
        mockUpdateExamMutation.mockClear();
        render(<ExamForm examData={makeAssessmentExamData()} />);

        await userEvent.click(screen.getByLabelText('Only the employees named below'));
        await userEvent.type(screen.getByLabelText('Employees') as HTMLInputElement, '10,11');
        await userEvent.click(screen.getByLabelText('Every employee of the selected workplaces'));
        await userEvent.click(screen.getByRole('button', { name: 'Save' }));

        expect(mockUpdateExamMutation).toHaveBeenCalledTimes(1);
        expect(mockUpdateExamMutation.mock.calls[0][0].targeted_users).toEqual([]);
    });

    it('opens an exam with named employees in named-employees mode, with their labels', () => {
        render(
            <ExamForm
                examData={makeAssessmentExamData({
                    targeted_users: [
                        { id: 10, name: 'NGUYEN VAN A', name_kana: '', staff_no: 'NV001', is_current_member: true },
                    ],
                })}
            />,
        );

        expect((screen.getByLabelText('Only the employees named below') as HTMLInputElement).checked).toBe(true);
        expect(screen.getByText('NGUYEN VAN A (NV001)')).toBeTruthy();
    });

    it('opens an exam without named employees in whole-workplaces mode', () => {
        render(<ExamForm examData={makeAssessmentExamData()} />);

        expect((screen.getByLabelText('Every employee of the selected workplaces') as HTMLInputElement).checked).toBe(
            true,
        );
        expect(screen.queryByTestId('employee-selector')).toBeNull();
    });

    /**
     * A named employee who has left every selected workplace is still shown,
     * marked: dropping the chip would leave an empty selector, which means
     * "everyone" - so an exam reaching nobody would start reaching a whole
     * workplace on the next save.
     */
    it('marks a named employee who is no longer a member of the selected workplaces', () => {
        render(
            <ExamForm
                examData={makeAssessmentExamData({
                    targeted_users: [
                        { id: 11, name: 'TRAN THI B', name_kana: '', staff_no: 'NV002', is_current_member: false },
                    ],
                })}
            />,
        );

        expect(screen.getByText('TRAN THI B (NV002) - no longer in the selected workplaces')).toBeTruthy();
    });

    /**
     * `is_current_member` is computed by the API against the exam's saved
     * workplaces, so it describes the audience as loaded and nothing else.
     * Once the admin edits the selection the flag can be wrong in both
     * directions, so the marker must not outlive the selection it was
     * computed for.
     */
    it('drops the stale marker once the workplace selection differs from the saved one', async () => {
        render(
            <ExamForm
                examData={makeAssessmentExamData({
                    targeted_users: [
                        { id: 11, name: 'TRAN THI B', name_kana: '', staff_no: 'NV002', is_current_member: false },
                    ],
                })}
            />,
        );

        expect(screen.getByText('TRAN THI B (NV002) - no longer in the selected workplaces')).toBeTruthy();

        await selectWorkplaces('2');

        expect(screen.queryByText('TRAN THI B (NV002) - no longer in the selected workplaces')).toBeNull();
        expect(screen.getByText('TRAN THI B (NV002)')).toBeTruthy();
    });
});

/**
 * A copy source with a schedule already in the past and non-default
 * scoring, so the copy-mode tests can tell "cleared" from "carried over".
 */
function makeCopySourceExamData(overrides: Partial<ExamWithRelation> = {}): ExamWithRelation {
    return makeAssessmentExamData({
        id: 3,
        title: translatable('Source exam'),
        is_required: true,
        start_at: '2020-01-01 00:00:00',
        end_at: '2020-01-08 00:00:00',
        retry_limit: 3,
        min_score: 1,
        time_limit: 10,
        ...overrides,
    } as Partial<ExamWithRelation>);
}

describe('ExamForm copy mode', () => {
    it('carries over workplaces and schedule when editing the source exam directly', () => {
        render(<ExamForm examData={makeCopySourceExamData()} />);

        expect(screen.getByTestId('multiselect-Workplace').textContent).toBe('["1"]');
        expect(screen.getByTestId('date-Start date').textContent).toBe('2020-01-01 00:00:00');
        expect(screen.getByTestId('date-Completion deadline').textContent).toBe('2020-01-08 00:00:00');
    });

    it('clears workplaces and schedule from the copy source, keeping everything else', () => {
        render(<ExamForm examData={makeCopySourceExamData()} isCopy />);

        expect(screen.getByTestId('multiselect-Workplace').textContent).toBe('[]');
        expect(screen.getByTestId('date-Start date').textContent).toBe('');
        expect(screen.getByTestId('date-Completion deadline').textContent).toBe('');
        expect((screen.getByLabelText('Min score') as HTMLInputElement).value).toBe('1');
        expect((screen.getByLabelText('Retry limit') as HTMLInputElement).value).toBe('3');
    });

    /**
     * The copy's workplaces are cleared, so it cannot inherit the source's
     * named employees either - they belong to the source's workplaces, and a
     * payload naming employees with no workplace scope is rejected by the API
     * outright. Copying resets the audience and makes the admin retarget it.
     */
    it('resets the audience to whole workplaces when copying an exam with named employees', () => {
        render(
            <ExamForm
                examData={makeCopySourceExamData({
                    targeted_users: [
                        { id: 11, name: 'TRAN THI B', name_kana: '', staff_no: 'NV002', is_current_member: true },
                    ],
                } as unknown as Partial<ExamWithRelation>)}
                isCopy
            />,
        );

        expect((screen.getByLabelText('Every employee of the selected workplaces') as HTMLInputElement).checked).toBe(
            true,
        );
        expect(screen.queryByTestId('employee-selector')).toBeNull();
    });

    /**
     * The same source edited directly (not copied) must still load in named
     * -employees mode - the reset above is specific to copying.
     */
    it('keeps named-employees mode when the same exam is edited rather than copied', () => {
        render(
            <ExamForm
                examData={makeCopySourceExamData({
                    targeted_users: [
                        { id: 11, name: 'TRAN THI B', name_kana: '', staff_no: 'NV002', is_current_member: true },
                    ],
                } as unknown as Partial<ExamWithRelation>)}
            />,
        );

        expect((screen.getByLabelText('Only the employees named below') as HTMLInputElement).checked).toBe(true);
        expect(screen.getByTestId('employee-selector')).toBeTruthy();
    });

    /**
     * The source exam's start_at is already in the past, which would lock
     * the field on a genuine edit (see the "carries over" test above via
     * isStartAtLocked). In copy mode the field must not inherit that lock,
     * since the copy's own start_at is cleared and hasn't happened yet.
     */
    it('does not lock the start date even though the copy source has already started', () => {
        render(<ExamForm examData={makeCopySourceExamData()} isCopy />);

        expect(screen.queryByTestId('disabled-Start date')).toBeNull();
    });

    /**
     * Regression: workplaces/start_at are cleared for a copy (see the test
     * above) but nothing previously stopped the form from being saved that
     * way. Since `is_required` carries over from the source exam, saving
     * before retargeting could immediately activate a required exam for
     * every approved user. Submitting with both fields still empty must be
     * rejected client-side.
     */
    it('blocks saving a copy until a target workplace and start date are set', async () => {
        createMutateAsyncMock.mockClear();
        render(<ExamForm examData={makeCopySourceExamData()} isCopy />);

        expect(screen.queryByRole('button', { name: 'Save' })).toBeNull();

        await userEvent.click(screen.getByRole('button', { name: 'Next' }));

        expect(screen.getByTestId('stepper')).toHaveAttribute('data-active', '0');
        expect(createMutateAsyncMock).not.toHaveBeenCalled();
    });

    it('does not lock the question structure of a copy even when the source has results', () => {
        render(<ExamForm examData={makeCopySourceExamData({ has_results: true })} isCopy />);

        expect(screen.getByTestId('question-list')).toHaveAttribute('data-structure-locked', 'false');
        expect(screen.queryByRole('alert')).toBeNull();
    });
});

describe('ExamForm language selection', () => {
    it('defaults a new exam to English only, with English locked', () => {
        render(<ExamForm />);

        const english = screen.getByLabelText('English') as HTMLInputElement;
        expect(english.checked).toBe(true);
        expect(english.disabled).toBe(true);

        const japanese = screen.getByLabelText('Japanese') as HTMLInputElement;
        expect(japanese.checked).toBe(false);
    });

    it('checking an optional language marks its checkbox selected', async () => {
        render(<ExamForm />);

        const japanese = screen.getByLabelText('Japanese') as HTMLInputElement;
        await userEvent.click(japanese);

        expect(japanese.checked).toBe(true);
    });

    it('pre-checks the languages loaded from an existing exam', () => {
        render(<ExamForm examData={{ ...makeExternalExamData(), languages: ['en', 'ja'] }} />);

        expect((screen.getByLabelText('English') as HTMLInputElement).checked).toBe(true);
        expect((screen.getByLabelText('Japanese') as HTMLInputElement).checked).toBe(true);
        expect((screen.getByLabelText('Vietnamese') as HTMLInputElement).checked).toBe(false);
    });
});

describe('ExamForm AI translate', () => {
    it('requests only source items that are still missing a target translation', async () => {
        translateMutateAsyncMock.mockReset();
        translateMutateAsyncMock.mockResolvedValue({
            data: { translations: { ja: { q1_content: '未翻訳の質問' } } },
        });
        const exam = makeAssessmentExamData({
            languages: ['en', 'ja'],
            title: translatable('Complete title'),
            questions: [
                { id: 1, content: translatable('Complete question'), options: [] },
                { id: 2, content: { en: 'Untranslated question', ja: '' }, options: [] },
            ],
        });

        render(<ExamForm examData={exam} />);
        await waitFor(() => expect((screen.getByLabelText('Japanese') as HTMLInputElement).checked).toBe(true));
        await userEvent.click(screen.getByRole('button', { name: 'AI translate' }));

        await waitFor(() => expect(translateMutateAsyncMock).toHaveBeenCalledTimes(1));
        expect(translateMutateAsyncMock).toHaveBeenCalledWith({
            items: [{ key: 'q1_content', text: 'Untranslated question' }],
            source_locale: 'en',
            target_locales: ['ja'],
            context: 'exam',
        });
    });

    it('requests each source item only for its missing target locales', async () => {
        translateMutateAsyncMock.mockReset();
        translateMutateAsyncMock.mockResolvedValue({
            data: {
                translations: {
                    ja: { title: 'タイトル' },
                    ne: { q0_content: 'प्रश्न' },
                },
            },
        });
        const exam = makeAssessmentExamData({
            languages: ['en', 'ja', 'ne'],
            title: { en: 'Title', ja: '', ne: 'शीर्षक' },
            questions: [{ id: 1, content: { en: 'Question', ja: '質問', ne: '' }, options: [] }],
        });

        render(<ExamForm examData={exam} />);
        await waitFor(() => expect((screen.getByLabelText('Nepali') as HTMLInputElement).checked).toBe(true));
        await userEvent.click(screen.getByRole('button', { name: 'AI translate' }));

        await waitFor(() => expect(translateMutateAsyncMock).toHaveBeenCalledTimes(2));
        expect(translateMutateAsyncMock).toHaveBeenNthCalledWith(1, {
            items: [{ key: 'title', text: 'Title' }],
            source_locale: 'en',
            target_locales: ['ja'],
            context: 'exam',
        });
        expect(translateMutateAsyncMock).toHaveBeenNthCalledWith(2, {
            items: [{ key: 'q0_content', text: 'Question' }],
            source_locale: 'en',
            target_locales: ['ne'],
            context: 'exam',
        });
    });

    it('copies an English external URL even when all text translations are complete', async () => {
        translateMutateAsyncMock.mockReset();
        mockUpdateExamMutation.mockClear();
        const url = 'https://docs.google.com/forms/d/e/example/viewform';
        const exam = {
            ...makeExternalExamData(),
            languages: ['en', 'ja'] as const,
            title: translatable('Complete title'),
            external_url: { en: url, ja: '' },
        } as unknown as ExamWithRelation;

        render(<ExamForm examData={exam} />);
        await waitFor(() => expect((screen.getByLabelText('Japanese') as HTMLInputElement).checked).toBe(true));
        await userEvent.click(screen.getByRole('button', { name: 'AI translate' }));
        await userEvent.click(screen.getByRole('button', { name: 'Save' }));

        await waitFor(() => expect(mockUpdateExamMutation).toHaveBeenCalledTimes(1));
        expect(mockUpdateExamMutation.mock.calls[0][0].external_url).toMatchObject({ en: url, ja: url });
        expect(translateMutateAsyncMock).not.toHaveBeenCalled();
    });

    it('locks question structure while translation chunks are running', async () => {
        translateMutateAsyncMock.mockReset();
        let resolveTranslation: (value: unknown) => void = () => undefined;
        translateMutateAsyncMock.mockReturnValue(
            new Promise((resolve) => {
                resolveTranslation = resolve;
            }),
        );
        const exam = makeAssessmentExamData({
            languages: ['en', 'ja'],
            title: { en: 'Title', ja: '' },
        });

        render(<ExamForm examData={exam} />);
        await waitFor(() => expect((screen.getByLabelText('Japanese') as HTMLInputElement).checked).toBe(true));
        await userEvent.click(screen.getByRole('button', { name: 'AI translate' }));

        await waitFor(() => expect(screen.getByTestId('question-list')).toHaveAttribute('data-disabled', 'true'));

        await act(async () => {
            resolveTranslation({ data: { translations: { ja: { title: 'タイトル' } } } });
        });

        await waitFor(() => expect(screen.getByTestId('question-list')).toHaveAttribute('data-disabled', 'false'));
    });
});

describe('ExamForm steps', () => {
    it('hides Save on the first step of a new exam and blocks Next while the step is invalid', async () => {
        render(<ExamForm />);

        expect(screen.queryByRole('button', { name: 'Save' })).toBeNull();

        await userEvent.click(screen.getByRole('button', { name: 'Next' }));

        expect(screen.getByTestId('stepper')).toHaveAttribute('data-active', '0');
    });

    it('does not jump ahead when a later step header is clicked on a new exam', async () => {
        render(<ExamForm />);

        await userEvent.click(screen.getByRole('button', { name: 'Questions' }));

        expect(screen.getByTestId('stepper')).toHaveAttribute('data-active', '0');
    });

    it('lets an edited exam jump to any step and save from there', async () => {
        render(<ExamForm examData={makeAssessmentExamData()} />);

        await userEvent.click(screen.getByRole('button', { name: 'Questions' }));
        expect(screen.getByTestId('stepper')).toHaveAttribute('data-active', '2');

        await userEvent.click(screen.getByRole('button', { name: 'General information' }));
        expect(screen.getByTestId('stepper')).toHaveAttribute('data-active', '0');
        expect(screen.getByRole('button', { name: 'Save' })).toBeTruthy();
    });

    it('advances through the steps with Next and returns with Back', async () => {
        render(<ExamForm examData={makeAssessmentExamData()} />);

        await userEvent.click(screen.getByRole('button', { name: 'Next' }));
        expect(screen.getByTestId('stepper')).toHaveAttribute('data-active', '1');

        await userEvent.click(screen.getByRole('button', { name: 'Next' }));
        expect(screen.getByTestId('stepper')).toHaveAttribute('data-active', '2');
        expect(screen.queryByRole('button', { name: 'Next' })).toBeNull();

        await userEvent.click(screen.getByRole('button', { name: 'Back' }));
        expect(screen.getByTestId('stepper')).toHaveAttribute('data-active', '1');
    });

    it('defers the passing score vs question count check until save, then jumps back to the first step', async () => {
        mockUpdateExamMutation.mockClear();
        render(<ExamForm examData={makeAssessmentExamData({ min_score: 5 })} />);

        await userEvent.click(screen.getByRole('button', { name: 'Next' }));
        await userEvent.click(screen.getByRole('button', { name: 'Next' }));
        expect(screen.getByTestId('stepper')).toHaveAttribute('data-active', '2');

        await userEvent.click(screen.getByRole('button', { name: 'Save' }));

        expect(mockUpdateExamMutation).not.toHaveBeenCalled();
        expect(screen.getByTestId('stepper')).toHaveAttribute('data-active', '0');
    });

    it('jumps to the questions step when the server rejects the questions', async () => {
        mockUpdateExamMutation.mockRejectedValueOnce(
            new ApiValidationError({
                status: 422,
                message: 'invalid',
                data: { questions: ['exam_questions_locked'] },
            }),
        );
        render(<ExamForm examData={makeAssessmentExamData()} />);

        await userEvent.click(screen.getByRole('button', { name: 'Save' }));

        await waitFor(() => expect(screen.getByTestId('stepper')).toHaveAttribute('data-active', '2'));
    });
});

describe('ExamForm exams with results', () => {
    it('locks the question structure and explains why when the exam already has results', () => {
        render(<ExamForm examData={makeAssessmentExamData({ has_results: true })} />);

        expect(screen.getByTestId('question-list')).toHaveAttribute('data-structure-locked', 'true');
        expect(screen.getByRole('alert')).toBeTruthy();
    });

    it('keeps the question structure editable when the exam has no results', () => {
        render(<ExamForm examData={makeAssessmentExamData({ has_results: false })} />);

        expect(screen.getByTestId('question-list')).toHaveAttribute('data-structure-locked', 'false');
        expect(screen.queryByRole('alert')).toBeNull();
    });
});

describe('ExamForm documents', () => {
    it('adds a newly uploaded document to the selection with its title', async () => {
        mockUpdateExamMutation.mockClear();
        render(<ExamForm examData={makeAssessmentExamData()} />);

        await userEvent.click(screen.getByRole('button', { name: 'Upload new document' }));
        expect(screen.getByTestId('create-document-modal-audience').textContent).toBe('hidden');

        await userEvent.click(screen.getByRole('button', { name: 'Submit document' }));

        expect(screen.queryByTestId('create-document-modal')).toBeNull();
        expect(screen.getByText('Uploaded handbook')).toBeTruthy();
        expect(screen.getByTestId('remote-multiselect-Document').textContent).toBe('["99"]');

        await userEvent.click(screen.getByRole('button', { name: 'Save' }));

        expect(mockUpdateExamMutation).toHaveBeenCalledTimes(1);
        expect(mockUpdateExamMutation.mock.calls[0][0].documents).toEqual(['99']);
    });
});

describe('ExamForm show results immediately', () => {
    it('submits show_result_immediately when checked on an assessment exam', async () => {
        mockUpdateExamMutation.mockClear();
        render(<ExamForm examData={makeAssessmentExamData()} />);

        await userEvent.click(screen.getByLabelText('Show results immediately after submission'));
        await userEvent.click(screen.getByRole('button', { name: 'Save' }));

        expect(mockUpdateExamMutation).toHaveBeenCalledTimes(1);
        expect(mockUpdateExamMutation.mock.calls[0][0].show_result_immediately).toBe(true);
    });

    it('hides the option and submits false for an external form exam', async () => {
        mockUpdateExamMutation.mockClear();
        render(
            <ExamForm
                examData={{ ...makeExternalExamData(), show_result_immediately: true } as unknown as ExamWithRelation}
            />,
        );

        expect(screen.queryByLabelText('Show results immediately after submission')).toBeNull();

        await userEvent.click(screen.getByRole('button', { name: 'Save' }));

        expect(mockUpdateExamMutation).toHaveBeenCalledTimes(1);
        expect(mockUpdateExamMutation.mock.calls[0][0].show_result_immediately).toBe(false);
    });
});

describe('ExamForm recurring', () => {
    it('checking recurring checks and locks required and clears the end date', async () => {
        mockUpdateExamMutation.mockClear();
        render(<ExamForm examData={makeAssessmentExamData({ end_at: '2099-01-01 00:00:00' })} />);

        await userEvent.click(screen.getByLabelText('Recurring'));

        const required = screen.getByLabelText('Required');
        expect(required).toBeChecked();
        expect(required).toBeDisabled();
        expect(screen.getByTestId('disabled-Completion deadline')).toBeInTheDocument();
        expect(screen.getByTestId('date-Completion deadline').textContent).toBe('');

        await userEvent.click(screen.getByRole('button', { name: 'Save' }));

        expect(mockUpdateExamMutation).toHaveBeenCalledTimes(1);
        expect(mockUpdateExamMutation.mock.calls[0][0]).toMatchObject({
            is_recurring: true,
            is_required: true,
            end_at: null,
        });
    });

    it('hides recurring and submits false for an external form exam', async () => {
        mockUpdateExamMutation.mockClear();
        render(
            <ExamForm examData={{ ...makeExternalExamData(), is_recurring: true } as unknown as ExamWithRelation} />,
        );

        expect(screen.queryByLabelText('Recurring')).toBeNull();

        await userEvent.click(screen.getByRole('button', { name: 'Save' }));

        expect(mockUpdateExamMutation).toHaveBeenCalledTimes(1);
        expect(mockUpdateExamMutation.mock.calls[0][0].is_recurring).toBe(false);
    });

    it('blocks saving a recurring exam without a workplace', async () => {
        mockUpdateExamMutation.mockClear();
        render(<ExamForm examData={makeAssessmentExamData({ is_recurring: true, workplaces: [] })} />);

        expect(screen.getByLabelText('Recurring')).toBeChecked();

        await userEvent.click(screen.getByRole('button', { name: 'Save' }));

        expect(mockUpdateExamMutation).not.toHaveBeenCalled();
    });
});
