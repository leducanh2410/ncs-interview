import React, { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { LuChevronRight, LuFileSpreadsheet, LuPencilLine, LuPlus } from 'react-icons/lu';
import Link from 'next/link';
import { useRouter } from 'next/router';
import {
    ActionIcon,
    Anchor,
    Badge,
    Breadcrumbs,
    Button,
    Flex,
    Paper,
    Stack,
    Text,
    Title,
    Tooltip,
    UnstyledButton,
} from '@mantine/core';
import { useDisclosure } from '@mantine/hooks';
import { useAtom } from 'jotai';
import { CheckIn } from '@/types/models/check_in';
import { WorkHistoryEditRequest } from '@/types/models/work_history_edit_request';
import { ApiConfig } from '@/configs/api.config';
import RouteConfig from '@/configs/route.config';
import { WorkHistoryEditRequestStatusEnum } from '@/enums/work_history_edit_request_status.enum';
import DateHelper, { DATE_STORE_FORMAT_WITH_TIME } from '@/helpers/date.helper';
import EnumHelper from '@/helpers/enum.helper';
import NotificationHelper from '@/helpers/notification.helper';
import RouteHelper from '@/helpers/route.helper';
import WorkplaceHelper from '@/helpers/workplace.helper';
import CommonSearchBox, { userRenderItem } from '@/components/CommonSearchBox';
import DDataTable, { ColumnDefinition, headerWrapClassName } from '@/components/DDataTable';
import UserWithAvatar from '@/components/UserWithAvatar';
import { masterAtom } from '@/pages/global.state';
import { fieldLabel } from '@/pages/manage/work-history/edit-requests/partials/edit_request_items';
import RequestDetailModal from '@/pages/manage/work-history/edit-requests/partials/request_detail_modal';
import ApproveEditRequestModal from '@/pages/manage/work-history/partials/approve_edit_request_modal';
import CheckInAddModal from '@/pages/manage/work-history/partials/check_in_add_modal';
import CheckInUpdateModal from '@/pages/manage/work-history/partials/check_in_update_modal';
import { usePermissions } from '@/hooks/usePermission';
import { requestDetailReportExport, useGetWorkHistoryQuery } from '@/pages/manage/work-history/api';

const REASON_COLUMN_WIDTH = '200px';

const WRAPPING_BADGE_STYLES = {
    root: { height: 'auto', maxWidth: '100%' },
    label: { whiteSpace: 'normal', overflow: 'visible' },
} as const;

const ReasonLines = ({ lines, onClick }: { lines: string[]; onClick?: () => void }) => {
    const content = (
        <Stack gap={2}>
            {lines.map((line, index) => (
                <Text key={index} size="sm" lineClamp={2}>
                    {line}
                </Text>
            ))}
        </Stack>
    );

    return (
        <Tooltip label={lines.join('\n')} multiline maw={360} withArrow>
            {onClick ? (
                <UnstyledButton onClick={onClick} ta="left">
                    {content}
                </UnstyledButton>
            ) : (
                content
            )}
        </Tooltip>
    );
};

const IndexPage = () => {
    const { t } = useTranslation();
    const perms = usePermissions([
        'WORK_HISTORY_LIST',
        'WORK_HISTORY_EXPORT',
        'WORK_HISTORY_EDIT',
        'WORK_HISTORY_ADD',
        'WORK_HISTORY_EDIT_REQUEST_PROCESS',
    ]);
    const { push, query } = useRouter();
    const { data, isLoading } = useGetWorkHistoryQuery(query, perms.WORK_HISTORY_LIST);
    const [masterData] = useAtom(masterAtom);
    const [currentCheckIn, setCurrentCheckIn] = useState<CheckIn | null>(null);
    const [approveCheckIn, setApproveCheckIn] = useState<CheckIn | null>(null);
    const [detailRequest, setDetailRequest] = useState<WorkHistoryEditRequest | null>(null);
    const [isShowAdd, { open: openAdd, close: closeAdd }] = useDisclosure(false);
    const [isExporting, setIsExporting] = useState(false);

    const now = DateHelper.now();
    const from = now.subtract(7, 'days');

    const accessibleWorkplaces = WorkplaceHelper.filterWorkplacesByAccess(
        masterData.workplaces,
        masterData.workplace_access,
        'work_history',
    );

    return (
        <>
            <Breadcrumbs>
                <Anchor component={Link} href={RouteConfig.HOME.path}>
                    {t('Home')}
                </Anchor>
                <Text>{t('Work history')}</Text>
            </Breadcrumbs>
            <Flex justify="space-between" align="center">
                <Title order={4} lh="48px" my="md">
                    {t('Work history')}
                </Title>
                <Flex gap="8">
                    <Button leftSection={<LuPlus />} onClick={openAdd} disabled={!perms.WORK_HISTORY_ADD}>
                        {t('Add user to shift')}
                    </Button>
                    <Button
                        leftSection={<LuFileSpreadsheet />}
                        disabled={!perms.WORK_HISTORY_EXPORT || !(data?.data?.total || 0)}
                        loading={isExporting}
                        onClick={async () => {
                            if (!data?.data?.data.length) {
                                return;
                            }
                            setIsExporting(true);
                            try {
                                const response = await requestDetailReportExport(query);
                                NotificationHelper.showSuccess(
                                    t('Export started. You will find the file in Export history when it is ready.'),
                                );
                                const batchId = response?.data?.id;
                                if (batchId) {
                                    await push(RouteHelper.getPath('EXPORT_BATCH_DETAIL', { id: batchId }));
                                }
                            } catch (e) {
                                NotificationHelper.showError(t('error|Could not export work history report'));
                            } finally {
                                setIsExporting(false);
                            }
                        }}
                    >
                        {t('Export excel')}
                    </Button>
                    <Button
                        leftSection={<LuChevronRight />}
                        variant="outline"
                        onClick={() => push(RouteConfig.MANAGE_WORK_HISTORY_MONTHLY.path)}
                    >
                        {t('View Monthly report')}
                    </Button>
                </Flex>
            </Flex>
            <CommonSearchBox
                initialValues={{
                    dates: [from.toDate(), now.toDate()],
                    workplace_id: '0',
                    user_id: null,
                    attendance_status: '',
                }}
                elements={[
                    {
                        id: 'dates',
                        type: 'date-range',
                        label: t('Date range'),
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
                        id: 'user_id',
                        type: 'select-remote',
                        label: t('User'),
                        name: 'user_id',
                        uri: ApiConfig.ADMIN_MASTER_USER_SEARCH,
                        renderItem: userRenderItem,
                    },
                    {
                        id: 'attendance_status',
                        type: 'select',
                        label: t('Attendance status'),
                        name: 'attendance_status',
                        options: [
                            { value: 'late', label: t('Late arrival') },
                            { value: 'early_leave', label: t('Early leave') },
                            { value: 'absent', label: t('Absent') },
                        ],
                        showAll: true,
                        allValue: '',
                    },
                ]}
            />
            <Paper p="md" mt="md">
                <DDataTable
                    columns={(
                        [
                            {
                                name: t('Date'),
                                key: 'shift.date',
                                render: (data) => {
                                    return DateHelper.parseAndFormat(data.shift.date);
                                },
                            },
                            {
                                name: t('Workplace'),
                                key: 'workplace',
                                render: (data) => {
                                    return (
                                        <Anchor
                                            component={Link}
                                            href={RouteHelper.getPath('ADMIN_WORKPLACE_DETAIL', {
                                                id: data.shift.workplace.id,
                                            })}
                                        >
                                            {data.shift.workplace.name}
                                        </Anchor>
                                    );
                                },
                            },
                            {
                                name: t('Shift'),
                                key: 'shift_time',
                                render: (data) => {
                                    const { startTime, endTime } = DateHelper.formatWorkHour(
                                        data.shift.workplace_shift.start_time,
                                        data.shift.workplace_shift.end_time,
                                    );
                                    return (
                                        <Anchor
                                            component={Link}
                                            href={RouteHelper.getPath('MANAGE_SHIFT_DETAIL', {
                                                id: data.shift_id,
                                            })}
                                        >
                                            {startTime} ~ {endTime}
                                        </Anchor>
                                    );
                                },
                            },
                            {
                                name: t('Break time (minute)'),
                                key: 'break_duration',
                                render: (data) => {
                                    if (data.break_minutes !== null) {
                                        return data.break_minutes;
                                    }

                                    if (data.shift.break_minutes !== null) {
                                        return data.shift.break_minutes;
                                    }

                                    return data.shift.workplace_shift.break_duration;
                                },
                                width: '70px',
                            },
                            {
                                name: t('Employee'),
                                key: 'employee',
                                render: (data) => <UserWithAvatar user={data.user} />,
                                width: '200px',
                            },
                            {
                                name: t('Start time'),
                                key: 'start_time',
                                render: (data) => {
                                    let time = DateHelper.parseAndFormat(
                                        data.check_in_time,
                                        DATE_STORE_FORMAT_WITH_TIME,
                                        t('FORMAT_DATE_WITH_TIME'),
                                    );
                                    if (data.checked_in_by !== null) {
                                        time += ` (${data.checked_in_by ? 'Admin' : t('System')})`;
                                    }

                                    return time;
                                },
                            },
                            {
                                name: t('End time'),
                                key: 'end_time',
                                render: (data) => {
                                    let time = DateHelper.parseAndFormat(
                                        data.check_out_time,
                                        DATE_STORE_FORMAT_WITH_TIME,
                                        t('FORMAT_DATE_WITH_TIME'),
                                    );
                                    if (data.checked_out_by !== null) {
                                        time += ` (${data.checked_out_by ? 'Admin' : t('System')})`;
                                    }

                                    return time;
                                },
                            },
                            {
                                name: `${t('Work hours')} (h)`,
                                key: 'work_hours',
                                render: (data) => {
                                    if (data.is_absent) {
                                        return (
                                            <Badge color="orange" variant="light" styles={WRAPPING_BADGE_STYLES}>
                                                {t('Absent')}
                                            </Badge>
                                        );
                                    }

                                    return DateHelper.formatMinuteToHour(data.work_minutes);
                                },
                                width: '70px',
                            },
                            {
                                name: t('Status'),
                                key: 'status',
                                render: (data) => {
                                    const status = data.latest_edit_request?.status;
                                    if (!status && !data.late_minutes && !data.early_minutes) {
                                        return '-';
                                    }
                                    return (
                                        <Stack gap={4} align="flex-start">
                                            {data.late_minutes > 0 && (
                                                <Badge
                                                    size="xs"
                                                    color="red"
                                                    variant="light"
                                                    styles={WRAPPING_BADGE_STYLES}
                                                >
                                                    {t('Late {{minutes}} min', { minutes: data.late_minutes })}
                                                </Badge>
                                            )}
                                            {data.early_minutes > 0 && (
                                                <Badge
                                                    size="xs"
                                                    color="grape"
                                                    variant="light"
                                                    styles={WRAPPING_BADGE_STYLES}
                                                >
                                                    {t('Early {{minutes}} min', { minutes: data.early_minutes })}
                                                </Badge>
                                            )}
                                            {status && (
                                                <Badge
                                                    size="xs"
                                                    styles={WRAPPING_BADGE_STYLES}
                                                    color={EnumHelper.getColor(
                                                        WorkHistoryEditRequestStatusEnum,
                                                        status,
                                                    )}
                                                >
                                                    {EnumHelper.getText(WorkHistoryEditRequestStatusEnum, status)}
                                                </Badge>
                                            )}
                                        </Stack>
                                    );
                                },
                                width: '120px',
                            },
                            {
                                name: t('Late / early reason'),
                                key: 'attendance_reason',
                                render: (data) => {
                                    const lines = [
                                        data.late_minutes > 0 && data.late_reason
                                            ? `${t('Late arrival')}: ${data.late_reason}`
                                            : null,
                                        data.early_minutes > 0 && data.early_reason
                                            ? `${t('Early leave')}: ${data.early_reason}`
                                            : null,
                                    ].filter((line): line is string => line !== null);
                                    if (!lines.length) {
                                        return '-';
                                    }
                                    return <ReasonLines lines={lines} />;
                                },
                                width: REASON_COLUMN_WIDTH,
                            },
                            {
                                name: t('Edit reason'),
                                key: 'edit_reason',
                                render: (data) => {
                                    const request = data.latest_edit_request;
                                    if (!request || !request.items?.length) {
                                        return '-';
                                    }
                                    const lines = request.items.map(
                                        (item) => `${fieldLabel(t, item.field)}: ${item.reason}`,
                                    );
                                    if (request.status === 'rejected' && request.reject_reason) {
                                        lines.push(`${t('Reject reason')}: ${request.reject_reason}`);
                                    }
                                    return (
                                        <ReasonLines
                                            lines={lines}
                                            onClick={() =>
                                                setDetailRequest({
                                                    ...request,
                                                    workplace: data.shift.workplace,
                                                    check_in: { id: data.id, user: data.user, shift: data.shift },
                                                })
                                            }
                                        />
                                    );
                                },
                                width: REASON_COLUMN_WIDTH,
                            },
                            {
                                name: t('Action'),
                                key: 'action',
                                render: (data) => (
                                    <Flex gap={4} align="center" wrap="nowrap">
                                        <ActionIcon
                                            aria-label={t('Edit')}
                                            variant="subtle"
                                            color="blue"
                                            onClick={() => setCurrentCheckIn(data)}
                                            disabled={
                                                !perms.WORK_HISTORY_EDIT ||
                                                data.latest_edit_request?.status === 'pending'
                                            }
                                        >
                                            <LuPencilLine size={16} />
                                        </ActionIcon>
                                        {perms.WORK_HISTORY_EDIT_REQUEST_PROCESS &&
                                            data.latest_edit_request?.status === 'pending' && (
                                                <Anchor onClick={() => setApproveCheckIn(data)}>{t('Approve')}</Anchor>
                                            )}
                                    </Flex>
                                ),
                                width: '110px',
                            },
                        ] satisfies ColumnDefinition<CheckIn>[]
                    ).map((column) => ({ ...column, titleClassName: headerWrapClassName }))}
                    data={data?.data || []}
                    isFetching={isLoading}
                    noPermission={!perms.WORK_HISTORY_LIST}
                />
            </Paper>
            <CheckInAddModal opened={isShowAdd} onClose={closeAdd} />
            <CheckInUpdateModal checkIn={currentCheckIn} onClose={() => setCurrentCheckIn(null)} />
            <ApproveEditRequestModal checkIn={approveCheckIn} onClose={() => setApproveCheckIn(null)} />
            <RequestDetailModal
                request={detailRequest}
                onClose={() => setDetailRequest(null)}
                onApprove={() => {}}
                onReject={() => {}}
                canProcess={false}
            />
        </>
    );
};
export default IndexPage;
