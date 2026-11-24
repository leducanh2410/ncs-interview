import React, { useEffect, useMemo, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { LuArrowLeft, LuArrowRight, LuInfo, LuLanguages, LuPlus, LuUpload } from 'react-icons/lu';
import Link from 'next/link';
import { useRouter } from 'next/router';
import {
    Alert,
    Box,
    Button,
    Checkbox,
    ComboboxItem,
    Group,
    MultiSelect,
    NumberInput,
    Paper,
    Radio,
    Select,
    Stepper,
    Text,
    Tooltip,
} from '@mantine/core';
import { DateTimePicker } from '@mantine/dates';
import { FormErrors, useForm } from '@mantine/form';
import { useAtom } from 'jotai';
import { Validator, validatorResolver } from '@/validator';
import { Document } from '@/types/models/document';
import { ExamWithRelation } from '@/types/models/exam';
import { Option, Question } from '@/types/models/question';
import { ApiConfig } from '@/configs/api.config';
import {
    EXAM_CONTENT_LANGUAGES,
    ExamContentLanguage,
    OPTIONAL_EXAM_LANGUAGES,
    REQUIRED_EXAM_LANGUAGE,
    TranslatableText,
    emptyTranslatableText,
} from '@/configs/exam_language.config';
import RouteConfig from '@/configs/route.config';
import { ApiValidationError } from '@/helpers/api.helper';
import DateHelper, { DATE_STORE_FORMAT_WITH_TIME } from '@/helpers/date.helper';
import FormHelper from '@/helpers/form.helper';
import NotificationHelper from '@/helpers/notification.helper';
import TranslateHelper from '@/helpers/translate.helper';
import DLanguageTabsInput from '@/components/DLanguageTabsInput';
import DSelectRemoteMultiple from '@/components/DSelectRemoteMultiple';
import { DUserSelectRemoteMultiple } from '@/components/DUserSelectRemote';
import { masterAtom } from '@/pages/global.state';
import CreateDocumentModal from '@/pages/manage/document/partials/create_document_modal';
import { TranslateItem, useTranslateContentMutation } from '@/pages/translate.api';
import QuestionFormList from './QuestionFormList';
import {
    EXAM_FORM_LAST_STEP,
    EXAM_FORM_STEP,
    ExamFormStep,
    getFirstErrorStep,
    pickStepErrors,
} from './exam_form_steps';
import { useCreateExamMutation, useUpdateExamMutation } from '@/pages/manage/exam/api';

/**
 * Which audience the exam goes to. Form state only: it is never submitted,
 * because the API derives the same distinction from whether `targeted_users`
 * is empty. That is also why "named employees with nobody named" has to be
 * blocked here - once it reaches the API it is indistinguishable from
 * "whole workplaces".
 */
export type DeliveryMode = 'workplaces' | 'users';

export type ExamFormType = {
    title: TranslatableText;
    languages: ExamContentLanguage[];
    type: 'assessment' | 'external_form_prep';
    is_required: boolean;
    is_recurring: boolean;
    start_at: string | null;
    end_at: string | null;
    retry_limit: number;
    min_score: number;
    time_limit: number;
    show_result_immediately: boolean;
    external_url: TranslatableText;
    workplaces: string[];
    delivery_mode: DeliveryMode;
    targeted_users: string[];
    documents: string[];
    questions: Question[];
};

export type QuestionList = {
    order: Array<number | string>;
    question_order: Record<number | string, Array<number | string>>;
};

const initialExam: ExamFormType = {
    title: emptyTranslatableText(),
    languages: [REQUIRED_EXAM_LANGUAGE],
    type: 'assessment',
    is_required: false,
    is_recurring: false,
    start_at: null,
    end_at: null,
    retry_limit: 0,
    min_score: 50,
    time_limit: 30,
    show_result_immediately: false,
    external_url: emptyTranslatableText(),
    workplaces: [],
    delivery_mode: 'workplaces',
    targeted_users: [],
    documents: [],
    questions: [
        {
            id: 'blank',
            content: emptyTranslatableText(),
            options: [
                {
                    id: 'blank_answer',
                    content: emptyTranslatableText(),
                    is_correct: false,
                },
            ],
        },
    ],
};

/**
 * Build a `{ vi: fn, en: fn, ... }` validate map where only languages
 * currently in `values.languages` are required - the rest are skipped
 * entirely (not just "not required") so an empty draft in an unselected
 * language's tab never trips the minLength check.
 */
function translatableValidator(label: string, minLength: number) {
    return Object.fromEntries(
        EXAM_CONTENT_LANGUAGES.map((lang) => [
            lang,
            (value: string, values: Record<string, unknown>, field: string): string | null => {
                if (!(values as unknown as ExamFormType).languages.includes(lang)) {
                    return null;
                }
                return Validator.string().required().minLength(minLength).label(label).getValidation()(
                    value,
                    values as unknown as Record<string, string>,
                    field,
                );
            },
        ]),
    );
}

/**
 * Same idea as translatableValidator(), but for the per-language Google
 * Form link: only validated (required + URL format) when the exam type is
 * external_form_prep AND the language is selected - otherwise the hidden/
 * unselected field is skipped entirely regardless of its draft content.
 */
function translatableUrlValidator(label: string) {
    return Object.fromEntries(
        EXAM_CONTENT_LANGUAGES.map((lang) => [
            lang,
            (value: string, values: Record<string, unknown>, field: string): string | null => {
                const typedValues = values as unknown as ExamFormType;
                if (typedValues.type !== 'external_form_prep' || !typedValues.languages.includes(lang)) {
                    return null;
                }
                return Validator.string().required().url().label(label).getValidation()(
                    value,
                    values as unknown as Record<string, string>,
                    field,
                );
            },
        ]),
    );
}

const initialQuestionList: QuestionList = {
    order: ['blank'],
    question_order: {
        blank: ['blank_answer'],
    },
};

type ExamFormProps = {
    examData?: ExamWithRelation | null | undefined;
    /**
     * When true, `examData` is a copy source rather than the exam being
     * edited: workplaces and the schedule must not carry over, since the
     * whole point of copying is retargeting to a different workshop.
     */
    isCopy?: boolean;
};

/**
 * Once the persisted start_at has passed, the field is locked: the admin cannot
 * shift the activation moment after the exam has already become visible.
 */
function isStartAtLocked(startAt: string | null | undefined): boolean {
    if (!startAt) {
        return false;
    }
    const parsed = DateHelper.parse(startAt, DATE_STORE_FORMAT_WITH_TIME);
    if (!parsed) {
        return false;
    }
    return !parsed.isAfter(DateHelper.now());
}

function toDocumentOption(response: unknown): ComboboxItem | null {
    const body = response as { id?: number; title?: string; data?: { id?: number; title?: string } } | null;
    const createdDocument = body?.data?.id !== undefined ? body.data : body;
    if (!createdDocument || createdDocument.id === undefined) {
        return null;
    }
    return { value: createdDocument.id.toString(), label: createdDocument.title || createdDocument.id.toString() };
}

export default function ExamForm({ examData, isCopy = false }: ExamFormProps) {
    const { t } = useTranslation();
    const { push, query } = useRouter();
    const examId = query.id as string;
    const { mutateAsync: createMutation } = useCreateExamMutation();
    const { mutateAsync: updateExamMutation } = useUpdateExamMutation(examId);
    const [masterData] = useAtom(masterAtom);
    const [questionList, setQuestionList] = useState<QuestionList>(initialQuestionList);
    const [activeStep, setActiveStep] = useState<ExamFormStep>(EXAM_FORM_STEP.general);
    const [isDocumentModalOpened, setIsDocumentModalOpened] = useState(false);
    const [uploadedDocumentOptions, setUploadedDocumentOptions] = useState<ComboboxItem[]>([]);
    const deferQuestionCountCheckRef = useRef(false);
    const isEditMode = !!examData && !isCopy;
    const isStructureLocked = isEditMode && !!examData?.has_results && examData.type !== 'external_form_prep';
    const canSave = isEditMode || activeStep === EXAM_FORM_LAST_STEP;

    const form = useForm<ExamFormType>({
        mode: 'uncontrolled',
        initialValues: initialExam,
        validate: {
            title: translatableValidator(t('field|title'), 3),
            type: (value: string, values: ExamFormType): string | null =>
                Validator.string().required().isSelect().label(t('field|type')).getValidation()(
                    value,
                    values as unknown as Record<string, string>,
                    'type',
                ),
            external_url: translatableUrlValidator(t('field|external_url')),
            ...validatorResolver({
                retry_limit: Validator.number().gte(0).integer().label(t('field|retry_limit')),
                time_limit: Validator.number().gte(1).integer().label(t('field|time_limit')),
            }),
            min_score: (value: number, values: ExamFormType): string | null => {
                // Hidden and server-defaulted for external_form_prep — no client
                // validation applies (the default 50 would otherwise trip the
                // "cannot exceed question count" check below against a form
                // that only has 1 blank question by default).
                if (values.type === 'external_form_prep') {
                    return null;
                }
                const baseError = Validator.number().gte(0).integer().label(t('field|min_score')).getValidation()(
                    value,
                    values as unknown as Record<string, number>,
                    'min_score',
                );
                if (baseError) {
                    return baseError;
                }
                if (!deferQuestionCountCheckRef.current && value > values.questions.length) {
                    return t('The passing score cannot exceed the number of questions ({{count}})', {
                        count: values.questions.length,
                    });
                }
                return null;
            },
            workplaces: (value: string[], values: ExamFormType): string | null => {
                // Copying starts with no target workplace so the admin is
                // forced to retarget it — leaving it empty would silently
                // recreate the source exam as global (visible to everyone).
                if (isCopy && (!value || value.length === 0)) {
                    return t('Please select a target workplace before saving the copy');
                }
                if (values.is_recurring && values.type !== 'external_form_prep' && (!value || value.length === 0)) {
                    return t('A recurring exam needs at least one workplace');
                }
                return null;
            },
            // The API reads an empty `targeted_users` as "every member of the
            // selected workplaces", so "named employees with nobody named"
            // can only be caught here, where the admin's intent exists.
            targeted_users: (value: string[], values: ExamFormType): string | null => {
                if (values.delivery_mode !== 'users') {
                    return null;
                }
                if (values.workplaces.length === 0) {
                    return t('Select at least one workplace before naming employees');
                }
                if (value.length === 0) {
                    return t('Select at least one employee');
                }
                return null;
            },
            start_at: (value: string | null, values: ExamFormType): string | null => {
                // Copying clears the schedule so the admin must pick a new
                // start date — leaving it empty would activate the copied
                // (possibly required) exam immediately on save.
                if (isCopy && !value) {
                    return t('Please set a start date before saving the copy');
                }
                if (!value || !values.end_at) {
                    return null;
                }
                if (value >= values.end_at) {
                    return t('Start date must be before end date');
                }
                return null;
            },
            questions: {
                content: translatableValidator(t('field|content'), 1),
                options: {
                    content: translatableValidator(t('field|content'), 1),
                },
            },
        },
    });
    const startLocked = useMemo(
        () => (isCopy ? false : isStartAtLocked(examData?.start_at ?? null)),
        [examData?.start_at, isCopy],
    );
    const isRequired = form.values.is_required;

    // The form is uncontrolled, so a field change only re-renders when its
    // dirty status flips against the initial snapshot — and form.setValues()
    // (loading an exam to edit) never updates that snapshot. Selecting the
    // snapshot's own value back (external_form_prep → assessment on a loaded
    // external exam) would therefore not re-render at all, leaving the UI
    // stuck in external mode. Mirror `type` into React state via form.watch,
    // which fires on every change regardless of dirty status.
    const [typeValue, setTypeValue] = useState<ExamFormType['type']>(initialExam.type);
    form.watch('type', ({ value, previousValue }) => {
        setTypeValue(value);
        // The Google Form link tabs unmount once the type leaves
        // external_form_prep, but their draft values stay in the form state
        // and still get submitted - possibly mid-edit and invalid (e.g.
        // "docs.goog"). translatableUrlValidator() skips them client-side for
        // this type, but the backend's per-language `nullable|url` rule does
        // not, so a stale invalid URL silently 422s a save the admin can no
        // longer see the field for. Clear it whenever the type actually
        // changes away from external_form_prep.
        if (previousValue === 'external_form_prep' && value !== 'external_form_prep') {
            form.setFieldValue('external_url', emptyTranslatableText());
        }
    });
    const isExternalFormPrep = typeValue === 'external_form_prep';

    const [isRecurring, setIsRecurring] = useState(initialExam.is_recurring);
    form.watch('is_recurring', ({ value }) => setIsRecurring(value));

    const onRecurringChange = (checked: boolean) => {
        form.setFieldValue('is_recurring', checked);
        if (checked) {
            form.setFieldValue('is_required', true);
            form.setFieldValue('end_at', null);
        }
    };

    const [showResultImmediately, setShowResultImmediately] = useState(initialExam.show_result_immediately);
    form.watch('show_result_immediately', ({ value }) => setShowResultImmediately(value));

    // Same "uncontrolled form snapshot" issue as `type` above: `languages`
    // is bulk-assigned via form.setValues() when loading an exam to edit, so
    // reading form.values.languages directly wouldn't reliably re-render
    // every DLanguageTabsInput's enabled tabs.
    const [languagesValue, setLanguagesValue] = useState<ExamContentLanguage[]>(initialExam.languages);
    form.watch('languages', ({ value }) => setLanguagesValue(value));

    // Same reason again: the delivery mode has to re-render its siblings (the
    // employee selector and the audience summary), and the workplace
    // selection has to re-render both the disabled state of the "named
    // employees" option and the scope the selector searches in. Neither
    // happens through getInputProps alone on an uncontrolled form.
    const [deliveryMode, setDeliveryMode] = useState<DeliveryMode>(initialExam.delivery_mode);
    form.watch('delivery_mode', ({ value }) => setDeliveryMode(value));

    const [workplacesValue, setWorkplacesValue] = useState<string[]>(initialExam.workplaces);
    form.watch('workplaces', ({ value }) => setWorkplacesValue(value));

    const hasWorkplace = workplacesValue.length > 0;
    const isNamedEmployeesMode = deliveryMode === 'users';

    // `is_current_member` is computed by the API against the exam's *saved*
    // workplaces, so it only describes the audience as it was loaded. The
    // moment the admin edits the workplace selection the flag can be wrong in
    // both directions (a chip that just went stale still looks fine, a
    // re-added workplace keeps a warning it no longer deserves), and the form
    // has no per-employee membership data to recompute it from. So the marker
    // is only shown while the selection still matches what was saved.
    const savedWorkplaceIds = useMemo(
        () => (examData?.workplaces ?? []).map((workplace) => workplace.id.toString()),
        [examData],
    );
    const matchesSavedWorkplaces = useMemo(() => {
        if (savedWorkplaceIds.length !== workplacesValue.length) {
            return false;
        }
        const saved = new Set(savedWorkplaceIds);
        return workplacesValue.every((id) => saved.has(id));
    }, [savedWorkplaceIds, workplacesValue]);

    // Restored chips need their labels from somewhere: the selector renders a
    // chip from its cached options, so a value array alone would show raw
    // ids. Labels match the search endpoint's format so the same control
    // never shows two different name forms.
    const targetedUserOptions = useMemo<ComboboxItem[]>(
        () =>
            (examData?.targeted_users ?? []).map((user) => {
                const label = `${user.name} (${user.staff_no || '-'})`;
                return {
                    value: user.id.toString(),
                    label:
                        user.is_current_member || !matchesSavedWorkplaces
                            ? label
                            : `${label} - ${t('no longer in the selected workplaces')}`,
                };
            }),
        [examData, matchesSavedWorkplaces, t],
    );

    // external_form_prep only ever enforces a mandatory external step — force
    // is_required so the field can't be left unchecked for this type.
    useEffect(() => {
        if (isExternalFormPrep && !form.values.is_required) {
            form.setFieldValue('is_required', true);
        }
        if (isExternalFormPrep && form.getValues().is_recurring) {
            form.setFieldValue('is_recurring', false);
        }
    }, [isExternalFormPrep]);

    const toggleOptionalLanguage = (lang: ExamContentLanguage, checked: boolean) => {
        const next = checked ? [...languagesValue, lang] : languagesValue.filter((l) => l !== lang);
        form.setFieldValue('languages', next);
    };

    const translateMutation = useTranslateContentMutation();
    const [translateProgress, setTranslateProgress] = useState<{ done: number; total: number } | null>(null);
    const isTranslating = translateProgress !== null;

    const textAt = (map: TranslatableText | undefined, lang: string) =>
        ((map?.[lang as ExamContentLanguage] as string) || '').trim();

    const textAtPath = (values: ExamFormType, path: string, lang: ExamContentLanguage) => {
        const map = path.split('.').reduce<unknown>((current, segment) => {
            if (current === null || current === undefined) return undefined;
            return (current as Record<string, unknown>)[segment];
        }, values);
        return textAt(map as TranslatableText | undefined, lang);
    };

    const onTranslate = async () => {
        const values = form.getValues();

        // key => English source text / target form path, for every translatable field
        const sources: Record<string, TranslatableText> = {};
        const paths: Record<string, string> = {};
        const candidateItems: TranslateItem[] = [];
        const pushItem = (key: string, path: string, source: TranslatableText) => {
            const text = textAt(source, REQUIRED_EXAM_LANGUAGE);
            if (!text) {
                return;
            }
            sources[key] = source;
            paths[key] = path;
            candidateItems.push({ key, text });
        };

        pushItem('title', 'title', values.title);
        values.questions.forEach((question, questionIndex) => {
            pushItem(`q${questionIndex}_content`, `questions.${questionIndex}.content`, question.content);
            question.options.forEach((option, optionIndex) => {
                pushItem(
                    `q${questionIndex}_o${optionIndex}_content`,
                    `questions.${questionIndex}.options.${optionIndex}.content`,
                    option.content,
                );
            });
        });

        // URLs are language-neutral: copy the English link into empty enabled tabs instead of translating it.
        let copiedUrl = false;
        if (values.type === 'external_form_prep') {
            const englishUrl = textAt(values.external_url, REQUIRED_EXAM_LANGUAGE);
            if (englishUrl) {
                for (const lang of languagesValue) {
                    if (lang !== REQUIRED_EXAM_LANGUAGE && !textAt(values.external_url, lang)) {
                        form.setFieldValue(`external_url.${lang}`, englishUrl);
                        copiedUrl = true;
                    }
                }
            }
        }

        if (candidateItems.length === 0) {
            if (copiedUrl) {
                NotificationHelper.showSuccess(t('Translations filled in. Please review before saving'));
            } else {
                NotificationHelper.showInfo(t('Enter English content before translating'));
            }
            return;
        }

        // Group source items by their exact missing locale set, avoiding translations for fields already filled in.
        const enabledTargetLocales = languagesValue.filter((lang) => lang !== REQUIRED_EXAM_LANGUAGE);
        const itemGroups = new Map<string, { targetLocales: ExamContentLanguage[]; items: TranslateItem[] }>();
        for (const item of candidateItems) {
            const targetLocales = enabledTargetLocales.filter((lang) => !textAt(sources[item.key], lang));
            if (targetLocales.length === 0) {
                continue;
            }
            const groupKey = targetLocales.join(',');
            const group = itemGroups.get(groupKey) ?? { targetLocales, items: [] };
            group.items.push(item);
            itemGroups.set(groupKey, group);
        }
        const requests = Array.from(itemGroups.values()).flatMap(({ targetLocales, items }) =>
            TranslateHelper.chunkItems(items).map((chunk) => ({ chunk, targetLocales })),
        );
        if (requests.length === 0) {
            if (copiedUrl) {
                NotificationHelper.showSuccess(t('Translations filled in. Please review before saving'));
            } else {
                NotificationHelper.showInfo(t('All translations are already filled in'));
            }
            return;
        }

        setTranslateProgress({ done: 0, total: requests.length });
        try {
            for (const [requestIndex, { chunk, targetLocales }] of requests.entries()) {
                const response = await translateMutation.mutateAsync({
                    items: chunk,
                    source_locale: REQUIRED_EXAM_LANGUAGE,
                    target_locales: targetLocales,
                    context: 'exam',
                });
                const translations = response.data?.translations || {};
                for (const item of chunk) {
                    for (const lang of targetLocales) {
                        const translated = translations[lang]?.[item.key];
                        if (translated && !textAtPath(form.getValues(), paths[item.key], lang)) {
                            form.setFieldValue(`${paths[item.key]}.${lang}`, translated);
                        }
                    }
                }
                setTranslateProgress({ done: requestIndex + 1, total: requests.length });
            }
            NotificationHelper.showSuccess(t('Translations filled in. Please review before saving'));
        } catch {
            // Already-applied requests are kept; the next attempt filters out their completed items.
            NotificationHelper.showError(
                t('error|Automatic translation failed. Please try again or enter the translation manually'),
            );
        } finally {
            setTranslateProgress(null);
        }
    };

    useEffect(() => {
        if (!examData) {
            setQuestionList(initialQuestionList);
            form.setValues(initialExam);
            return;
        }

        const questions: Question[] = [];
        const questionList: QuestionList = {
            order: [],
            question_order: {},
        };
        for (const question of examData.questions) {
            questionList.order.push(question.id);
            questionList.question_order[question.id] = [];
            const options: Option[] = [];
            for (const option of question.options) {
                questionList.question_order[question.id].push(option.id);
                options.push({
                    id: option.id,
                    content: option.content,
                    is_correct: option.is_correct,
                });
            }
            questions.push({
                id: question.id,
                content: question.content,
                options,
            });
        }

        form.setValues({
            title: examData.title,
            languages: examData.languages?.length ? examData.languages : [REQUIRED_EXAM_LANGUAGE],
            type: examData.type ?? 'assessment',
            is_required: examData.is_required,
            is_recurring: examData.is_recurring ?? false,
            start_at: isCopy ? null : (examData.start_at ?? null),
            end_at: isCopy ? null : (examData.end_at ?? null),
            retry_limit: examData.retry_limit,
            min_score: examData.min_score,
            time_limit: examData.time_limit,
            show_result_immediately: !!examData.show_result_immediately,
            external_url: examData.external_url ?? emptyTranslatableText(),
            workplaces: isCopy ? [] : examData.workplaces.map((wp: any) => wp.id.toString()),
            // A copy starts with no workplace, so it cannot inherit the
            // source's named employees either: they belong to the source's
            // workplaces, and keeping them would build a payload the API
            // rejects outright (named employees with no workplace scope).
            // Copying resets the audience and makes the admin retarget it.
            //
            // Otherwise: derived, not stored - an exam with named employees is
            // in "named employees" mode by definition.
            delivery_mode: !isCopy && (examData.targeted_users ?? []).length > 0 ? 'users' : 'workplaces',
            targeted_users: isCopy ? [] : (examData.targeted_users ?? []).map((user) => user.id.toString()),
            documents: examData.documents.map((doc: any) => doc.id.toString()),
            questions: questions,
        });
        setQuestionList(questionList);
    }, [examData, isCopy]);

    const handleSubmit = async (values: ExamFormType) => {
        // `delivery_mode` stays out of the request: the API distinguishes the
        // two modes by whether `targeted_users` is empty. Switching back to
        // whole workplaces therefore has to submit an empty list even when
        // employees were named before the switch.
        const { delivery_mode, ...rest } = values;
        const payload = {
            ...rest,
            show_result_immediately: values.type === 'assessment' && values.show_result_immediately,
            is_recurring: values.type !== 'external_form_prep' && values.is_recurring,
            end_at: values.type !== 'external_form_prep' && values.is_recurring ? null : values.end_at,
            targeted_users: delivery_mode === 'users' ? values.targeted_users : [],
        };

        try {
            if (examId) {
                await updateExamMutation(payload);
                NotificationHelper.showSuccess(t('Successfully updated exam'));
            } else {
                await createMutation(payload);
                NotificationHelper.showSuccess(t('Successfully created exam'));
            }
            await push(RouteConfig.MANAGE_EXAM.path);
        } catch (err) {
            FormHelper.setFormError(form, err);
            if (err instanceof ApiValidationError) {
                goToFirstErrorStep(Object.keys(err.data));
            }
        }
    };

    const goToFirstErrorStep = (errorPaths: string[]) => {
        const step = getFirstErrorStep(errorPaths);
        if (step !== null) {
            setActiveStep(step);
        }
    };

    const handleInvalidSubmit = (errors: FormErrors) => {
        goToFirstErrorStep(Object.keys(errors));
    };

    const validateStep = (step: ExamFormStep): boolean => {
        deferQuestionCountCheckRef.current = step !== EXAM_FORM_LAST_STEP;
        const { errors } = form.validate();
        deferQuestionCountCheckRef.current = false;
        const stepErrors = pickStepErrors(errors, step);
        form.setErrors(stepErrors);
        return Object.keys(stepErrors).length === 0;
    };

    const goToNextStep = () => {
        if (activeStep >= EXAM_FORM_LAST_STEP || !validateStep(activeStep)) {
            return;
        }
        setActiveStep((activeStep + 1) as ExamFormStep);
    };

    const goToPreviousStep = () => {
        if (activeStep > EXAM_FORM_STEP.general) {
            setActiveStep((activeStep - 1) as ExamFormStep);
        }
    };

    const handleStepClick = (step: number) => {
        if (isEditMode || step < activeStep) {
            setActiveStep(step as ExamFormStep);
        }
    };

    const handleFormSubmit = (event: React.FormEvent<HTMLFormElement>) => {
        if (!canSave) {
            event.preventDefault();
            goToNextStep();
            return;
        }
        form.onSubmit(handleSubmit, handleInvalidSubmit)(event);
    };

    const documentOptions = useMemo<ComboboxItem[]>(
        () => [
            ...(examData?.documents ?? []).map((doc) => ({
                value: doc.id.toString(),
                label: doc.title,
            })),
            ...uploadedDocumentOptions,
        ],
        [examData, uploadedDocumentOptions],
    );

    const handleDocumentCreated = (response: unknown) => {
        const option = toDocumentOption(response);
        if (!option) {
            return;
        }
        setUploadedDocumentOptions((current) => [...current, option]);
        const selected = form.getValues().documents;
        if (!selected.includes(option.value)) {
            form.setFieldValue('documents', [...selected, option.value]);
        }
    };

    const languageLabels: Record<ExamContentLanguage, string> = {
        vi: t('Vietnamese'),
        en: t('English'),
        ja: t('Japanese'),
        ne: t('Nepali'),
        si: t('Sinhala'),
    };

    return (
        <Paper p="md" mt="md" radius="md" withBorder>
            <Stepper
                active={activeStep}
                onStepClick={handleStepClick}
                allowNextStepsSelect={isEditMode}
                size="sm"
                mb="xl"
            >
                <Stepper.Step label={t('General information')} />
                <Stepper.Step label={t('Documents')} />
                <Stepper.Step label={t('Questions')} />
            </Stepper>
            <form onSubmit={handleFormSubmit}>
                <Box display={activeStep === EXAM_FORM_STEP.general ? 'block' : 'none'}>
                    <Box mb="md">
                        <Text size="sm" fw={500} mb={4}>
                            {t('Languages')}
                        </Text>
                        <Group>
                            <Checkbox label={languageLabels[REQUIRED_EXAM_LANGUAGE]} checked disabled />
                            {OPTIONAL_EXAM_LANGUAGES.map((lang) => (
                                <Checkbox
                                    key={lang}
                                    label={languageLabels[lang]}
                                    checked={languagesValue.includes(lang)}
                                    onChange={(event) => toggleOptionalLanguage(lang, event.currentTarget.checked)}
                                    disabled={isTranslating}
                                />
                            ))}
                            <Button
                                size="xs"
                                variant="light"
                                leftSection={<LuLanguages size={14} />}
                                loading={isTranslating}
                                onClick={onTranslate}
                            >
                                {translateProgress
                                    ? t('Translating... {{done}}/{{total}}', {
                                          done: translateProgress.done,
                                          total: translateProgress.total,
                                      })
                                    : t('AI translate')}
                            </Button>
                        </Group>
                    </Box>

                    <DLanguageTabsInput
                        form={form}
                        path="title"
                        enabledLanguages={languagesValue}
                        label={t('Exam name')}
                        withAsterisk
                        maxLength={255}
                        mb="md"
                        disabled={isTranslating}
                    />

                    <Select
                        label={t('Exam type')}
                        withAsterisk
                        allowDeselect={false}
                        data={[
                            { value: 'assessment', label: t('Knowledge assessment') },
                            { value: 'external_form_prep', label: t('External form preparation') },
                        ]}
                        {...form.getInputProps('type')}
                        key={form.key('type')}
                        mb="md"
                    />

                    {isExternalFormPrep && (
                        <DLanguageTabsInput
                            form={form}
                            path="external_url"
                            enabledLanguages={languagesValue}
                            label={t('Google Form link')}
                            placeholder="https://docs.google.com/forms/d/e/.../viewform"
                            withAsterisk
                            mb="md"
                            disabled={isTranslating}
                        />
                    )}

                    <MultiSelect
                        label={t('Workplace')}
                        withAsterisk={isCopy}
                        data={masterData.workplaces.map((workplace) => {
                            return {
                                label: workplace.name,
                                value: workplace.id.toString(),
                            };
                        })}
                        searchable
                        {...form.getInputProps('workplaces')}
                        key={form.key('workplaces')}
                        mb="md"
                    />

                    <Radio.Group
                        label={t('Who takes this exam')}
                        mb="md"
                        {...form.getInputProps('delivery_mode')}
                        key={form.key('delivery_mode')}
                    >
                        <Group mt="xs">
                            <Radio value="workplaces" label={t('Every employee of the selected workplaces')} />
                            <Radio
                                value="users"
                                disabled={!hasWorkplace}
                                label={t('Only the employees named below')}
                                description={hasWorkplace ? undefined : t('Select a workplace first')}
                            />
                        </Group>
                    </Radio.Group>

                    {isNamedEmployeesMode && (
                        <Box mb="md">
                            {/*
                             * Search is locked without a workplace: an empty
                             * `workplace_ids` is dropped from the query string, so
                             * it would fall back to every employee in the system -
                             * candidates this exam could never reach. Only the
                             * search is locked, not the whole control (`disabled`),
                             * because the natural way out of this state is to clear
                             * the now-out-of-scope names before picking the new
                             * workplaces; freezing the chips would force the admin
                             * to re-pick a scope first, or to round-trip through
                             * "every employee" mode, which drops the list entirely
                             * on save. The mode itself also stays selected (its
                             * radio is disabled the same way) so the named
                             * employees remain visible while the admin reworks the
                             * workplaces.
                             */}
                            <DUserSelectRemoteMultiple
                                label={t('Employees')}
                                placeholder={
                                    hasWorkplace
                                        ? t('Type at least 3 characters to search by name, employee code or email')
                                        : t('Select a workplace first')
                                }
                                extraParams={{ workplace_ids: workplacesValue }}
                                initialOptions={targetedUserOptions}
                                searchDisabled={!hasWorkplace}
                                {...form.getInputProps('targeted_users')}
                                key={form.key('targeted_users')}
                            />
                            <Text size="xs" c="dimmed" mt={4}>
                                {hasWorkplace
                                    ? t(
                                          'Only these employees receive this exam. Other members of the selected workplaces do not.',
                                      )
                                    : t('Select a workplace first')}
                            </Text>
                        </Box>
                    )}

                    <Group mb="md" grow align="flex-start">
                        <Box mt="lg">
                            <Checkbox
                                label={t('Required')}
                                checked={isRequired}
                                disabled={isExternalFormPrep || isRecurring}
                                description={
                                    isExternalFormPrep
                                        ? t('External form preparation exams are always required')
                                        : undefined
                                }
                                onChange={(event) => {
                                    form.setFieldValue('is_required', event.currentTarget.checked);
                                }}
                                key={form.key('is_required')}
                            />
                            {!isExternalFormPrep && (
                                <Tooltip
                                    label={t(
                                        'Asked again on the first shift, on every 10th shift after the previous time, and after a gap of more than 30 days',
                                    )}
                                    multiline
                                    maw={320}
                                    withArrow
                                >
                                    <Checkbox
                                        mt="xs"
                                        label={t('Recurring')}
                                        checked={isRecurring}
                                        onChange={(event) => onRecurringChange(event.currentTarget.checked)}
                                        key={form.key('is_recurring')}
                                    />
                                </Tooltip>
                            )}
                        </Box>
                        <DateTimePicker
                            label={t('Start date')}
                            withAsterisk={isCopy}
                            valueFormat={t('FORMAT_DATE_WITH_TIME')}
                            clearable
                            disabled={startLocked}
                            description={startLocked ? t('Locked because the exam has already started') : undefined}
                            {...form.getInputProps('start_at')}
                            key={form.key('start_at')}
                        />
                        <DateTimePicker
                            label={t('Completion deadline')}
                            valueFormat={t('FORMAT_DATE_WITH_TIME')}
                            clearable
                            disabled={isRecurring && !isExternalFormPrep}
                            {...form.getInputProps('end_at')}
                            key={form.key('end_at')}
                        />
                    </Group>

                    {!isExternalFormPrep && (
                        <Group grow mb="md">
                            <NumberInput
                                label={t('Retry limit')}
                                withAsterisk
                                min={0}
                                disabled={isRequired}
                                description={isRequired ? t('Required exams do not have a retry limit') : undefined}
                                {...form.getInputProps('retry_limit')}
                                key={form.key('retry_limit')}
                            />
                            <NumberInput
                                label={t('Min score')}
                                withAsterisk
                                min={0}
                                {...form.getInputProps('min_score')}
                                key={form.key('min_score')}
                            />
                            <NumberInput
                                label={t('Time limit (minutes)')}
                                withAsterisk
                                min={1}
                                {...form.getInputProps('time_limit')}
                                key={form.key('time_limit')}
                            />
                        </Group>
                    )}

                    {!isExternalFormPrep && (
                        <Checkbox
                            label={t('Show results immediately after submission')}
                            checked={showResultImmediately}
                            onChange={(event) => {
                                form.setFieldValue('show_result_immediately', event.currentTarget.checked);
                            }}
                            key={form.key('show_result_immediately')}
                            mb="md"
                        />
                    )}
                </Box>

                <Box display={activeStep === EXAM_FORM_STEP.documents ? 'block' : 'none'}>
                    <DSelectRemoteMultiple<Document>
                        label={t('Document')}
                        placeholder={t('Type at least 1 character to search by title or ID')}
                        uri={ApiConfig.ADMIN_DOCUMENT_SEARCH}
                        renderItem={(doc): ComboboxItem => ({
                            value: doc.id.toString(),
                            label: doc.title,
                        })}
                        initialOptions={documentOptions}
                        minSearchLength={1}
                        {...form.getInputProps('documents')}
                        key={form.key('documents')}
                        mb="md"
                    />
                    <Button
                        variant="light"
                        leftSection={<LuUpload size={16} />}
                        onClick={() => setIsDocumentModalOpened(true)}
                    >
                        {t('Upload new document')}
                    </Button>
                </Box>

                <Box display={activeStep === EXAM_FORM_STEP.questions ? 'block' : 'none'}>
                    {isStructureLocked && (
                        <Alert color="yellow" icon={<LuInfo />} mb="md">
                            {t(
                                'This exam already has results, so questions, answers and correct answers cannot be added, removed or changed. You can still edit their wording.',
                            )}
                        </Alert>
                    )}
                    {form.errors.questions && (
                        <Text c="red" size="sm" mb="md">
                            {form.errors.questions}
                        </Text>
                    )}
                    <QuestionFormList
                        questionList={questionList}
                        setQuestionList={setQuestionList}
                        form={form}
                        enabledLanguages={languagesValue}
                        disabled={isTranslating}
                        structureLocked={isStructureLocked}
                    />
                </Box>

                <Group mt="xl" justify="space-between">
                    <Group>
                        {activeStep > EXAM_FORM_STEP.general && (
                            <Button variant="default" leftSection={<LuArrowLeft />} onClick={goToPreviousStep}>
                                {t('Back')}
                            </Button>
                        )}
                        {activeStep < EXAM_FORM_LAST_STEP && (
                            <Button variant="light" rightSection={<LuArrowRight />} onClick={goToNextStep}>
                                {t('Next')}
                            </Button>
                        )}
                    </Group>
                    <Group>
                        {canSave && (
                            <Button type="submit" leftSection={<LuPlus />} disabled={isTranslating}>
                                {t('Save')}
                            </Button>
                        )}
                        <Button variant="default" component={Link} href={RouteConfig.MANAGE_EXAM.path}>
                            {t('Cancel')}
                        </Button>
                    </Group>
                </Group>
            </form>
            <CreateDocumentModal
                isOpened={isDocumentModalOpened}
                onClose={() => setIsDocumentModalOpened(false)}
                onSubmitted={handleDocumentCreated}
                withAudience={false}
            />
        </Paper>
    );
}
