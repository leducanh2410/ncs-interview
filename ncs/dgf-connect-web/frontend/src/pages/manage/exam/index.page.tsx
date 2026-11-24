import React, { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { LuCopy, LuPencil, LuPlus, LuTrash2 } from 'react-icons/lu';
import Link from 'next/link';
import { useRouter } from 'next/router';
import {
    ActionIcon,
    Anchor,
    Badge,
    Breadcrumbs,
    Button,
    Flex,
    Modal,
    Paper,
    Text,
    Title,
    Tooltip,
} from '@mantine/core';
import { useAtom } from 'jotai';
import RouteConfig from '@/configs/route.config';
import DateHelper, { DATE_STORE_FORMAT_WITH_TIME } from '@/helpers/date.helper';
import NotificationHelper from '@/helpers/notification.helper';
import RouteHelper from '@/helpers/route.helper';
import TranslatableHelper from '@/helpers/translatable.helper';
import CommonSearchBox from '@/components/CommonSearchBox';
import DDataTable from '@/components/DDataTable';
import ExamStatusBadge from '@/components/ExamStatusBadge';
import { masterAtom } from '@/pages/global.state';
import { usePermissions } from '@/hooks/usePermission';
import { useDeleteExamMutation, useGetExamsQuery } from '@/pages/manage/exam/api';

export default function IndexPage() {
    const { t, i18n } = useTranslation();
    const [masterData] = useAtom(masterAtom);
    const { push, query } = useRouter();

    const perms = usePermissions(['EXAM_LIST', 'EXAM_EDIT', 'EXAM_CREATE', 'EXAM_DELETE']);

    const [deleteId, setDeleteId] = useState<number | null>(null);

    const { data, isPending, refetch } = useGetExamsQuery(query, perms.EXAM_LIST);
    const { mutateAsync: deleteExam } = useDeleteExamMutation();
    const handleDelete = async () => {
        if (deleteId !== null) {
            await deleteExam(deleteId.toString());
            NotificationHelper.showSuccess(t('Successfully deleted exam'));
            setDeleteId(null);
            await refetch();
        }
    };

    return (
        <>
            <Breadcrumbs>
                <Anchor component={Link} href={RouteConfig.HOME.path}>
                    {t('Home')}
                </Anchor>
                <Text>{t('Exam management')}</Text>
            </Breadcrumbs>

            <Flex justify="space-between" align="center">
                <Title order={4} lh="48px" my="md">
                    {t('Exam management')}
                </Title>
                <Button
                    leftSection={<LuPlus />}
                    onClick={() => push(RouteConfig.MANAGE_EXAM_CREATE.path)}
                    disabled={!perms.EXAM_CREATE}
                >
                    {t('Create exam')}
                </Button>
            </Flex>

            <CommonSearchBox
                initialValues={{
                    title: '',
                    status: 'all',
                    workplace_id: '0',
                    is_required: '1',
                }}
                elements={[
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
                        options: [
                            { value: 'scheduled', label: t('Scheduled') },
                            { value: 'active', label: t('Active') },
                            { value: 'ended', label: t('Ended') },
                        ],
                        isClearable: true,
                        showAll: true,
                        allValue: 'all',
                    },
                    {
                        id: 'is_required',
                        type: 'select',
                        label: t('Required'),
                        name: 'is_required',
                        options: [
                            { value: '1', label: t('Active') },
                            { value: '0', label: t('Disabled') },
                        ],
                        isClearable: true,
                        showAll: true,
                        allValue: 'all',
                    },
                    {
                        id: 'workplace_id',
                        type: 'select',
                        label: t('Workplace'),
                        name: 'workplace_id',
                        options: masterData.workplaces.map((workplace) => ({
                            value: workplace.id.toString(),
                            label: workplace.name,
                        })),
                        isClearable: false,
                        isSearchable: true,
                        showAll: true,
                        allValue: '0',
                    },
                ]}
            />

            <Paper p="md" mt="md">
                <DDataTable
                    columns={[
                        {
                            name: t('Status'),
                            key: 'status',
                            render: (row) => <ExamStatusBadge exam={row} />,
                        },
                        {
                            name: t('Exam name'),
                            key: 'title',
                            render: (row) => (
                                <Anchor
                                    component={Link}
                                    href={RouteHelper.getPath('MANAGE_EXAM_DETAIL', { id: row.id })}
                                >
                                    {TranslatableHelper.resolve(row.title, i18n.language)}
                                </Anchor>
                            ),
                        },
                        {
                            name: t('Exam type'),
                            key: 'type',
                            render: (row) =>
                                row.type === 'external_form_prep' ? (
                                    <Badge color="grape" variant="light">
                                        {t('External form preparation')}
                                    </Badge>
                                ) : (
                                    <Badge color="blue" variant="light">
                                        {t('Knowledge assessment')}
                                    </Badge>
                                ),
                        },
                        {
                            name: t('Required'),
                            key: 'is_required',
                            render: (row) => (row.is_required ? t('Yes') : t('No')),
                        },
                        {
                            name: t('Start date'),
                            key: 'start_at',
                            render: (row) =>
                                DateHelper.parseAndFormat(
                                    row.start_at,
                                    DATE_STORE_FORMAT_WITH_TIME,
                                    t('FORMAT_DATE_WITH_TIME'),
                                ),
                        },
                        {
                            name: t('Completion deadline'),
                            key: 'end_at',
                            render: (row) =>
                                DateHelper.parseAndFormat(
                                    row.end_at,
                                    DATE_STORE_FORMAT_WITH_TIME,
                                    t('FORMAT_DATE_WITH_TIME'),
                                ),
                        },
                        {
                            name: t('Retry limit'),
                            key: 'retry_limit',
                            render: (row) => (row.type === 'external_form_prep' ? '-' : row.retry_limit),
                        },
                        {
                            name: t('Min score'),
                            key: 'min_score',
                            render: (row) => (row.type === 'external_form_prep' ? '-' : row.min_score),
                        },
                        {
                            name: t('Completion'),
                            key: 'completion_stats',
                            render: (row) =>
                                `${row.completion_stats.completed_count}/${row.completion_stats.eligible_count}`,
                        },
                        {
                            name: t('Action'),
                            key: 'action',
                            render: (row) => (
                                <Flex gap={8}>
                                    <ActionIcon
                                        variant="subtle"
                                        color="blue"
                                        onClick={() => push(RouteHelper.getPath('MANAGE_EXAM_DETAIL', { id: row.id }))}
                                        disabled={!perms.EXAM_EDIT}
                                    >
                                        <LuPencil size={16} />
                                    </ActionIcon>
                                    <Tooltip label={t('Copy exam')}>
                                        <ActionIcon
                                            variant="subtle"
                                            color="teal"
                                            aria-label={t('Copy exam')}
                                            onClick={() =>
                                                push({
                                                    pathname: RouteConfig.MANAGE_EXAM_CREATE.path,
                                                    query: { copyFrom: row.id },
                                                })
                                            }
                                            disabled={!perms.EXAM_CREATE}
                                        >
                                            <LuCopy size={16} />
                                        </ActionIcon>
                                    </Tooltip>
                                    <ActionIcon
                                        variant="subtle"
                                        color="red"
                                        onClick={() => setDeleteId(row.id)}
                                        disabled={!perms.EXAM_DELETE}
                                    >
                                        <LuTrash2 size={16} />
                                    </ActionIcon>
                                </Flex>
                            ),
                        },
                    ]}
                    data={data?.data || []}
                    isFetching={isPending}
                    noPermission={!perms.EXAM_LIST}
                />
            </Paper>

            <Modal opened={deleteId !== null} onClose={() => setDeleteId(null)} title={t('Delete exam')} centered>
                <Text>{t('Are you sure you want to delete this exam?')}</Text>
                <Flex justify="end" gap="sm" mt="md">
                    <Button variant="default" onClick={() => setDeleteId(null)}>
                        {t('Cancel')}
                    </Button>
                    <Button color="red" onClick={handleDelete}>
                        {t('Delete')}
                    </Button>
                </Flex>
            </Modal>
        </>
    );
}
