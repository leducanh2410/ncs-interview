import React, { useMemo } from 'react';
import { useTranslation } from 'react-i18next';
import { LuPlus, LuUpload } from 'react-icons/lu';
import Link from 'next/link';
import { useRouter } from 'next/router';
import { Anchor, Badge, Breadcrumbs, Button, Flex, Paper, Text, Title } from '@mantine/core';
import { useDisclosure } from '@mantine/hooks';
import { useAtom } from 'jotai';
import RouteConfig from '@/configs/route.config';
import { ShiftStatusEnum } from '@/enums/shift_status.enum';
import DateHelper, { DATE_STORE_FORMAT } from '@/helpers/date.helper';
import EnumHelper from '@/helpers/enum.helper';
import RouteHelper from '@/helpers/route.helper';
import ShiftHelper from '@/helpers/shift.helper';
import WorkplaceHelper from '@/helpers/workplace.helper';
import CommonSearchBox from '@/components/CommonSearchBox';
import DDataTable from '@/components/DDataTable';
import { masterAtom } from '@/pages/global.state';
import ImportOrderModal from '@/pages/manage/shift/partials/import_order_modal';
import ShiftCreateModal from '@/pages/manage/shift/partials/shift_create_modal';
import { usePermissions } from '@/hooks/usePermission';
import { useGetShiftsQuery } from '@/pages/manage/shift/api';

const IndexPage = () => {
    const { t } = useTranslation();
    const perms = usePermissions([
        'SHIFT_LIST',
        'SHIFT_CREATE',
        'SHIFT_EDIT',
        'SHIFT_EXPORT',
        'SHIFT_IMPORT',
        'SHIFT_IMPORT_ORDER',
    ]);
    const [masterData] = useAtom(masterAtom);
    const router = useRouter();
    const { data, isLoading } = useGetShiftsQuery(router.query, perms.SHIFT_LIST);
    const [isShowCreate, { open: openCreate, close: closeCreate }] = useDisclosure(false);
    const [isShowImportOrder, { open: openImportOrder, close: closeImportOrder }] = useDisclosure(false);
    const statusFilter = (router.query.status as string) || null;

    const filteredData = useMemo(() => {
        const shifts = data?.data || [];
        if (!statusFilter) return shifts;
        return shifts.filter((shift) => ShiftHelper.getStatus(shift) === statusFilter);
    }, [data?.data, statusFilter]);

    let date = router.query.date as string | undefined;
    if (!date) {
        date = DateHelper.now().format(DATE_STORE_FORMAT);
    }

    const accessibleWorkplaces = WorkplaceHelper.filterWorkplacesByAccess(
        masterData.workplaces,
        masterData.workplace_access,
        'shift',
    );

    return (
        <>
            <Breadcrumbs>
                <Anchor component={Link} href={RouteConfig.HOME.path}>
                    {t('Home')}
                </Anchor>
                <Text>{t('Manage shifts')}</Text>
            </Breadcrumbs>
            <Flex justify="space-between" align="center">
                <Title order={4} lh="48px" my="md">
                    {t('Manage shifts')}
                </Title>
                <Flex gap="sm" align="center">
                    {perms.SHIFT_IMPORT_ORDER && (
                        <Button variant="light" leftSection={<LuUpload size={16} />} onClick={openImportOrder}>
                            {t('Import shift order')}
                        </Button>
                    )}
                    {perms.SHIFT_CREATE && (
                        <Button leftSection={<LuPlus />} onClick={openCreate}>
                            {t('Create shift')}
                        </Button>
                    )}
                </Flex>
            </Flex>
            <CommonSearchBox
                initialValues={{ date: DateHelper.now().toDate(), workplace_id: null, status: '' }}
                elements={[
                    {
                        id: 'date',
                        type: 'date',
                        label: t('Date'),
                        name: 'date',
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
                    },
                    {
                        id: 'status',
                        type: 'select',
                        label: t('Status'),
                        name: 'status',
                        options: EnumHelper.toSelectArray(ShiftStatusEnum),
                        showAll: true,
                        allValue: '',
                        isClearable: false,
                    },
                ]}
            />
            <Paper p="md" mt="md">
                <DDataTable
                    columns={[
                        {
                            name: t('ID'),
                            key: 'id',
                            render: (data) => (
                                <Anchor
                                    component={Link}
                                    href={RouteHelper.getPath('MANAGE_SHIFT_DETAIL', { id: data.id })}
                                >
                                    {data.id}
                                </Anchor>
                            ),
                        },
                        {
                            name: t('Workplace'),
                            key: 'workplace.name',
                            render: (data) => data.workplace.name,
                        },
                        {
                            name: t('Shift'),
                            key: 'workplaceShift',
                            render: (data) => data.workplace_shift.start_time + ' ~ ' + data.workplace_shift.end_time,
                        },
                        {
                            name: t('Status'),
                            key: 'status',
                            render: (data) => {
                                const status = ShiftHelper.getStatus(data);
                                return (
                                    <Badge variant="light" color={EnumHelper.getColor(ShiftStatusEnum, status)}>
                                        {EnumHelper.getText(ShiftStatusEnum, status)}
                                    </Badge>
                                );
                            },
                        },
                        {
                            name: t('Register'),
                            key: 'register',
                            render: (data) => (
                                <>
                                    <Text size="sm">
                                        {t('Fixed')}: {data.fixed_count} / {data.fixed_total} (
                                        {data.fixed_pending_count} {t('pending')})
                                    </Text>
                                    <Text size="sm">
                                        {t('Temp')}: {data.temp_count} / {data.temp_total} ({data.temp_pending_count}{' '}
                                        {t('pending')})
                                    </Text>
                                </>
                            ),
                        },
                        {
                            name: t('Confirmed'),
                            key: 'confirmed',
                            render: (data) => (
                                <>
                                    <Text size="sm">
                                        {t('Fixed')}: {data.fixed_confirmed} / {data.fixed_count}
                                    </Text>
                                    <Text size="sm">
                                        {t('Temp')}: {data.temp_confirmed} / {data.temp_count}
                                    </Text>
                                </>
                            ),
                        },
                        {
                            name: t('Check in'),
                            key: 'check_in',
                            render: (data) => (
                                <>
                                    <Text size="sm">
                                        {t('Fixed')}: {data.fixed_check_in} / {data.fixed_count}
                                    </Text>
                                    <Text size="sm">
                                        {t('Temp')}: {data.temp_check_in} / {data.temp_count}
                                    </Text>
                                </>
                            ),
                        },
                        {
                            name: t('Break time'),
                            key: 'break_minutes',
                            render: (data) => {
                                if (data.break_minutes === null) {
                                    return '-';
                                }
                                return DateHelper.formatMinuteToHour(data.break_minutes);
                            },
                        },
                    ]}
                    data={filteredData}
                    isFetching={isLoading}
                    noPermission={!perms.SHIFT_LIST}
                />
            </Paper>
            <ShiftCreateModal isOpened={isShowCreate} onClose={closeCreate} date={date} />
            <ImportOrderModal isShow={isShowImportOrder} onClose={closeImportOrder} />
        </>
    );
};

export default IndexPage;
