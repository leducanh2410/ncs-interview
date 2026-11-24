import React, { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { LuCheck } from 'react-icons/lu';
import { Box, MantineSpacing, Tabs, Text, TextInput, Textarea, ThemeIcon } from '@mantine/core';
import { UseFormReturnType } from '@mantine/form';
import { EXAM_CONTENT_LANGUAGES, ExamContentLanguage } from '@/configs/exam_language.config';

type Props<T> = {
    form: UseFormReturnType<T>;
    path: string;
    enabledLanguages: ExamContentLanguage[];
    label?: string;
    placeholder?: string;
    withAsterisk?: boolean;
    maxLength?: number;
    multiline?: boolean;
    disabled?: boolean;
    mb?: MantineSpacing;
    style?: React.CSSProperties;
};

function getByPath(obj: unknown, path: string): unknown {
    return path.split('.').reduce<unknown>((acc, key) => {
        if (acc === null || acc === undefined) return acc;
        return (acc as Record<string, unknown>)[key];
    }, obj);
}

/**
 * Renders one tab per language in `enabledLanguages`, each holding a text
 * field bound to `${path}.${lang}` on the given form. Tabs highlight live as
 * the admin types: green check once a language has a non-empty value, red
 * once that language fails validation (missing on submit, or right after
 * it's cleared/blurred).
 *
 * Subscribes to every language in EXAM_CONTENT_LANGUAGES (not just
 * `enabledLanguages`) so the number of `form.watch` calls - and the hooks
 * they set up internally - stays constant across renders even as
 * `enabledLanguages` grows/shrinks when the admin toggles language
 * checkboxes; only rendering is scoped to the enabled subset.
 */
export default function DLanguageTabsInput<T>({
    form,
    path,
    enabledLanguages,
    label,
    placeholder,
    withAsterisk,
    maxLength,
    multiline,
    disabled,
    mb,
    style,
}: Props<T>) {
    const { t } = useTranslation();
    const [active, setActive] = useState<string>(enabledLanguages[0] ?? EXAM_CONTENT_LANGUAGES[0]);
    const [fieldValues, setFieldValues] = useState<Record<string, string>>(() =>
        Object.fromEntries(
            EXAM_CONTENT_LANGUAGES.map((lang) => [lang, String(getByPath(form.getValues(), `${path}.${lang}`) ?? '')]),
        ),
    );

    EXAM_CONTENT_LANGUAGES.forEach((lang) => {
        form.watch(`${path}.${lang}` as never, ({ value }) => {
            setFieldValues((prev) => ({ ...prev, [lang]: String(value ?? '') }));
        });
    });

    useEffect(() => {
        if (!enabledLanguages.includes(active as ExamContentLanguage) && enabledLanguages.length > 0) {
            setActive(enabledLanguages[0]);
        }
    }, [enabledLanguages, active]);

    const languageLabels: Record<string, string> = {
        vi: t('Vietnamese'),
        en: t('English'),
        ja: t('Japanese'),
        ne: t('Nepali'),
        si: t('Sinhala'),
    };

    const InputComponent = multiline ? Textarea : TextInput;

    return (
        <Box mb={mb} style={style}>
            {label && (
                <Text size="sm" fw={500} mb={4}>
                    {label}
                    {withAsterisk && (
                        <Text component="span" c="red">
                            {' '}
                            *
                        </Text>
                    )}
                </Text>
            )}
            <Tabs value={active} onChange={(value) => value && setActive(value)} keepMounted={false}>
                <Tabs.List>
                    {enabledLanguages.map((lang) => {
                        const hasError = Boolean(form.errors[`${path}.${lang}`]);
                        const isFilled = fieldValues[lang].trim().length > 0;
                        return (
                            <Tabs.Tab
                                key={lang}
                                value={lang}
                                c={hasError ? 'red' : undefined}
                                leftSection={
                                    !hasError && isFilled ? (
                                        <ThemeIcon color="green" size={16} radius="xl" variant="light">
                                            <LuCheck size={11} />
                                        </ThemeIcon>
                                    ) : undefined
                                }
                            >
                                {languageLabels[lang]}
                                {hasError && ' *'}
                            </Tabs.Tab>
                        );
                    })}
                </Tabs.List>
                {enabledLanguages.map((lang) => (
                    <Tabs.Panel key={lang} value={lang} pt="xs">
                        <InputComponent
                            maxLength={maxLength}
                            placeholder={placeholder}
                            {...form.getInputProps(`${path}.${lang}`)}
                            key={form.key(`${path}.${lang}`)}
                            disabled={disabled}
                        />
                    </Tabs.Panel>
                ))}
            </Tabs>
        </Box>
    );
}
