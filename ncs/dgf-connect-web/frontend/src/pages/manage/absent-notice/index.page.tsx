import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import Link from 'next/link';
import { useRouter } from 'next/router';
import { Anchor, Badge, Box, Breadcrumbs, CheckIcon, Flex, Paper, Select, Text, Title } from '@mantine/core';
import { useAtom } from 'jotai';
import RouteConfig from '@/configs/route.config';
import { AbsentNoticeStatusEnum } from '@/enums/absent_notice_status.enum';
import { ErrorCodeEnum } from '@/enums/error_code.enum';
import { ApiError } from '@/helpers/api.helper';
import DateHelper from '@/helpers/date.helper';
import EnumHelper from '@/helpers/enum.helper';
import NotificationHelper from '@/helpers/notification.helper';
import RouteHelper from '@/helpers/route.helper';
import WorkplaceHelper from '@/helpers/workplace.helper';
import CommonSearchBox from '@/components/CommonSearchBox';
import DConfirmModal from '@/components/DConfirmModal';
import DDataTable from '@/components/DDataTable';
import { masterAtom } from '@/pages/global.state';
import { usePermissions } from '@/hooks/usePermission';
import {
    AbsentNoticeListItem,
    UpdateAbsentNoticeStatusResponse,
    useGetAbsentNoticesQuery,
    useUpdateAbsentNoticeStatusMutation,
} from '@/pages/manage/absent-notice/api';

const StatusDot = ({ color }: { color: string }) => (
    <Box w={8} h={8} bdrs="50%" bg={`var(--mantine-color-${color}-6)`} style={{ flexShrink: 0 }} />
);

const IndexPage = () => {
    const { t } = useTranslation();
    const router = useRouter();
    const perms = usePermissions(['SHIFT_LIST', 'SHIFT_EDIT']);
    const { data, isPending } = useGetAbsentNoticesQuery(router.query, perms.SHIFT_LIST);
    const updateStatus = useUpdateAbsentNoticeStatusMutation();
    const [masterData] = useAtom(masterAtom);

    const today = DateHelper.now();
    const nextWeek = today.add(7, 'days');
    const accessibleWorkplaces = WorkplaceHelper.filterWorkplacesByAccess(
        masterData.workplaces,
        masterData.workplace_access,
        'shift',
    );

    const [pendingChange, setPendingChange] = useState<{ row: AbsentNoticeListItem; status: string } | null>(null);

    // Every transition has a consequence outside this screen - it adds or keeps the employee on the
    // shift, or notifies them - so none of them is applied straight from the click.
    const confirmMessages: Record<string, string> = {
        [AbsentNoticeStatusEnum.PROCESSED.value]: t(
            'The employee will be moved to the rejected list of this shift and notified that the shift was cancelled. A shift that has already started, or one the employee has already checked in to, is kept as is - use the absent flag on the shift screen for those.',
        ),
        [AbsentNoticeStatusEnum.REJECTED.value]: t(
            'The employee keeps this shift and will be notified that the absence request was refused.',
        ),
        [AbsentNoticeStatusEnum.PENDING.value]: t(
            'The request returns to the pending list. An employee already removed from the shift is not added back automatically.',
        ),
    };

    // The request is always closed, but the shift and the notification are not always touched with
    // it, so the confirmation repeats what the API reports rather than the intended case.
    const getSuccessMessage = (result: UpdateAbsentNoticeStatusResponse): string => {
        switch (result.shift_outcome) {
            case 'shift_kept_already_started':
                return t(
                    'The request was closed. The shift had already started, so the employee stays on it - use the absent flag on the shift screen to record a no-show.',
                );
            case 'shift_kept_checked_in':
                return t(
                    'The request was closed. The employee has already checked in, so the shift and the check-in were kept - use the absent flag on the shift screen to record a no-show.',
                );
            case 'shift_rejected':
                return result.notified
                    ? t('The employee was removed from the shift and notified.')
                    : t(
                          'The employee was removed from the shift. No notification was sent because a manager assigned this shift.',
                      );
            default:
                return t('Status updated');
        }
    };

    const handleConfirmStatus = () => {
        if (!pendingChange) {
            return;
        }

        updateStatus.mutate(
            { id: pendingChange.row.id, status: pendingChange.status },
            {
                onSuccess: (result) => {
                    setPendingChange(null);
                    NotificationHelper.showSuccess(getSuccessMessage(result));
                },
                onError: (error) => {
                    setPendingChange(null);
                    NotificationHelper.showError(
                        error instanceof ApiError &&
                            error.message === ErrorCodeEnum.ABSENT_NOTICE_SHIFT_NOT_APPROVED.value
                            ? t('The employee is no longer on this shift, so the request cannot be refused.')
                            : t('Failed to update status'),
                    );
                },
            },
        );
    };

    return (
        <>
            <Breadcrumbs>
                <Anchor component={Link} href={RouteConfig.HOME.path}>
                    {t('Home')}
                </Anchor>
                <Text>{t('Absent Notices')}</Text>
            </Breadcrumbs>

            <Title order={4} lh="48px" my="md">
                {t('Absent Notices')}
            </Title>

            <CommonSearchBox
                initialValues={{
                    dates: [today.toDate(), nextWeek.toDate()],
                    workplace_id: '0',
                    status: '',
                }}
                elements={[
                    {
                        id: 'dates',
                        type: 'date-range',
                        label: t('Shift date range'),
                        name: 'dates',
                    },
                    {
                        id: 'workplace_id',
                        type: 'select',
                        label: t('Workplace'),
                        name: 'workplace_id',
                        options: accessibleWorkplaces.map((workplace) => ({
                            value: workplace.id.toString(),
                            label: workplace.name,
                        })),
                        isSearchable: true,
                        showAll: true,
                        allValue: '0',
                    },
                    {
                        id: 'status',
                        type: 'select',
                        label: t('Status'),
                        name: 'status',
                        options: EnumHelper.toSelectArray(AbsentNoticeStatusEnum),
                        showAll: true,
                        allValue: '',
                    },
                ]}
            />

            <Paper p="md" mt="md">
                <DDataTable
                    columns={[
                        {
                            name: t('Shift date'),
                            key: 'shift_date',
                            render: (row) => DateHelper.parseAndFormat(row.shift_date),
                        },
                        {
                            name: t('Workplace'),
                            key: 'workplace_name',
                        },
                        {
                            name: t('Shift time'),
                            key: 'shift_time',
                            render: (row) => (
                                <Anchor
                                    component={Link}
                                    href={RouteHelper.getPath('MANAGE_SHIFT_DETAIL', { id: row.shift_id })}
                                >
                                    {row.shift_time}
                                </Anchor>
                            ),
                        },
                        {
                            name: t('User'),
                            key: 'user_name',
                        },
                        {
                            name: t('Position'),
                            key: 'position_name',
                        },
                        {
                            name: t('Requested at'),
                            key: 'requested_at',
                            render: (row) =>
                                DateHelper.parseAndFormat(row.requested_at, null, t('FORMAT_DATE_WITH_TIME')),
                        },
                        {
                            name: t('Status'),
                            key: 'status',
                            render: (row) => {
                                const color = EnumHelper.getColor(AbsentNoticeStatusEnum, row.status);

                                if (!perms.SHIFT_EDIT) {
                                    return (
                                        <Badge color={color}>
                                            {EnumHelper.getText(AbsentNoticeStatusEnum, row.status)}
                                        </Badge>
                                    );
                                }

                                return (
                                    <Select
                                        size="xs"
                                        w={160}
                                        allowDeselect={false}
                                        checkIconPosition="right"
                                        value={row.status}
                                        onChange={(status) => status && setPendingChange({ row, status })}
                                        disabled={updateStatus.isPending}
                                        data={EnumHelper.toSelectArray(AbsentNoticeStatusEnum)}
                                        leftSection={<StatusDot color={color} />}
                                        styles={{
                                            input: {
                                                color: `var(--mantine-color-${color}-8)`,
                                                fontWeight: 500,
                                            },
                                        }}
                                        renderOption={({ option, checked }) => (
                                            <Flex align="center" justify="space-between" w="100%" gap={8}>
                                                <Flex align="center" gap={8}>
                                                    <StatusDot
                                                        color={EnumHelper.getColor(
                                                            AbsentNoticeStatusEnum,
                                                            option.value,
                                                        )}
                                                    />
                                                    {option.label}
                                                </Flex>
                                                {checked && <CheckIcon size={10} opacity={0.4} />}
                                            </Flex>
                                        )}
                                    />
                                );
                            },
                        },
                        {
                            name: t('Handled by'),
                            key: 'processed_by_name',
                            render: (row) => row.processed_by_name || '-',
                        },
                        {
                            name: t('Handled at'),
                            key: 'processed_at',
                            render: (row) =>
                                row.processed_at
                                    ? DateHelper.parseAndFormat(row.processed_at, null, t('FORMAT_DATE_WITH_TIME'))
                                    : '-',
                        },
                    ]}
                    data={data?.data || []}
                    isFetching={isPending}
                    noPermission={!perms.SHIFT_LIST}
                />
            </Paper>

            <DConfirmModal
                opened={!!pendingChange}
                title={t('Update absence request status')}
                message={pendingChange ? confirmMessages[pendingChange.status] : ''}
                onConfirm={handleConfirmStatus}
                onCancel={() => setPendingChange(null)}
                isProcessing={updateStatus.isPending}
            />
        </>
    );
};

export default IndexPage;
