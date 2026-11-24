import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import Link from 'next/link';
import { useRouter } from 'next/router';
import { Anchor, Badge, Breadcrumbs, Button, Flex, Paper, Switch, Text, Title, Tooltip } from '@mantine/core';
import { DatePickerInput } from '@mantine/dates';
import { notifications } from '@mantine/notifications';
import { FixedScheduleChangeConfig } from '@/types/models/fixed_schedule_change_config';
import RouteConfig from '@/configs/route.config';
import DateHelper, { DATE_STORE_FORMAT } from '@/helpers/date.helper';
import RouteHelper from '@/helpers/route.helper';
import CommonSearchBox from '@/components/CommonSearchBox';
import DDataTable from '@/components/DDataTable';
import BulkConfigModal, {
    BulkConfigRequest,
} from '@/pages/admin/fixed-schedule/change-config/partials/bulk_config_modal';
import { usePermissions } from '@/hooks/usePermission';
import {
    useBulkUpdateFixedScheduleChangeConfigMutation,
    useGetFixedScheduleChangeConfigsQuery,
    useUpdateFixedScheduleChangeConfigMutation,
} from '@/pages/admin/fixed-schedule/change-config/api';

type Row = FixedScheduleChangeConfig & { id: number };

const IndexPage = () => {
    const { t } = useTranslation();
    const router = useRouter();
    const perms = usePermissions(['FIXED_SCHEDULE_CHANGE_CONFIG_LIST', 'FIXED_SCHEDULE_CHANGE_CONFIG_EDIT']);
    const { data, isPending } = useGetFixedScheduleChangeConfigsQuery(perms.FIXED_SCHEDULE_CHANGE_CONFIG_LIST);
    const updateMutation = useUpdateFixedScheduleChangeConfigMutation();
    const bulkMutation = useBulkUpdateFixedScheduleChangeConfigMutation();

    const [selectedRecords, setSelectedRecords] = useState<Row[]>([]);
    const [bulkRequest, setBulkRequest] = useState<BulkConfigRequest | null>(null);

    // Mirrors the backend's window rule (is_window_open: today <= deadline): today is a valid
    // deadline that keeps the window open through end of day. Past dates are intentionally
    // allowed too, since the backend uses them to close a window immediately.
    const today = DateHelper.now().format(DATE_STORE_FORMAT);
    const isValidDeadline = (deadline: string | null) => !!deadline && deadline >= today;

    const allRows: Row[] = useMemo(() => (data?.data ?? []).map((c) => ({ ...c, id: c.workplace_id })), [data]);
    const workplaceOptions = useMemo(
        () => allRows.map((r) => ({ value: r.workplace_id.toString(), label: r.workplace.name })),
        [allRows],
    );
    const filterWorkplaceId = router.query.workplace_id;
    const rows: Row[] = useMemo(
        () =>
            filterWorkplaceId && !Array.isArray(filterWorkplaceId)
                ? allRows.filter((r) => r.workplace_id.toString() === filterWorkplaceId)
                : allRows,
        [allRows, filterWorkplaceId],
    );

    const handleToggle = async (row: Row) => {
        try {
            await updateMutation.mutateAsync({
                workplaceId: row.workplace_id,
                data: { is_enabled: !row.is_enabled, deadline: row.deadline },
            });
        } catch {
            notifications.show({ color: 'red', message: t('error|Could not update change config') });
        }
    };

    const handleDeadlineChange = async (row: Row, deadline: string | null) => {
        try {
            await updateMutation.mutateAsync({
                workplaceId: row.workplace_id,
                data: { is_enabled: row.is_enabled, deadline },
            });
        } catch {
            notifications.show({ color: 'red', message: t('error|Could not update change config') });
        }
    };

    const openBulkModal = (isEnabled: boolean) => {
        setBulkRequest({
            workplaceIds: selectedRecords.map((r) => r.workplace_id),
            workplaceNames: selectedRecords.map((r) => r.workplace.name),
            isEnabled,
        });
    };

    const handleBulkConfirm = async (deadline: string | null) => {
        if (!bulkRequest) return;
        try {
            await bulkMutation.mutateAsync({
                workplace_ids: bulkRequest.workplaceIds,
                is_enabled: bulkRequest.isEnabled,
                deadline,
            });
            notifications.show({ color: 'teal', message: t('Successfully updated change config') });
            setSelectedRecords([]);
            setBulkRequest(null);
        } catch {
            notifications.show({ color: 'red', message: t('error|Could not update change config') });
        }
    };

    return (
        <>
            <Breadcrumbs>
                <Anchor component={Link} href={RouteConfig.HOME.path}>
                    {t('Home')}
                </Anchor>
                <Text>{t('Change config')}</Text>
            </Breadcrumbs>
            <Flex justify="space-between" align="center">
                <Title order={4} lh="48px" my="md">
                    {t('Change config')}
                </Title>
                {selectedRecords.length > 0 && perms.FIXED_SCHEDULE_CHANGE_CONFIG_EDIT && (
                    <Flex gap="xs">
                        <Button color="teal" onClick={() => openBulkModal(true)}>
                            {t('Enable for {{count}} workplaces', { count: selectedRecords.length })}
                        </Button>
                        <Button color="red" onClick={() => openBulkModal(false)}>
                            {t('Disable for {{count}} workplaces', { count: selectedRecords.length })}
                        </Button>
                    </Flex>
                )}
            </Flex>
            <CommonSearchBox
                initialValues={{ workplace_id: null }}
                elements={[
                    {
                        id: 'workplace_id',
                        type: 'select',
                        label: t('Workplace'),
                        name: 'workplace_id',
                        options: workplaceOptions,
                        isSearchable: true,
                    },
                ]}
            />
            <Paper mt="md" p="md">
                <DDataTable
                    tableKey="fixed-schedule-change-config"
                    columns={[
                        {
                            name: t('Workplace'),
                            key: 'workplace',
                            width: 300,
                            render: (row: Row) => (
                                <Anchor
                                    component={Link}
                                    href={RouteHelper.getPath('ADMIN_WORKPLACE_DETAIL', { id: row.workplace_id })}
                                >
                                    {row.workplace.name}
                                </Anchor>
                            ),
                        },
                        {
                            name: t('Status'),
                            key: 'is_enabled',
                            width: 220,
                            render: (row: Row) => {
                                const canEdit = perms.FIXED_SCHEDULE_CHANGE_CONFIG_EDIT && row.is_editable;
                                const blockedFromEnabling = !row.is_enabled && !isValidDeadline(row.deadline);
                                const switchEl = (
                                    <Switch
                                        checked={row.is_enabled}
                                        onChange={() => handleToggle(row)}
                                        disabled={!canEdit || blockedFromEnabling}
                                    />
                                );
                                return (
                                    <Flex align="center" gap="xs">
                                        {canEdit && blockedFromEnabling ? (
                                            <Tooltip label={t('Please set a deadline of today or later first')}>
                                                <span>{switchEl}</span>
                                            </Tooltip>
                                        ) : (
                                            switchEl
                                        )}
                                        {row.is_enabled && !row.is_window_open && (
                                            <Badge color="orange">{t('Expired')}</Badge>
                                        )}
                                    </Flex>
                                );
                            },
                        },
                        {
                            name: t('Deadline'),
                            key: 'deadline',
                            width: 200,
                            render: (row: Row) => (
                                <DatePickerInput
                                    valueFormat={t('FORMAT_DATE')}
                                    value={row.deadline}
                                    onChange={(value) => handleDeadlineChange(row, value)}
                                    disabled={!perms.FIXED_SCHEDULE_CHANGE_CONFIG_EDIT || !row.is_editable}
                                    w={180}
                                />
                            ),
                        },
                    ]}
                    data={rows}
                    isFetching={isPending}
                    selectedRecords={selectedRecords}
                    onSelectedRecordsChange={setSelectedRecords}
                    isRecordSelectable={(row: Row) => row.is_editable}
                    noPermission={!perms.FIXED_SCHEDULE_CHANGE_CONFIG_LIST}
                />
            </Paper>

            <BulkConfigModal
                request={bulkRequest}
                onConfirm={handleBulkConfirm}
                onCancel={() => setBulkRequest(null)}
                isProcessing={bulkMutation.isPending}
            />
        </>
    );
};

export default IndexPage;
