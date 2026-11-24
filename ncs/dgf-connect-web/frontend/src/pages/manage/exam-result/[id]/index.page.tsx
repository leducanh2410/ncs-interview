import React, { useMemo } from 'react';
import { useTranslation } from 'react-i18next';
import { LuFileText } from 'react-icons/lu';
import Link from 'next/link';
import { useRouter } from 'next/router';
import {
    Anchor,
    Badge,
    Breadcrumbs,
    Button,
    Divider,
    Flex,
    Group,
    Loader,
    Paper,
    Stack,
    Table,
    Text,
    Title,
} from '@mantine/core';
import RouteConfig from '@/configs/route.config';
import { ErrorCodeEnum } from '@/enums/error_code.enum';
import { ExamStatusEnum } from '@/enums/exam_status.enum';
import { ApiError } from '@/helpers/api.helper';
import CommonHelper from '@/helpers/common.helper';
import DateHelper from '@/helpers/date.helper';
import NotificationHelper from '@/helpers/notification.helper';
import TranslatableHelper from '@/helpers/translatable.helper';
import { usePermissions } from '@/hooks/usePermission';
import { useCompleteExternalOverrideMutation, useGetDetailExamResultQuery } from '@/pages/manage/exam-result/api';

const IndexPage = () => {
    const { t, i18n } = useTranslation();
    const router = useRouter();
    const id = router.query.id as string;
    const perms = usePermissions(['EXAM_RESULT_LIST', 'EXAM_EDIT']);

    const { data: examResultData, isLoading, refetch } = useGetDetailExamResultQuery(id, perms.EXAM_RESULT_LIST);
    const examResult = examResultData?.data?.exam_result;
    const { mutateAsync: completeExternalOverride, isPending: isOverriding } = useCompleteExternalOverrideMutation(id);
    const isExternalFormPrep = examResult?.exam?.type === 'external_form_prep';
    // Mirrors the candidate-side API, which resolves external_url from
    // user.settings.lang (SetLocale middleware) rather than the viewer's
    // locale - otherwise the admin can end up looking at a different
    // language's Google Form than the one the candidate actually opened.
    const candidateLang = examResult?.user?.settings?.lang || i18n.language;

    const handleOverride = async () => {
        try {
            await completeExternalOverride();
            NotificationHelper.showSuccess(t('Marked the external form as completed'));
            await refetch();
        } catch (err) {
            let message = t('Something went wrong');
            if (err instanceof ApiError) {
                if (err.message === ErrorCodeEnum.EXAM_RESULT_NOT_AVAILABLE.value) {
                    message = t('error|This exam result is not awaiting an external form');
                } else if (err.message === ErrorCodeEnum.E_FORBIDDEN.value) {
                    message = t('error|You do not have permission to complete this exam result');
                }
            }
            NotificationHelper.showError(message);
        }
    };

    // Process questions and user answers
    const processedQuestions = useMemo(() => {
        if (!examResultData?.data?.questions || !examResult?.questions) {
            return [];
        }

        const allQuestions = examResultData.data.questions;
        const userAnswers = CommonHelper.arrayKeyBy(examResult.questions, 'question_id');

        return allQuestions.map((question) => {
            const answer = userAnswers[question.id];
            const selectedOptionId = answer?.question_option_id || null;
            let correctOption, selectedOption;
            for (const opt of question.options) {
                if (opt.id === selectedOptionId) {
                    selectedOption = opt;
                }
                if (opt.is_correct) {
                    correctOption = opt;
                }
            }

            return {
                ...question, // Includes original question content and all options
                selected_option: selectedOption,
                correct_option: correctOption,
                is_correct: !!answer?.is_correct,
                is_carried_over: !!answer?.is_carried_over,
            };
        });
    }, [examResultData, examResult]);

    const getStatusBadge = (status: string) => {
        const statusObj = Object.values(ExamStatusEnum).find((item) => item.value === status);
        if (!statusObj) return <Badge color="gray">{status}</Badge>;

        const colorMap = {
            completed: 'green',
            in_progress: 'blue',
            failed: 'red',
            pending_external: 'orange',
        };

        return <Badge color={colorMap[statusObj.value as keyof typeof colorMap] || 'gray'}>{t(statusObj.text)}</Badge>;
    };

    if (isLoading || !router.isReady) {
        return <Loader />;
    }

    if (!examResult) {
        return (
            <Paper withBorder p="md" mt={12} radius="md">
                <Text>{t('Exam result not found')}</Text>
            </Paper>
        );
    }

    return (
        <>
            <Breadcrumbs mb="md">
                <Anchor component={Link} href={RouteConfig.HOME.path}>
                    {t('Home')}
                </Anchor>
                <Anchor component={Link} href={RouteConfig.MANAGE_EXAM_RESULT.path}>
                    {t('Exam result')}
                </Anchor>
                <Text>{t('Exam result Detail')}</Text>
            </Breadcrumbs>

            <Flex justify="space-between" align="center">
                <Title order={4} mb="sm">
                    <LuFileText size={20} style={{ marginRight: 8 }} />
                    {t('Exam result Detail')}:{' '}
                    {(examResult.exam?.title && TranslatableHelper.resolve(examResult.exam.title, i18n.language)) ||
                        '-'}
                </Title>
            </Flex>

            {/* Exam result Information */}
            <Paper withBorder p="md" mt={12} radius="md">
                <Title order={5} mb="md">
                    {t('Exam Information')}
                </Title>
                <Flex gap="md">
                    <Stack flex={1} gap={0}>
                        <Group px="sm" py="xs" bg="gray.2">
                            <Text size="sm" w={150}>
                                {t('Exam name')}
                            </Text>
                            <Text size="sm" style={{ flex: 1 }}>
                                {(examResult.exam?.title &&
                                    TranslatableHelper.resolve(examResult.exam.title, i18n.language)) ||
                                    '-'}
                            </Text>
                        </Group>
                        <Group px="sm" py="xs">
                            <Text size="sm" w={150}>
                                {t('Score')}
                            </Text>
                            <Text size="sm">{examResult.score}</Text>
                        </Group>
                        <Group px="sm" py="xs" bg="gray.2">
                            <Text size="sm" w={150}>
                                {t('Correct answers')}
                            </Text>
                            <Text size="sm">
                                {examResult.correct_answers}/{examResult.total_questions}
                            </Text>
                        </Group>
                        <Group px="sm" py="xs">
                            <Text size="sm" w={150}>
                                {t('Status')}
                            </Text>
                            <Text size="sm">{getStatusBadge(examResult.status)}</Text>
                            {isExternalFormPrep && examResult.status === 'pending_external' && perms.EXAM_EDIT && (
                                <Button size="xs" variant="light" loading={isOverriding} onClick={handleOverride}>
                                    {t('Mark external form as completed')}
                                </Button>
                            )}
                        </Group>
                    </Stack>
                    <Stack flex={1} gap={0}>
                        <Group px="sm" py="xs" bg="gray.2">
                            <Text size="sm" w={150}>
                                {t('Started at')}
                            </Text>
                            <Text size="sm">
                                {examResult.started_at
                                    ? DateHelper.parseAndFormat(examResult.started_at, null, t('FORMAT_DATE_WITH_TIME'))
                                    : '-'}
                            </Text>
                        </Group>
                        <Group px="sm" py="xs">
                            <Text size="sm" w={150}>
                                {t('Completed at')}
                            </Text>
                            <Text size="sm">
                                {examResult.completed_at
                                    ? DateHelper.parseAndFormat(
                                          examResult.completed_at,
                                          null,
                                          t('FORMAT_DATE_WITH_TIME'),
                                      )
                                    : '-'}
                            </Text>
                        </Group>
                        {isExternalFormPrep ? (
                            <>
                                <Group px="sm" py="xs" bg="gray.2">
                                    <Text size="sm" w={150}>
                                        {t('Google Form link')}
                                    </Text>
                                    <Text size="sm" style={{ wordBreak: 'break-all' }}>
                                        {(examResult.exam?.external_url &&
                                            TranslatableHelper.resolve(examResult.exam.external_url, candidateLang)) ||
                                            '-'}
                                    </Text>
                                </Group>
                                <Group px="sm" py="xs">
                                    <Text size="sm" w={150}>
                                        {t('External completed at')}
                                    </Text>
                                    <Text size="sm">
                                        {examResult.external_completed_at
                                            ? DateHelper.parseAndFormat(
                                                  examResult.external_completed_at,
                                                  null,
                                                  t('FORMAT_DATE_WITH_TIME'),
                                              )
                                            : '-'}
                                    </Text>
                                </Group>
                            </>
                        ) : (
                            <>
                                <Group px="sm" py="xs" bg="gray.2">
                                    <Text size="sm" w={150}>
                                        {t('Min score')}
                                    </Text>
                                    <Text size="sm">{examResult.exam?.min_score || '-'}</Text>
                                </Group>
                                <Group px="sm" py="xs">
                                    <Text size="sm" w={150}>
                                        {t('Time limit')}
                                    </Text>
                                    <Text size="sm">
                                        {examResult.exam?.time_limit
                                            ? `${examResult.exam.time_limit} ${t('minutes')}`
                                            : '-'}
                                    </Text>
                                </Group>
                            </>
                        )}
                    </Stack>
                </Flex>
            </Paper>

            <Divider my="lg" />

            <Paper withBorder p="md" mt={12} radius="md">
                <Title order={5} mb="md">
                    {t('User information')}
                </Title>
                <Flex gap="md">
                    <Stack flex={1} gap={0}>
                        <Group px="sm" py="xs" bg="gray.2">
                            <Text size="sm" w={150}>
                                {t('Name')}
                            </Text>
                            <Text size="sm">{examResult.user?.name || '-'}</Text>
                        </Group>
                        <Group px="sm" py="xs">
                            <Text size="sm" w={150}>
                                {t('Staff number')}
                            </Text>
                            <Text size="sm">{examResult.user?.staff_no || '-'}</Text>
                        </Group>
                    </Stack>
                    <Stack flex={1} gap={0}>
                        <Group px="sm" py="xs" bg="gray.2">
                            <Text size="sm" w={150}>
                                {t('Name')} (Kana)
                            </Text>
                            <Text size="sm">{examResult.user?.name_kana || '-'}</Text>
                        </Group>
                        <Group px="sm" py="xs">
                            <Text size="sm" w={150}>
                                {t('Email')}
                            </Text>
                            <Text size="sm">{examResult.user?.email || '-'}</Text>
                        </Group>
                    </Stack>
                </Flex>
            </Paper>

            <Divider my="lg" />

            <Title order={5} mb="sm">
                {t('Questions and Answers')}
            </Title>

            {processedQuestions.length === 0 ? (
                <Text c="dimmed">{t('No question')}</Text>
            ) : (
                <Paper withBorder radius="md" p="md">
                    <Table striped highlightOnHover withTableBorder withColumnBorders>
                        <Table.Thead>
                            <Table.Tr>
                                <Table.Th w={40}>#</Table.Th>
                                <Table.Th w={450}>{t('Question')}</Table.Th>
                                <Table.Th w={280}>{t('Your answer')}</Table.Th>
                                <Table.Th w={280}>{t('Correct answer')}</Table.Th>
                                <Table.Th w={180}>{t('Result')}</Table.Th>
                            </Table.Tr>
                        </Table.Thead>
                        <Table.Tbody>
                            {processedQuestions.map((q, index) => (
                                <Table.Tr key={q.id}>
                                    <Table.Td>{index + 1}</Table.Td>
                                    <Table.Td>{TranslatableHelper.resolve(q.content, i18n.language)}</Table.Td>
                                    <Table.Td>
                                        <Text size="sm" c={q.is_correct ? 'green.7' : 'red.7'}>
                                            {(q.selected_option?.content &&
                                                TranslatableHelper.resolve(q.selected_option.content, i18n.language)) ||
                                                t('Not answered')}
                                        </Text>
                                    </Table.Td>
                                    <Table.Td>
                                        <Text size="sm" c="green.7">
                                            {q.correct_option?.content &&
                                                TranslatableHelper.resolve(q.correct_option.content, i18n.language)}
                                        </Text>
                                    </Table.Td>
                                    <Table.Td>
                                        <Stack gap={4}>
                                            <Badge color={q.is_correct ? 'green' : 'red'} fullWidth>
                                                {q.is_correct ? t('Correct') : t('Incorrect')}
                                            </Badge>
                                            {q.is_carried_over && (
                                                <Badge color="teal" variant="light" fullWidth>
                                                    {t('Correct (previous attempt)')}
                                                </Badge>
                                            )}
                                        </Stack>
                                    </Table.Td>
                                </Table.Tr>
                            ))}
                        </Table.Tbody>
                    </Table>
                </Paper>
            )}
        </>
    );
};

export default IndexPage;
