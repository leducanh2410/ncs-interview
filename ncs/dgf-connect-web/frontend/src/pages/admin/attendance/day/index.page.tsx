import React from 'react';
import { useTranslation } from 'react-i18next';
import { LuArrowLeft, LuMapPin } from 'react-icons/lu';
import Link from 'next/link';
import { useRouter } from 'next/router';
import {
    ActionIcon,
    Alert,
    Anchor,
    Badge,
    Breadcrumbs,
    Button,
    Card,
    Group,
    Paper,
    Stack,
    Table,
    Text,
    Title,
} from '@mantine/core';
import { AdminAttendanceEventType } from '@/types/models/admin_attendance_event';
import { AdminAttendanceSession } from '@/types/models/admin_attendance_session';
import RouteConfig from '@/configs/route.config';
import DateHelper, { DATE_STORE_FORMAT_WITH_TIME } from '@/helpers/date.helper';
import RouteHelper from '@/helpers/route.helper';
import TableEmptyState from '@/components/TableEmptyState';
import { useGetAttendanceDayQuery } from '@/pages/admin/attendance/api';

const eventTypeColor = (type: AdminAttendanceEventType) => (type === 'check_in' ? 'blue' : 'teal');

const formatTime = (value: string) => DateHelper.parseAndFormat(value, DATE_STORE_FORMAT_WITH_TIME, 'YYYY-MM-DD HH:mm');

// The session is still checked in with no matching check-out yet — i.e. its most recent event
// is a check-in. This can be true whether or not `has_missing_checkout` is set: it is NOT flagged
// while this is the admin's current workplace for today (Current_Focus), but it IS flagged once
// superseded by another workplace or once the day is in the past — see spec Requirement 8.
const isSessionCurrentlyOpen = (session: AdminAttendanceSession): boolean => {
    const events = session.events ?? [];
    return events.length > 0 && events[events.length - 1].type === 'check_in';
};

// A check-out was recorded (for audit) but credited zero minutes because this workplace's
// interval was interrupted by a fully completed visit elsewhere before that check-out arrived.
const hasAuditOnlyCheckout = (session: AdminAttendanceSession, isOpen: boolean): boolean =>
    session.work_minutes === null && !isOpen && (session.events ?? []).some((event) => event.type === 'check_out');

const AdminAttendanceDayPage = () => {
    const { t } = useTranslation();
    const { query, back } = useRouter();

    const userId = query.user_id ? Number(query.user_id) : null;
    const date = query.date ? String(query.date) : null;

    const { data, isLoading } = useGetAttendanceDayQuery(userId, date);
    const sessions = data?.data ?? [];
    const admin = sessions[0]?.user;

    return (
        <>
            <Breadcrumbs>
                <Anchor component={Link} href={RouteConfig.HOME.path}>
                    {t('Home')}
                </Anchor>
                <Anchor component={Link} href={RouteConfig.ADMIN_ATTENDANCE_LIST.path}>
                    {t('Admin attendance')}
                </Anchor>
                <Text>{t('Admin attendance detail')}</Text>
            </Breadcrumbs>

            <Group justify="space-between" my="md">
                <Title order={4} lh="48px">
                    {t('Admin attendance detail')}
                    {date ? ` — ${DateHelper.parseAndFormat(date)}` : ''}
                    {admin ? ` — ${admin.name}${admin.staff_no ? ` (${admin.staff_no})` : ''}` : ''}
                </Title>
                <Button
                    variant="default"
                    leftSection={<LuArrowLeft size={16} />}
                    component={Link}
                    href={RouteConfig.ADMIN_ATTENDANCE_LIST.path}
                    onClick={(e: React.MouseEvent) => {
                        e.preventDefault();
                        back();
                    }}
                >
                    {t('Back')}
                </Button>
            </Group>

            {!isLoading && sessions.length === 0 ? (
                <Paper p="md">
                    <TableEmptyState />
                </Paper>
            ) : (
                <Stack gap="md">
                    {sessions.map((session) => {
                        const isOpen = isSessionCurrentlyOpen(session);
                        const hasCreditedHours = session.work_minutes !== null;
                        const intervals = session.intervals ?? [];
                        const auditOnlyCheckout = hasAuditOnlyCheckout(session, isOpen);
                        const isInvalidated = session.invalidated_reason !== null;

                        return (
                            <Card key={session.id} withBorder padding="md">
                                <Group justify="space-between" mb="xs">
                                    <Anchor
                                        component={Link}
                                        fw={600}
                                        href={RouteHelper.getPath('ADMIN_WORKPLACE_DETAIL', {
                                            id: session.workplace_id,
                                        })}
                                    >
                                        {session.workplace?.name}
                                    </Anchor>
                                    <Group gap="xs">
                                        {isInvalidated ? (
                                            <Badge color="red">{t('Passed deadline')}</Badge>
                                        ) : session.has_missing_checkout ? (
                                            <Badge color="yellow">{t('Missing check-out')}</Badge>
                                        ) : (
                                            isOpen && <Badge color="blue">{t('Still checked in')}</Badge>
                                        )}
                                    </Group>
                                </Group>

                                <Group gap="xl" mb="xs">
                                    <Text size="sm">
                                        {t('Start time')}: {formatTime(session.first_check_in_at)}
                                    </Text>
                                    <Text size="sm">
                                        {t('End time')}:{' '}
                                        {session.last_check_out_at ? formatTime(session.last_check_out_at) : '-'}
                                    </Text>
                                    {hasCreditedHours && (
                                        <Text size="sm">
                                            {t('Total hours')}: <b>{session.work_hours}</b>
                                        </Text>
                                    )}
                                </Group>

                                {isInvalidated ? (
                                    <Alert color="red" mb="xs">
                                        {hasCreditedHours
                                            ? t(
                                                  "This session's last check-in passed its check-out deadline, so only the time since that check-in was not counted — the hours shown above are still valid. The log below is retained for reference.",
                                              )
                                            : t(
                                                  'This check-in passed its check-out deadline, so its hours were not counted. The log below is retained for reference.',
                                              )}
                                    </Alert>
                                ) : (
                                    session.has_missing_checkout && (
                                        <Alert color="yellow" mb="xs">
                                            {t(
                                                'This workplace still has a check-in with no matching check-out. Hours after that check-in are not counted until it is closed.',
                                            )}
                                        </Alert>
                                    )
                                )}

                                {auditOnlyCheckout && (
                                    <Alert color="gray" mb="xs">
                                        {t(
                                            'A check-out was recorded below, but it did not count toward hours because this workplace was interrupted by a completed visit elsewhere before that check-out.',
                                        )}
                                    </Alert>
                                )}

                                {intervals.length > 0 && (
                                    <Stack gap={2} mb="xs">
                                        <Text size="sm" fw={600}>
                                            {t('Counted intervals')}
                                        </Text>
                                        {intervals.map((interval) => (
                                            <Text size="sm" key={interval.id}>
                                                {formatTime(interval.started_at)} → {formatTime(interval.ended_at)} (
                                                {(interval.minutes / 60).toFixed(2)})
                                            </Text>
                                        ))}
                                    </Stack>
                                )}

                                <Table withTableBorder withColumnBorders striped>
                                    <Table.Thead>
                                        <Table.Tr>
                                            <Table.Th>{t('Type')}</Table.Th>
                                            <Table.Th>{t('Time')}</Table.Th>
                                            <Table.Th>{t('Coordinate')}</Table.Th>
                                        </Table.Tr>
                                    </Table.Thead>
                                    <Table.Tbody>
                                        {(session.events ?? []).map((event) => (
                                            <Table.Tr key={event.id}>
                                                <Table.Td>
                                                    <Badge color={eventTypeColor(event.type)}>
                                                        {event.type === 'check_in' ? t('Check-in') : t('Check-out')}
                                                    </Badge>
                                                </Table.Td>
                                                <Table.Td>{formatTime(event.event_time)}</Table.Td>
                                                <Table.Td>
                                                    <Group gap={4} wrap="nowrap">
                                                        <Text size="sm">{event.coordinate}</Text>
                                                        <ActionIcon
                                                            component="a"
                                                            href={`https://www.google.com/maps?q=${event.coordinate}`}
                                                            target="_blank"
                                                            rel="noopener noreferrer"
                                                            variant="subtle"
                                                            color="blue"
                                                            size="sm"
                                                            aria-label={t('Open on Google Maps')}
                                                        >
                                                            <LuMapPin size={14} />
                                                        </ActionIcon>
                                                    </Group>
                                                </Table.Td>
                                            </Table.Tr>
                                        ))}
                                    </Table.Tbody>
                                </Table>
                            </Card>
                        );
                    })}
                </Stack>
            )}
        </>
    );
};

export default AdminAttendanceDayPage;
