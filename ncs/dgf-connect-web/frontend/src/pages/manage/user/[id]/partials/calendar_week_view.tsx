import React, { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Box, LoadingOverlay, ScrollArea } from '@mantine/core';
import dayjs, { type Dayjs } from 'dayjs';
import { CalendarCard } from '@/types/shift';
import DateHelper, { DATE_STORE_FORMAT } from '@/helpers/date.helper';
import classes from '../index.module.css';
import ShiftInfoModal from './shift_info_modal';

type Props = {
    start: Dayjs;
    end: Dayjs;
    cards: Record<string, CalendarCard[]>;
    loading: boolean;
};

type WeekCard = CalendarCard & {
    offset: number;
    height: number;
};

const getLength = (start: string, end: string): number => {
    const startTime = dayjs(start, 'HH:mm');
    const endTime = dayjs(end, 'HH:mm');
    return endTime.diff(startTime, 'minute') / 30;
};

const convertToWeekCard = (card: CalendarCard): WeekCard => {
    const offsetBlock = getLength('00:00', card.startTime);
    const heightBlock = getLength(card.startTime, card.endTime);
    let offset = offsetBlock * ROW_HEIGHT;
    let height = heightBlock * ROW_HEIGHT;
    let closest = Math.floor(offsetBlock) * ROW_HEIGHT;
    if (offset - closest < 6) {
        height -= closest + 6 - offset;
        offset = closest + 6;
    }
    closest = Math.floor(offsetBlock + heightBlock) * ROW_HEIGHT;
    if (offset + height - closest < 6) {
        height = closest - offset - 6;
    }

    return {
        offset,
        height,
        ...card,
    };
};

const WEEK_DAYS = [1, 2, 3, 4, 5, 6, 0];
const ROW_HEIGHT = 32;

const CalendarWeekView = ({ start, end, cards: calendarCards, loading }: Props) => {
    const { t } = useTranslation();
    const [selectedCard, setSelectedCard] = useState<CalendarCard | null>(null);
    const dates = useMemo(() => {
        const dates = [];
        for (let i = 0; i < 7; i++) {
            const date = start.add(i, 'day');
            dates.push({
                key: date.format(DATE_STORE_FORMAT),
                display: date.format('MM/DD'),
                isToday: date.isSame(DateHelper.now(), 'day'),
            });
        }

        return dates;
    }, [start, end]);

    const hours = useMemo(() => {
        const hours: string[] = [];
        const start = dayjs().hour(0).minute(0);
        for (let i = 0; i < 48; i++) {
            hours.push(start.add(i * 30, 'minute').format('HH:mm'));
        }

        return hours;
    }, []);

    const cards: Record<string, WeekCard[]> = useMemo(() => {
        const cards: Record<string, WeekCard[]> = {};
        for (const date of Object.keys(calendarCards)) {
            cards[date] = calendarCards[date].map((card) => convertToWeekCard(card));
        }

        return cards;
    }, [calendarCards]);

    const handleCardClick = (card: CalendarCard) => {
        setSelectedCard(card);
    };

    const handleCloseShiftInfoModal = () => {
        setSelectedCard(null);
    };

    return (
        <div className={classes.weekView}>
            <LoadingOverlay visible={loading} />
            <div className={`${classes.row} ${classes.header}`}>
                <div className={classes.cell}></div>
                {dates.map((item, index) => {
                    return (
                        <div key={item.key} className={`${classes.cell} ${item.isToday ? classes.today : ''}`}>
                            {DateHelper.getDayOfWeek(WEEK_DAYS[index])} ({item.display})
                        </div>
                    );
                })}
            </div>
            <ScrollArea h={600} className={classes.scrollArea}>
                <div style={{ position: 'relative' }}>
                    <div className={classes.eventWrapper}>
                        {dates.map((item) => {
                            const dayCards = cards[item.key] || [];
                            return (
                                <div key={item.key} className={classes.eventColumn}>
                                    {dayCards.map((card) => (
                                        <Box
                                            key={card.id}
                                            className={`${classes.weekCard} ${
                                                card.hasCheckIn ? classes.checkedIn : ''
                                            } ${card.isAbsent ? classes.absent : ''}`}
                                            style={{
                                                top: `${card.offset}px`,
                                                height: `${card.height}px`,
                                            }}
                                            onClick={() => handleCardClick(card)}
                                        >
                                            <div className={classes.cardHeader}>{card.workplaceName}</div>
                                            <div>
                                                {card.startTime} ~ {card.endTime}
                                                <br />
                                                {t('Position: {{position}}', { position: card.position })}
                                            </div>
                                            {card.hasCheckIn && (
                                                <div>{t('Work hour: {{hour}}h', { hour: card.workHours })}</div>
                                            )}
                                        </Box>
                                    ))}
                                </div>
                            );
                        })}
                    </div>
                    {hours.map((hour) => (
                        <div key={hour} className={classes.row}>
                            <div className={classes.cell}>{hour}</div>
                            {dates.map((item) => (
                                <div
                                    key={item.key}
                                    className={`${classes.cell} ${item.isToday ? classes.today : ''}`}
                                ></div>
                            ))}
                        </div>
                    ))}
                </div>
            </ScrollArea>
            <ShiftInfoModal card={selectedCard} onClose={handleCloseShiftInfoModal} />
        </div>
    );
};

export default CalendarWeekView;
