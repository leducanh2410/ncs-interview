import React, { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { LuFactory, LuPencilLine, LuPlus, LuTrash2 } from 'react-icons/lu';
import Link from 'next/link';
import { useRouter } from 'next/router';
import {
    ActionIcon,
    Anchor,
    Badge,
    Breadcrumbs,
    Button,
    Flex,
    Group,
    Paper,
    Stack,
    Text,
    Title,
    Tooltip,
} from '@mantine/core';
import { useDisclosure } from '@mantine/hooks';
import { notifications } from '@mantine/notifications';
import { NotificationWithRelation } from '@/types/models/notification';
import RouteConfig from '@/configs/route.config';
import { ErrorCodeEnum } from '@/enums/error_code.enum';
import { BASE_LOCALE, LocaleEnum, getLocaleFlag } from '@/enums/locale.enum';
import { getNotificationIconComponent } from '@/enums/notification_icon.enum';
import { NotificationStatusEnum, getStatusColor } from '@/enums/notification_status.enum';
import { NotificationTargetTypeEnum } from '@/enums/notification_target_type.enum';
import { NotificationTypeEnum } from '@/enums/notification_type.enum';
import { ApiError } from '@/helpers/api.helper';
import DateHelper, { DATE_STORE_FORMAT_WITH_TIME } from '@/helpers/date.helper';
import EnumHelper from '@/helpers/enum.helper';
import NotificationHelper from '@/helpers/notification.helper';
import CommonSearchBox from '@/components/CommonSearchBox';
import DConfirmModal from '@/components/DConfirmModal';
import DDataTable, { headerWrapClassName } from '@/components/DDataTable';
import NotificationCreateUpdate from '@/pages/manage/notification/partials/notification_create_update';
import { usePermissions } from '@/hooks/usePermission';
import { useDeleteNotificationMutation, useGetNotificationsQuery } from '@/pages/manage/notification/api';

const IndexPage = () => {
    const { t } = useTranslation();
    const perms = usePermissions([
        'NOTIFICATION_LIST',
        'NOTIFICATION_EDIT',
        'NOTIFICATION_CREATE',
        'NOTIFICATION_DELETE',
    ]);
    const router = useRouter();
    const [createUpdateModalOpened, createUpdateModalAction] = useDisclosure(false);
    const [currentNotification, setCurrentNotification] = useState<NotificationWithRelation | null>(null);
    const { data, isLoading } = useGetNotificationsQuery(router.query, perms.NOTIFICATION_LIST);
    const { mutateAsync: deleteNotification } = useDeleteNotificationMutation();
    const [deleteId, setDeleteId] = React.useState<string | null>(null);

    const now = DateHelper.now();
    const from = now.subtract(7, 'days');
    return (
        <>
            <Breadcrumbs>
                <Anchor component={Link} href={RouteConfig.HOME.path}>
                    {t('Home')}
                </Anchor>
                <Text>{t('Notification management')}</Text>
            </Breadcrumbs>
            <Flex justify="space-between" align="center">
                <Title order={4} lh="48px" my="md">
                    {t('Notification management')}
                </Title>
                <Button
                    leftSection={<LuPlus />}
                    onClick={() => {
                        setCurrentNotification(null);
                        createUpdateModalAction.open();
                    }}
                    disabled={!perms.NOTIFICATION_CREATE}
                >
                    {t('Create notification')}
                </Button>
            </Flex>
            <CommonSearchBox
                initialValues={{
                    dates: [from.toDate(), now.toDate()],
                    type: 'all',
                    status: 'all',
                }}
                elements={[
                    {
                        id: 'dates',
                        type: 'date-range',
                        label: t('Send day'),
                        name: 'dates',
                    },
                    {
                        id: 'type',
                        type: 'select',
                        label: t('Notification type'),
                        name: 'type',
                        options: EnumHelper.toSelectArray(NotificationTypeEnum),
                        isClearable: false,
                        showAll: true,
                        allValue: 'all',
                    },
                    {
                        id: 'status',
                        type: 'select',
                        label: t('Status'),
                        name: 'status',
                        options: EnumHelper.toSelectArray(NotificationStatusEnum),
                        isClearable: false,
                        showAll: true,
                        allValue: 'all',
                    },
                ]}
            />
            <Paper p="md" mt="md">
                <DDataTable
                    columns={[
                        {
                            name: t('Send day'),
                            key: 'scheduled_at',
                            render: (data) => {
                                return DateHelper.parseAndFormat(
                                    data.scheduled_at,
                                    DATE_STORE_FORMAT_WITH_TIME,
                                    t('FORMAT_DATE_WITH_TIME'),
                                );
                            },
                        },
                        {
                            name: t('Notification type'),
                            key: 'type',
                            render: (data) => {
                                return EnumHelper.getText(NotificationTypeEnum, data.type);
                            },
                        },
                        {
                            name: t('Icon'),
                            key: 'icon',
                            render: (data) => {
                                let Icon = data.icon ? getNotificationIconComponent(data.icon) : LuFactory;
                                return <Icon size={20} color="var(--mantine-color-blue-6)" />;
                            },
                        },
                        {
                            name: t('Title'),
                            key: 'title',
                            render: (data) => {
                                return (
                                    <Stack gap={4} miw={180}>
                                        <Group gap={6} wrap="nowrap" align="flex-start">
                                            <Tooltip label={EnumHelper.getText(LocaleEnum, BASE_LOCALE)}>
                                                <Text size="sm" span>
                                                    {getLocaleFlag(BASE_LOCALE)}
                                                </Text>
                                            </Tooltip>
                                            <Tooltip
                                                label={data.title}
                                                multiline
                                                w={300}
                                                disabled={data.title.length <= 30}
                                            >
                                                <Text size="sm" fw={500} lineClamp={2}>
                                                    {data.title}
                                                </Text>
                                            </Tooltip>
                                        </Group>
                                        {data.translations
                                            .filter((translation) => !translation.is_fallback)
                                            .map((translation) => (
                                                <Group key={translation.id} gap={6} wrap="nowrap" align="flex-start">
                                                    <Tooltip label={EnumHelper.getText(LocaleEnum, translation.lang)}>
                                                        <Text size="sm" span>
                                                            {getLocaleFlag(translation.lang)}
                                                        </Text>
                                                    </Tooltip>
                                                    <Tooltip
                                                        label={translation.title}
                                                        multiline
                                                        w={300}
                                                        disabled={translation.title.length <= 30}
                                                    >
                                                        <Text size="xs" c="dimmed" lineClamp={1}>
                                                            {translation.title}
                                                        </Text>
                                                    </Tooltip>
                                                </Group>
                                            ))}
                                    </Stack>
                                );
                            },
                        },
                        {
                            name: t('Content'),
                            key: 'content',
                            render: (data) => {
                                return (
                                    <Stack gap={4} miw={220}>
                                        <Group gap={6} wrap="nowrap" align="flex-start">
                                            <Tooltip label={EnumHelper.getText(LocaleEnum, BASE_LOCALE)}>
                                                <Text size="sm" span>
                                                    {getLocaleFlag(BASE_LOCALE)}
                                                </Text>
                                            </Tooltip>
                                            <Tooltip
                                                label={data.content}
                                                multiline
                                                w={300}
                                                disabled={data.content.length <= 40}
                                            >
                                                <Text size="sm" lineClamp={2}>
                                                    {data.content}
                                                </Text>
                                            </Tooltip>
                                        </Group>
                                        {data.translations
                                            .filter((translation) => !translation.is_fallback)
                                            .map((translation) => (
                                                <Group key={translation.id} gap={6} wrap="nowrap" align="flex-start">
                                                    <Tooltip label={EnumHelper.getText(LocaleEnum, translation.lang)}>
                                                        <Text size="sm" span>
                                                            {getLocaleFlag(translation.lang)}
                                                        </Text>
                                                    </Tooltip>
                                                    <Tooltip
                                                        label={translation.content}
                                                        multiline
                                                        w={300}
                                                        disabled={translation.content.length <= 40}
                                                    >
                                                        <Text size="xs" c="dimmed" lineClamp={2}>
                                                            {translation.content}
                                                        </Text>
                                                    </Tooltip>
                                                </Group>
                                            ))}
                                    </Stack>
                                );
                            },
                        },
                        {
                            name: t('Status'),
                            key: 'status',
                            width: '130px',
                            render: (data) => {
                                return (
                                    <Badge color={getStatusColor(data.status)}>
                                        {EnumHelper.getText(NotificationStatusEnum, data.status)}
                                    </Badge>
                                );
                            },
                        },
                        {
                            name: t('Target'),
                            key: 'target',
                            render: (data) => {
                                if (data.type === NotificationTypeEnum.RECRUITMENT.value) return <>-</>;
                                const workplaces = data.workplaces || [];
                                if (data.target_type !== NotificationTargetTypeEnum.WORKPLACE.value) {
                                    return <>{t('All employees')}</>;
                                }
                                return (
                                    <Tooltip
                                        label={workplaces.map((workplace) => workplace.name).join(', ')}
                                        multiline
                                        w={300}
                                        disabled={workplaces.length === 0}
                                    >
                                        <Text size="sm">{t('{{count}} workplaces', { count: workplaces.length })}</Text>
                                    </Tooltip>
                                );
                            },
                        },
                        {
                            name: t('Shift'),
                            key: 'shift',
                            render: (data) => {
                                if (!data.shift) return <>-</>;
                                const { startTime, endTime } = DateHelper.formatWorkHour(
                                    data.shift.workplace_shift.start_time,
                                    data.shift.workplace_shift.end_time,
                                );
                                return (
                                    <div>
                                        {data.shift.workplace_shift.workplace.name}
                                        <br />
                                        {`${startTime} ~ ${endTime}`}
                                    </div>
                                );
                            },
                        },
                        {
                            name: t('Read / received'),
                            key: 'read_received',
                            width: '110px',
                            titleClassName: headerWrapClassName,
                            render: (data) => {
                                // Only targeted notifications (recruitment, selected workplaces) are sent
                                // to a resolved list; a broadcast has no audience to count.
                                const isTargeted =
                                    data.type === NotificationTypeEnum.RECRUITMENT.value ||
                                    data.target_type === NotificationTargetTypeEnum.WORKPLACE.value;
                                if (!isTargeted) return <>-</>;
                                // Null means the list was never resolved, which is a different
                                // thing from having reached nobody. Only a pending notification
                                // is still waiting for that: one sent before targeting existed
                                // was broadcast and has no audience to count, and a failed one
                                // already tried and is never retried, so neither is "not sent
                                // yet" — both are shown as having no figure.
                                if (data.recipients_resolved_count === null) {
                                    return data.status === NotificationStatusEnum.PENDING.value ? (
                                        <>{t('Not sent yet')}</>
                                    ) : (
                                        <>-</>
                                    );
                                }
                                return (
                                    <>
                                        {data.read_count ?? 0} / {data.recipients_resolved_count}
                                    </>
                                );
                            },
                        },
                        {
                            name: t('Action'),
                            key: 'action',
                            width: '80px',
                            render: (data) => {
                                if (data.status === 'sent') {
                                    return '-';
                                }

                                return (
                                    <Flex>
                                        <ActionIcon
                                            variant="subtle"
                                            color="blue"
                                            onClick={() => {
                                                setCurrentNotification(data);
                                                createUpdateModalAction.open();
                                            }}
                                            disabled={!perms.NOTIFICATION_EDIT}
                                        >
                                            <LuPencilLine size={16} />
                                        </ActionIcon>
                                        <ActionIcon
                                            variant="subtle"
                                            color="red"
                                            onClick={() => setDeleteId(data.id.toString())}
                                            disabled={!perms.NOTIFICATION_DELETE}
                                        >
                                            <LuTrash2 size={16} />
                                        </ActionIcon>
                                    </Flex>
                                );
                            },
                        },
                    ]}
                    data={data?.data || []}
                    isFetching={isLoading}
                    noPermission={!perms.NOTIFICATION_LIST}
                />
            </Paper>
            <NotificationCreateUpdate
                notification={currentNotification}
                opened={createUpdateModalOpened}
                onClose={createUpdateModalAction.close}
            />
            <DConfirmModal
                opened={deleteId !== null}
                title={t('Delete notification')}
                message={t('Are you sure you want to delete this notification? This action cannot be undone.')}
                onConfirm={async () => {
                    if (!deleteId) return;
                    try {
                        await deleteNotification(deleteId);
                        notifications.show({
                            color: 'teal',
                            message: t('Successfully deleted notification'),
                        });
                    } catch (e) {
                        let message = t('error|Could not delete notification');
                        if (e instanceof ApiError) {
                            if (e.message === ErrorCodeEnum.NOTIFICATION_ALREADY_SENT.value) {
                                message = t('error|Could not delete. This notification has already been sent');
                            }
                        }
                        NotificationHelper.showError(message);
                    } finally {
                        setDeleteId(null);
                    }
                }}
                onCancel={() => setDeleteId(null)}
                confirmButtonLabel={t('Delete notification')}
                confirmButtonColor="red"
            />
        </>
    );
};
export default IndexPage;
