import { Badge, Loader, Paper, PasswordInput, Select, Text, TextInput, Textarea, createTheme } from '@mantine/core';
import { DatePickerInput } from '@mantine/dates';

const theme = createTheme({
    primaryShade: 6,
    defaultRadius: 6,
    cursorType: 'pointer',
    other: {},
    components: {
        Badge: Badge.extend({
            defaultProps: {
                radius: 4,
            },
        }),
        DatePickerInput: DatePickerInput.extend({
            defaultProps: {
                highlightToday: true,
                allowDeselect: false,
            },
        }),
        Loader: Loader.extend({
            defaultProps: {
                type: 'dots',
            },
        }),
        Paper: Paper.extend({
            defaultProps: {
                shadow: 'xs',
            },
        }),
        PasswordInput: PasswordInput.extend({
            defaultProps: {
                maxLength: 255,
                autoComplete: 'off',
            },
        }),
        Select: Select.extend({
            defaultProps: {
                checkIconPosition: 'right',
            },
        }),
        Text: Text.extend({
            defaultProps: {
                size: 'sm',
            },
        }),
        TextInput: TextInput.extend({
            defaultProps: {
                maxLength: 255,
                autoComplete: 'off',
            },
        }),
        Textarea: Textarea.extend({
            defaultProps: {
                maxLength: 255,
            },
        }),
    },
});

export default theme;
