import React, { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Anchor, Box, LoadingOverlay, Modal, Stack } from '@mantine/core';
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

const WEEK_DAYS = [1, 2, 3, 4, 5, 6, 0];

const CalendarMonthView = ({ start, end, cards, loading }: Props) => {
    const { t } = useTranslation();
    const [modalShifts, setModalShifts] = useState<CalendarCard[] | null>(null);
    const [modalDate, setModalDate] = useState<string>('');
    const [selectedCard, setSelectedCard] = useState<CalendarCard | null>(null);

    const dates = useMemo(() => {
        const dates = [];

        let startOfWeek = start.startOf('week');
        while (!startOfWeek.isAfter(end)) {
            const weekDays = [];
            for (let i = 0; i < 7; i++) {
                const date = startOfWeek.add(i, 'day');
                weekDays.push({
                    key: date.format(DATE_STORE_FORMAT),
                    monthDisplay: date.date() === 1 ? date.format('MMM') : '',
                    display: date.format('D'),
                    isToday: date.isSame(dayjs(), 'day'),
                    isThisMonth: date.isSame(start, 'month'),
                });
            }

            dates.push(weekDays);
            startOfWeek = startOfWeek.add(7, 'day');
        }

        return dates;
    }, [start, end]);

    const handleShowAllShifts = (date: string, shifts: CalendarCard[]) => {
        setModalDate(date);
        setModalShifts(shifts);
    };

    const handleCloseModal = () => {
        setModalShifts(null);
        setModalDate('');
    };

    const handleCardClick = (card: CalendarCard) => {
        setSelectedCard(card);
    };

    const handleCloseShiftInfoModal = () => {
        setSelectedCard(null);
    };

    return (
        <div className={classes.monthView}>
            <LoadingOverlay visible={loading} />
            <div className={`${classes.row} ${classes.header}`}>
                {WEEK_DAYS.map((item) => {
                    return (
                        <div key={item} className={classes.cell}>
                            {DateHelper.getDayOfWeek(item)}
                        </div>
                    );
                })}
            </div>
            {dates.map((weekDates) => {
                return (
                    <div key={weekDates[0].key} className={classes.row}>
                        {weekDates.map((item) => {
                            const dayCards = cards[item.key] || [];
                            const visibleCards = dayCards.slice(0, 2);
                            const remainingCount = dayCards.length - 2;
                            return (
                                <div
                                    key={item.key}
                                    className={`${classes.cell} ${item.isToday ? classes.today : ''} ${
                                        !item.isThisMonth ? classes.notThisMonth : ''
                                    }`}
                                >
                                    <div className={classes.date}>
                                        <div>{item.monthDisplay}</div>
                                        <div>{item.display}</div>
                                    </div>
                                    {visibleCards.map((card) => (
                                        <Box
                                            key={card.id}
                                            className={`${classes.monthCardCompact} ${
                                                card.hasCheckIn ? classes.checkedIn : ''
                                            } ${card.isAbsent ? classes.absent : ''}`}
                                            onClick={() => handleCardClick(card)}
                                            style={{ cursor: 'pointer' }}
                                        >
                                            <div className={classes.compactLine1}>
                                                <span className={classes.workplaceName}>{card.workplaceName}</span>
                                                <span className={classes.workTime}>
                                                    {card.startTime}~{card.endTime}
                                                </span>
                                            </div>
                                            <div className={classes.compactLine2}>
                                                <span className={classes.position}>{card.position}</span>
                                                {card.hasCheckIn && (
                                                    <span className={classes.workHours}>{card.workHours}h</span>
                                                )}
                                            </div>
                                        </Box>
                                    ))}
                                    {remainingCount > 0 && (
                                        <Anchor
                                            className={classes.moreLink}
                                            onClick={(e) => {
                                                e.preventDefault();
                                                handleShowAllShifts(item.key, dayCards);
                                            }}
                                        >
                                            +{remainingCount} {t('more')}
                                        </Anchor>
                                    )}
                                </div>
                            );
                        })}
                    </div>
                );
            })}

            <Modal
                opened={!!modalShifts}
                onClose={handleCloseModal}
                title={t('All shifts on {{date}}', {
                    date: modalDate ? DateHelper.parse(modalDate)?.format(t('FORMAT_DATE')) : '',
                })}
                size="lg"
            >
                <Stack gap="md">
                    {modalShifts?.map((card) => (
                        <Box
                            key={card.id}
                            className={`${classes.modalCard} ${card.hasCheckIn ? classes.checkedIn : ''} ${
                                card.isAbsent ? classes.absent : ''
                            }`}
                            onClick={() => {
                                handleCloseModal();
                                handleCardClick(card);
                            }}
                            style={{ cursor: 'pointer' }}
                        >
                            <div className={classes.cardHeader}>{card.workplaceName}</div>
                            <div>
                                {card.startTime} ~ {card.endTime}
                                <br />
                                {t('Position: {{position}}', { position: card.position })}
                            </div>
                            {card.hasCheckIn && <div>{t('Work hour: {{hour}}h', { hour: card.workHours })}</div>}
                        </Box>
                    ))}
                </Stack>
            </Modal>

            <ShiftInfoModal card={selectedCard} onClose={handleCloseShiftInfoModal} />
        </div>
    );
};

export default CalendarMonthView;
