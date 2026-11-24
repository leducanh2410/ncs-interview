import React, { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { LuFileSpreadsheet } from 'react-icons/lu';
import Link from 'next/link';
import { useRouter } from 'next/router';
import { Anchor, Breadcrumbs, Button, Flex, Paper, Text, Title } from '@mantine/core';
import { ExamResultWithRelation } from '@/types/models/exam_result';
import { ApiConfig } from '@/configs/api.config';
import RouteConfig from '@/configs/route.config';
import { ExamStatusEnum } from '@/enums/exam_status.enum';
import DateHelper from '@/helpers/date.helper';
import NotificationHelper from '@/helpers/notification.helper';
import RouteHelper from '@/helpers/route.helper';
import TranslatableHelper from '@/helpers/translatable.helper';
import CommonSearchBox, { userRenderItem } from '@/components/CommonSearchBox';
import DDataTable from '@/components/DDataTable';
import UserWithAvatar from '@/components/UserWithAvatar';
import { usePermissions } from '@/hooks/usePermission';
import { exportExamResult, useGetAllExamResultQuery } from '@/pages/manage/exam-result/api';

export default function IndexPage() {
    const { t, i18n } = useTranslation();
    const router = useRouter();
    const perms = usePermissions(['EXAM_RESULT_LIST', 'EXAM_RESULT_EXPORT']);
    const { data, isPending } = useGetAllExamResultQuery(router.query, perms.EXAM_RESULT_LIST);
    const [isExporting, setIsExporting] = useState(false);

    return (
        <>
            <Breadcrumbs>
                <Anchor component={Link} href={RouteConfig.HOME.path}>
                    {t('Home')}
                </Anchor>
                <Text>{t('Exam result')}</Text>
            </Breadcrumbs>

            <Flex justify="space-between" align="center">
                <Title order={4} lh="48px" my="md">
                    {t('Exam result')}
                </Title>
                <Button
                    leftSection={<LuFileSpreadsheet />}
                    disabled={!data?.data?.data.length || !perms.EXAM_RESULT_EXPORT}
                    loading={isExporting}
                    onClick={async () => {
                        if (isExporting) {
                            return;
                        }
                        setIsExporting(true);
                        try {
                            await exportExamResult(router.query);
                        } catch (e) {
                            NotificationHelper.showError(t('error|Could not export exam result'));
                        } finally {
                            setIsExporting(false);
                        }
                    }}
                >
                    {t('Export CSV')}
                </Button>
            </Flex>

            <CommonSearchBox
                initialValues={{
                    title: '',
                    user_id: '',
                    status: 'all',
                    completed_at: null,
                    occurrence: '',
                }}
                elements={[
                    {
                        id: 'user_id',
                        type: 'select-remote',
                        label: t('Employee'),
                        name: 'user_id',
                        uri: ApiConfig.ADMIN_MASTER_USER_SEARCH,
                        renderItem: userRenderItem,
                    },
                    {
                        id: 'title',
                        type: 'input-text',
                        label: t('Exam name'),
                        name: 'title',
                    },
                    {
                        id: 'status',
                        type: 'select',
                        label: t('Status'),
                        name: 'status',
                        options: Object.values(ExamStatusEnum).map((item) => ({
                            value: item.value,
                            label: t(item.text),
                        })),
                        isClearable: true,
                        showAll: true,
                        allValue: 'all',
                    },
                    {
                        id: 'completed_at',
                        type: 'date',
                        label: t('Completed at'),
                        name: 'completed_at',
                    },
                    {
                        id: 'occurrence',
                        type: 'input-number',
                        label: t('Occurrence'),
                        name: 'occurrence',
                    },
                ]}
            />

            <Paper p="md" mt="md">
                <DDataTable
                    columns={[
                        {
                            name: t('Completed at'),
                            key: 'completed_at',
                            render: (row: ExamResultWithRelation) =>
                                row.completed_at
                                    ? DateHelper.parseAndFormat(row.completed_at, null, t('FORMAT_DATE_WITH_TIME'))
                                    : '-',
                        },
                        {
                            name: t('External completed at'),
                            key: 'external_completed_at',
                            render: (row: ExamResultWithRelation) =>
                                row.external_completed_at
                                    ? DateHelper.parseAndFormat(
                                          row.external_completed_at,
                                          null,
                                          t('FORMAT_DATE_WITH_TIME'),
                                      )
                                    : '-',
                        },
                        {
                            name: t('User'),
                            key: 'user',
                            render: (row: ExamResultWithRelation) => <UserWithAvatar user={row.user} />,
                        },
                        {
                            name: t('Exam name'),
                            key: 'exam.title',
                            render: (row: ExamResultWithRelation) => (
                                <Anchor
                                    component={Link}
                                    href={RouteHelper.getPath('MANAGE_EXAM_RESULT_DETAIL', { id: row.id })}
                                >
                                    {(row.exam?.title && TranslatableHelper.resolve(row.exam.title, i18n.language)) ||
                                        '-'}
                                </Anchor>
                            ),
                        },
                        {
                            name: t('Occurrence'),
                            key: 'occurrence',
                        },
                        {
                            name: t('Workplace'),
                            key: 'workplace',
                            render: (row: ExamResultWithRelation) => row.workplace?.name || '-',
                        },
                        {
                            name: t('Score'),
                            key: 'score',
                        },
                        {
                            name: t('Correct answers'),
                            key: 'correct_answers',
                            render: (row: ExamResultWithRelation) => `${row.correct_answers}/${row.total_questions}`,
                        },
                        {
                            name: t('Status'),
                            key: 'status',
                            render: (row: ExamResultWithRelation) => {
                                const statusObj = Object.values(ExamStatusEnum).find(
                                    (item) => item.value === row.status,
                                );
                                return statusObj ? t(statusObj.text) : row.status;
                            },
                        },
                    ]}
                    data={data?.data || []}
                    isFetching={isPending}
                />
            </Paper>
        </>
    );
}
