import React from 'react';
import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import DLanguageTabsInput from './DLanguageTabsInput';

vi.mock('react-i18next', () => ({
    useTranslation: () => ({ t: (key: string) => key }),
}));

vi.mock('react-icons/lu', () => ({
    LuCheck: () => React.createElement('span', { 'data-testid': 'check-icon' }, 'check'),
}));

vi.mock('@mantine/core', () => {
    const Tabs = ({ value, onChange, children }: any) =>
        React.createElement(
            'div',
            null,
            React.Children.map(children, (child: any) =>
                child ? React.cloneElement(child, { activeValue: value, onTabChange: onChange }) : child,
            ),
        );
    Tabs.List = ({ children, activeValue, onTabChange }: any) =>
        React.createElement(
            'div',
            { role: 'tablist' },
            React.Children.map(children, (child: any) => React.cloneElement(child, { activeValue, onTabChange })),
        );
    Tabs.Tab = ({ children, value, activeValue, onTabChange, leftSection, c }: any) =>
        React.createElement(
            'button',
            {
                'role': 'tab',
                'aria-selected': value === activeValue,
                'data-color': c,
                'onClick': () => onTabChange(value),
            },
            [leftSection, children],
        );
    Tabs.Panel = ({ children, value, activeValue }: any) =>
        value === activeValue ? React.createElement('div', { role: 'tabpanel' }, children) : null;

    return {
        Box: ({ children, ...rest }: any) => React.createElement('div', rest, children),
        Text: ({ children, ...rest }: any) => React.createElement('span', rest, children),
        TextInput: ({ label, defaultValue, onChange, maxLength }: any) =>
            React.createElement('input', {
                'aria-label': label || 'content',
                defaultValue,
                maxLength,
                onChange,
            }),
        Textarea: ({ label, defaultValue, onChange }: any) =>
            React.createElement('textarea', { 'aria-label': label || 'content', defaultValue, onChange }),
        ThemeIcon: ({ children }: any) => React.createElement('span', { 'data-testid': 'theme-icon' }, children),
        Tabs,
    };
});

/**
 * Minimal fake mirroring the subset of Mantine's UseFormReturnType that
 * DLanguageTabsInput consumes: nested-path get/set plus `watch` subscribers,
 * so tests can simulate typing and assert the live highlight reacts.
 */
function createFakeForm(initialValues: Record<string, unknown>, errors: Record<string, string> = {}) {
    const values = structuredClone(initialValues);
    const watchers: Record<string, ((input: { value: unknown }) => void)[]> = {};

    const getByPath = (obj: any, path: string) => path.split('.').reduce((acc, key) => acc?.[key], obj);
    const setByPath = (obj: any, path: string, value: unknown) => {
        const keys = path.split('.');
        let cur = obj;
        for (let i = 0; i < keys.length - 1; i++) cur = cur[keys[i]];
        cur[keys[keys.length - 1]] = value;
    };

    return {
        errors,
        getValues: () => values,
        watch: (path: string, cb: (input: { value: unknown }) => void) => {
            watchers[path] = watchers[path] || [];
            watchers[path].push(cb);
        },
        getInputProps: (path: string) => ({
            defaultValue: getByPath(values, path),
            onChange: (e: any) => {
                const value = e?.currentTarget ? e.currentTarget.value : e;
                setByPath(values, path, value);
                (watchers[path] || []).forEach((cb) => cb({ value }));
            },
        }),
        key: (path: string) => path,
    } as any;
}

describe('DLanguageTabsInput', () => {
    it('renders one tab per supported language', () => {
        const form = createFakeForm({ title: { vi: '', en: '', ja: '', ne: '', si: '' } });
        render(<DLanguageTabsInput form={form} path="title" enabledLanguages={['vi', 'en', 'ja', 'ne', 'si']} />);

        expect(screen.getAllByRole('tab')).toHaveLength(5);
    });

    it('marks a tab red when the form reports a validation error for it', () => {
        const form = createFakeForm(
            { title: { vi: '', en: 'Hello', ja: '', ne: '', si: '' } },
            { 'title.vi': 'Required' },
        );
        render(<DLanguageTabsInput form={form} path="title" enabledLanguages={['vi', 'en', 'ja', 'ne', 'si']} />);

        const viTab = screen.getAllByRole('tab')[0];
        expect(viTab.getAttribute('data-color')).toBe('red');
        expect(viTab.textContent).toContain('*');
    });

    it('does not show the green check for an empty, untouched language', () => {
        const form = createFakeForm({ title: { vi: '', en: '', ja: '', ne: '', si: '' } });
        render(<DLanguageTabsInput form={form} path="title" enabledLanguages={['vi', 'en', 'ja', 'ne', 'si']} />);

        expect(screen.queryByTestId('theme-icon')).not.toBeInTheDocument();
    });

    it('shows a green check for the active tab as soon as it has a value typed in', () => {
        const form = createFakeForm({ title: { vi: '', en: '', ja: '', ne: '', si: '' } });
        render(<DLanguageTabsInput form={form} path="title" enabledLanguages={['vi', 'en', 'ja', 'ne', 'si']} />);

        fireEvent.change(screen.getByRole('textbox'), { target: { value: 'Ky thi' } });

        expect(screen.getByTestId('theme-icon')).toBeInTheDocument();
    });

    it('switches panels when a different language tab is clicked', () => {
        const form = createFakeForm({ title: { vi: 'Xin chao', en: 'Hello', ja: '', ne: '', si: '' } });
        render(<DLanguageTabsInput form={form} path="title" enabledLanguages={['vi', 'en', 'ja', 'ne', 'si']} />);

        expect(screen.getByRole('textbox')).toHaveValue('Xin chao');

        fireEvent.click(screen.getAllByRole('tab')[1]);

        expect(screen.getByRole('textbox')).toHaveValue('Hello');
    });

    it('only renders tabs for the languages in enabledLanguages', () => {
        const form = createFakeForm({ title: { vi: '', en: '', ja: '', ne: '', si: '' } });
        render(<DLanguageTabsInput form={form} path="title" enabledLanguages={['en', 'ja']} />);

        expect(screen.getAllByRole('tab')).toHaveLength(2);
    });

    it('does not render a tab for a language with a value once it is disabled', () => {
        const form = createFakeForm({ title: { vi: 'Xin chao', en: 'Hello', ja: '', ne: '', si: '' } });
        render(<DLanguageTabsInput form={form} path="title" enabledLanguages={['en']} />);

        expect(screen.getAllByRole('tab')).toHaveLength(1);
        expect(screen.getByRole('textbox')).toHaveValue('Hello');
    });
});
