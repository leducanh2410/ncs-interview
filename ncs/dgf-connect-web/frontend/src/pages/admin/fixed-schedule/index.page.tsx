import React, { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { LuPencilLine, LuPlus, LuTrash2 } from 'react-icons/lu';
import Link from 'next/link';
import { useRouter } from 'next/router';
import { ActionIcon, Anchor, Box, Breadcrumbs, Button, Flex, Paper, Text, Title } from '@mantine/core';
import { useDisclosure } from '@mantine/hooks';
import { notifications } from '@mantine/notifications';
import { useAtom } from 'jotai';
import { FixedScheduleWithRelation } from '@/types/models/fixed_schedule';
import { ApiConfig } from '@/configs/api.config';
import RouteConfig from '@/configs/route.config';
import DateHelper from '@/helpers/date.helper';
import RouteHelper from '@/helpers/route.helper';
import WorkplaceHelper from '@/helpers/workplace.helper';
import CommonSearchBox, { userRenderItem } from '@/components/CommonSearchBox';
import DConfirmModal from '@/components/DConfirmModal';
import DDataTable from '@/components/DDataTable';
import UserWithAvatar from '@/components/UserWithAvatar';
import FixedScheduleCreateUpdate from '@/pages/admin/fixed-schedule/partials/fixed_schedule_create_update';
import { masterAtom } from '@/pages/global.state';
import { usePermissions } from '@/hooks/usePermission';
import { useDeleteFixedScheduleMutation, useGetFixedSchedulesQuery } from '@/pages/admin/fixed-schedule/api';

const IndexPage = () => {
    const { t } = useTranslation();
    const perms = usePermissions([
        'FIXED_SCHEDULE_LIST',
        'FIXED_SCHEDULE_CREATE',
        'FIXED_SCHEDULE_EDIT',
        'FIXED_SCHEDULE_DELETE',
        'WORKPLACE_SHIFT_LIST',
        'WORKPLACE_POSITION_LIST',
    ]);
    const canCreateFixedSchedule = perms.FIXED_SCHEDULE_CREATE && perms.WORKPLACE_SHIFT_LIST;
    const canEditFixedSchedule = perms.FIXED_SCHEDULE_EDIT && perms.WORKPLACE_SHIFT_LIST;
    const router = useRouter();
    const { data, isPending } = useGetFixedSchedulesQuery(router.query, perms.FIXED_SCHEDULE_LIST);
    const deleteMutation = useDeleteFixedScheduleMutation();
    const [deleteId, setDeleteId] = React.useState<string | null>(null);
    const [createUpdateModalOpened, createUpdateModalAction] = useDisclosure(false);
    const [currentFixedSchedule, setCurrentFixedSchedule] = useState<FixedScheduleWithRelation | null>(null);
    const [masterData] = useAtom(masterAtom);
    const accessibleWorkplaces = WorkplaceHelper.filterWorkplacesByAccess(
        masterData.workplaces,
        masterData.workplace_access,
        'fixed_schedule',
    );

    return (
        <>
            <Breadcrumbs>
                <Anchor component={Link} href={RouteConfig.HOME.path}>
                    {t('Home')}
                </Anchor>
                <Text>{t('Fixed schedules')}</Text>
            </Breadcrumbs>
            <Flex justify="space-between" align="center">
                <Title order={4} lh="48px" my="md">
                    {t('Fixed schedules')}
                </Title>
                <Button
                    leftSection={<LuPlus />}
                    onClick={() => {
                        setCurrentFixedSchedule(null);
                        createUpdateModalAction.open();
                    }}
                    disabled={!canCreateFixedSchedule}
                >
                    {t('Create schedule')}
                </Button>
            </Flex>
            <CommonSearchBox
                initialValues={{ workplace_id: null, user_id: null }}
                elements={[
                    {
                        id: 'workplace_id',
                        type: 'select',
                        label: t('Workplace'),
                        name: 'workplace_id',
                        options: WorkplaceHelper.toSelectOptions(accessibleWorkplaces, t),
                        isSearchable: true,
                    },
                    {
                        id: 'user_id',
                        type: 'select-remote',
                        label: t('User'),
                        name: 'user_id',
                        uri: ApiConfig.ADMIN_MASTER_USER_SEARCH,
                        renderItem: userRenderItem,
                    },
                ]}
            />
            <Paper mt="md" p="md">
                <DDataTable
                    columns={[
                        {
                            name: t('Workplace'),
                            key: 'workplace',
                            render: (data) => (
                                <Anchor
                                    component={Link}
                                    href={RouteHelper.getPath('ADMIN_WORKPLACE_DETAIL', { id: data.workplace_id })}
                                >
                                    {data.workplace.name}
                                </Anchor>
                            ),
                        },
                        {
                            name: t('User'),
                            key: 'user',
                            render: (data) => <UserWithAvatar user={data.user} />,
                        },
                        {
                            name: t('Schedule'),
                            key: 'details',
                            render: (data) => {
                                const details = data.details.sort((a, b) => {
                                    if (a.day === 0) return 1;
                                    if (b.day === 0) return -1;
                                    return a.day - b.day;
                                });
                                return (
                                    <Box>
                                        {details.map((detail) => {
                                            const { startTime, endTime } = DateHelper.formatWorkHour(
                                                detail.workplace_shift.start_time,
                                                detail.workplace_shift.end_time,
                                            );

                                            return (
                                                <Box key={detail.id}>
                                                    <Text tt="capitalize" span>
                                                        {DateHelper.getDayOfWeek(detail.day)}:&nbsp;
                                                    </Text>
                                                    <span>{`${startTime} ~ ${endTime}`}</span>
                                                    {detail.workplace_position && (
                                                        <Text c="dimmed" span>
                                                            {` · ${detail.workplace_position.name}`}
                                                        </Text>
                                                    )}
                                                </Box>
                                            );
                                        })}
                                    </Box>
                                );
                            },
                        },
                        {
                            name: t('Action'),
                            key: 'action',
                            render: (data) => (
                                <Flex>
                                    <ActionIcon
                                        variant="subtle"
                                        color="blue"
                                        onClick={() => {
                                            setCurrentFixedSchedule(data);
                                            createUpdateModalAction.open();
                                        }}
                                        disabled={!canEditFixedSchedule}
                                    >
                                        <LuPencilLine size={16} />
                                    </ActionIcon>
                                    <ActionIcon
                                        variant="subtle"
                                        color="red"
                                        onClick={() => setDeleteId(data.id.toString())}
                                        disabled={!perms.FIXED_SCHEDULE_DELETE}
                                    >
                                        <LuTrash2 size={16} />
                                    </ActionIcon>
                                </Flex>
                            ),
                            width: '100px',
                        },
                    ]}
                    data={data?.data || []}
                    isFetching={isPending}
                    noPermission={!perms.FIXED_SCHEDULE_LIST}
                />
            </Paper>

            <FixedScheduleCreateUpdate
                fixedSchedule={currentFixedSchedule}
                opened={createUpdateModalOpened}
                onClose={createUpdateModalAction.close}
                hasWorkplaceShiftListPermission={perms.WORKPLACE_SHIFT_LIST}
                hasWorkplacePositionListPermission={perms.WORKPLACE_POSITION_LIST}
            />

            <DConfirmModal
                opened={deleteId !== null}
                title={t('Delete schedule')}
                message={t('Are you sure you want to delete this schedule? This action cannot be undone.')}
                onConfirm={async () => {
                    if (!deleteId) return;
                    try {
                        await deleteMutation.mutateAsync(deleteId);
                        notifications.show({
                            color: 'teal',
                            message: t('Successfully deleted schedule'),
                        });
                    } catch (e) {
                        notifications.show({
                            color: 'red',
                            message: t('error|Could not delete schedule'),
                        });
                    } finally {
                        setDeleteId(null);
                    }
                }}
                onCancel={() => setDeleteId(null)}
                confirmButtonLabel={t('Delete schedule')}
                confirmButtonColor="red"
            />
        </>
    );
};

export default IndexPage;
