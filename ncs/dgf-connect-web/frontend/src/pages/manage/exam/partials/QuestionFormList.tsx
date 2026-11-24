import React from 'react';
import { useTranslation } from 'react-i18next';
import { LuPlus, LuTrash2 } from 'react-icons/lu';
import { ActionIcon, Button, Card, Checkbox, Combobox, Group, Stack, Title } from '@mantine/core';
import { UseFormReturnType } from '@mantine/form';
import { v4 as uuidv4 } from 'uuid';
import { ExamContentLanguage, emptyTranslatableText } from '@/configs/exam_language.config';
import DLanguageTabsInput from '@/components/DLanguageTabsInput';
import { ExamFormType, QuestionList } from '@/pages/manage/exam/partials/exam_form';

import HiddenInput = Combobox.HiddenInput;

type Props = {
    form: UseFormReturnType<ExamFormType>;
    questionList: QuestionList;
    setQuestionList: (q: QuestionList) => void;
    enabledLanguages: ExamContentLanguage[];
    disabled?: boolean;
    structureLocked?: boolean;
};

export default function QuestionFormList({
    form,
    questionList,
    setQuestionList,
    enabledLanguages,
    disabled,
    structureLocked = false,
}: Props) {
    const { t } = useTranslation();
    const structureDisabled = disabled || structureLocked;

    return (
        <Stack>
            {questionList.order.map((id, qIndex) => (
                <Card key={id} withBorder p="md" radius="md">
                    <Group justify="space-between" mb="xs">
                        <Title order={5}>{`${t('Question')} ${qIndex + 1}`}</Title>
                        <Button
                            variant="filled"
                            mt="md"
                            color="red"
                            leftSection={<LuTrash2 />}
                            disabled={structureDisabled}
                            onClick={() => {
                                const newQuestionOrder = { ...questionList.question_order };
                                delete newQuestionOrder[id];
                                setQuestionList({
                                    order: questionList.order.filter((qid) => qid !== id),
                                    question_order: newQuestionOrder,
                                });
                                form.removeListItem(`questions`, qIndex);
                            }}
                        >
                            {t('Delete question')}
                        </Button>
                    </Group>
                    <DLanguageTabsInput
                        form={form}
                        path={`questions.${qIndex}.content`}
                        enabledLanguages={enabledLanguages}
                        maxLength={2000}
                        mb="sm"
                        disabled={disabled}
                    />

                    <Title order={6} mt="xs">
                        {t('Answer')}
                    </Title>

                    <Stack>
                        {questionList.question_order[id].map((aId, aIndex) => (
                            <Group key={aId} align="top">
                                <Checkbox
                                    {...form.getInputProps(`questions.${qIndex}.options.${aIndex}.is_correct`, {
                                        type: 'checkbox',
                                    })}
                                    key={form.key(`questions.${qIndex}.options.${aIndex}.is_correct`)}
                                    mt="8"
                                    disabled={structureDisabled}
                                />
                                <DLanguageTabsInput
                                    form={form}
                                    path={`questions.${qIndex}.options.${aIndex}.content`}
                                    enabledLanguages={enabledLanguages}
                                    label={`${t('Answer')} ${String.fromCharCode(65 + aIndex)}`}
                                    maxLength={1000}
                                    style={{ flex: 1 }}
                                    disabled={disabled}
                                />
                                <ActionIcon
                                    variant="subtle"
                                    color="red"
                                    mt={4}
                                    disabled={structureDisabled}
                                    onClick={() => {
                                        setQuestionList({
                                            order: questionList.order,
                                            question_order: {
                                                ...questionList.question_order,
                                                [id]: questionList.question_order[id].filter((aid) => aid !== aId),
                                            },
                                        });
                                        form.removeListItem(`questions.${qIndex}.options`, aIndex);
                                    }}
                                >
                                    <LuTrash2 size={16} />
                                </ActionIcon>
                            </Group>
                        ))}
                        <Button
                            leftSection={<LuPlus size={16} />}
                            variant="light"
                            onClick={() => {
                                const tempId = uuidv4();
                                setQuestionList({
                                    order: questionList.order,
                                    question_order: {
                                        ...questionList.question_order,
                                        [id]: [...questionList.question_order[id], tempId],
                                    },
                                });
                                form.insertListItem(`questions.${qIndex}.options`, {
                                    id: tempId,
                                    content: emptyTranslatableText(),
                                    is_correct: false,
                                });
                            }}
                            mt="xs"
                            size="xs"
                            disabled={structureDisabled}
                        >
                            {t('Add answer')}
                        </Button>
                    </Stack>
                </Card>
            ))}

            <Button
                variant="light"
                mt="md"
                leftSection={<LuPlus />}
                disabled={structureDisabled}
                onClick={() => {
                    const questionTempId = uuidv4();
                    const optionTempId = uuidv4();
                    form.insertListItem(`questions`, {
                        id: questionTempId,
                        content: emptyTranslatableText(),
                        options: [
                            {
                                id: optionTempId,
                                content: emptyTranslatableText(),
                                is_correct: false,
                            },
                        ],
                    });
                    setQuestionList({
                        order: [...questionList.order, questionTempId],
                        question_order: {
                            ...questionList.question_order,
                            [questionTempId]: [optionTempId],
                        },
                    });
                }}
            >
                {t('Add question')}
            </Button>
        </Stack>
    );
}
