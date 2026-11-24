import React, { useMemo } from 'react';
import { useTranslation } from 'react-i18next';
import { LuChevronLeft, LuChevronRight } from 'react-icons/lu';
import Link from 'next/link';
import { useRouter } from 'next/router';
import { ActionIcon, Anchor, Breadcrumbs, Button, Flex, Paper, SegmentedControl, Text, Title } from '@mantine/core';
import { CalendarCard } from '@/types/shift';
import RouteConfig from '@/configs/route.config';
import DateHelper, { DATE_STORE_FORMAT } from '@/helpers/date.helper';
import CalendarMonthView from '@/pages/manage/user/[id]/partials/calendar_month_view';
import CalendarWeekView from '@/pages/manage/user/[id]/partials/calendar_week_view';
import classes from './index.module.css';
import { useGetUserScheduleQuery } from '@/pages/manage/user/[id]/api';

const UserScheduleDetailPage = () => {
    const { t } = useTranslation();
    const { push, query } = useRouter();
    const mode = query.mode === 'week' ? 'week' : 'month';

    const { start, end } = useMemo(() => {
        let now = DateHelper.now();
        let date = now;
        if (query.date) {
            date = DateHelper.parse(query.date as string) || now;
        }

        const start = date.clone().startOf(mode);
        const end = date.clone().endOf(mode);

        return { start, end };
    }, [query.date, mode]);

    const { data, isLoading } = useGetUserScheduleQuery(query.id as string, {
        start_date: start.format(DATE_STORE_FORMAT),
        end_date: end.format(DATE_STORE_FORMAT),
    });

    const cards = useMemo(() => {
        if (!data || !data.data) {
            return {};
        }

        const event: Record<string, CalendarCard[]> = {};
        for (const shiftUser of data.data.shifts) {
            const date = shiftUser.shift.date;
            if (!event[date]) {
                event[date] = [];
            }
            const { startTime, endTime } = DateHelper.formatWorkHour(
                shiftUser.shift.workplace_shift.start_time,
                shiftUser.shift.workplace_shift.end_time,
            );
            event[date].push({
                id: shiftUser.id,
                shiftId: shiftUser.shift.id,
                workplaceName: shiftUser.shift.workplace.name,
                startTime,
                endTime,
                position: shiftUser.workplace_position.name,
                workHours: shiftUser.check_in ? shiftUser.check_in.work_hours : null,
                hasCheckIn: !!shiftUser.check_in,
                status: shiftUser.status,
                isAbsent: shiftUser.is_absent,
            });
        }

        return event;
    }, [data]);

    const onPrev = async () => {
        query.date = start.subtract(1, mode).format(DATE_STORE_FORMAT);
        await push({ query });
    };

    const onNext = async () => {
        query.date = start.add(1, mode).format(DATE_STORE_FORMAT);
        await push({ query });
    };

    return (
        <>
            <Breadcrumbs>
                <Anchor component={Link} href={RouteConfig.HOME.path}>
                    {t('Home')}
                </Anchor>
                <Text>{t('User schedule')}</Text>
            </Breadcrumbs>
            <Flex justify="space-between" align="center">
                <Title order={4} lh="48px" my="md">
                    {t('Working schedule of {{user}}', { user: data?.data?.user.name || '' })}
                </Title>
            </Flex>
            <Paper p="md" mt="md">
                <div className={classes.title}>
                    <Flex gap={4} w="200px">
                        <ActionIcon color="blue" variant="outline" onClick={onPrev} h={32} w={32}>
                            <LuChevronLeft size={16} />
                        </ActionIcon>
                        <ActionIcon color="blue" variant="outline" onClick={onNext} h={32} w={32}>
                            <LuChevronRight size={16} />
                        </ActionIcon>
                        <Button
                            variant="outline"
                            onClick={async () => {
                                delete query.date;
                                await push({ query });
                            }}
                            h={32}
                        >
                            {t('Today')}
                        </Button>
                    </Flex>
                    <strong>
                        {start.format(t('FORMAT_DATE'))} - {end.format(t('FORMAT_DATE'))}
                    </strong>
                    <Flex w="200px" justify="flex-end">
                        <SegmentedControl
                            value={mode}
                            size="xs"
                            miw="128px"
                            onChange={async (mode) => {
                                query.mode = mode;
                                await push({ query });
                            }}
                            data={[
                                { value: 'week', label: t('Week') },
                                { value: 'month', label: t('Month') },
                            ]}
                        />
                    </Flex>
                </div>
                {mode === 'week' ? (
                    <CalendarWeekView start={start} end={end} cards={cards} loading={isLoading} />
                ) : (
                    <CalendarMonthView start={start} end={end} cards={cards} loading={isLoading} />
                )}
            </Paper>
        </>
    );
};

export default UserScheduleDetailPage;
