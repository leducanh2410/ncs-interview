import React, { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { LuArrowDown, LuArrowUp, LuArrowUpDown, LuCalendar, LuChevronDown, LuChevronRight } from 'react-icons/lu';
import {
    Badge,
    Group,
    Loader,
    Paper,
    Progress,
    Select,
    SimpleGrid,
    Stack,
    Table,
    Text,
    Tooltip,
    UnstyledButton,
} from '@mantine/core';
import { DatePickerInput, DatesRangeValue } from '@mantine/dates';
import dayjs from 'dayjs';
import { useAtom } from 'jotai';
import { ExamQuestionStatistics } from '@/types/models/exam';
import { DATE_STORE_FORMAT } from '@/helpers/date.helper';
import TranslatableHelper from '@/helpers/translatable.helper';
import { masterAtom } from '@/pages/global.state';
import {
    CorrectRateSort,
    formatRate,
    formatScore,
    getAnswerBreakdown,
    nextCorrectRateSort,
    sortQuestionStatistics,
} from './exam_statistics.helper';
import { useGetExamStatisticsQuery } from '@/pages/manage/exam/api';

type Props = {
    examId: string;
    isRecurring?: boolean;
};

const TABLE_COLUMN_COUNT = 7;

const toDateParam = (value: string | Date | null): string | null =>
    value ? dayjs(value).format(DATE_STORE_FORMAT) : null;

function SummaryCard({ label, value }: { label: string; value: React.ReactNode }) {
    return (
        <Paper withBorder p="md" radius="md">
            <Text size="xs" c="dimmed">
                {label}
            </Text>
            <Text size="xl" fw={700}>
                {value}
            </Text>
        </Paper>
    );
}

function AnswerBreakdownBar({ question }: { question: ExamQuestionStatistics }) {
    const { t } = useTranslation();
    const breakdown = getAnswerBreakdown(question);

    return (
        <Progress.Root size="lg" miw={140}>
            <Tooltip label={`${t('Correct')}: ${question.correct_count}`}>
                <Progress.Section value={breakdown.correct} color="green" />
            </Tooltip>
            <Tooltip label={`${t('Incorrect')}: ${question.wrong_count}`}>
                <Progress.Section value={breakdown.wrong} color="red" />
            </Tooltip>
            <Tooltip label={`${t('Not answered')}: ${question.unanswered_count}`}>
                <Progress.Section value={breakdown.unanswered} color="gray.4" />
            </Tooltip>
        </Progress.Root>
    );
}

export default function ExamStatistics({ examId, isRecurring = false }: Props) {
    const { t, i18n } = useTranslation();
    const [masterData] = useAtom(masterAtom);
    const [workplaceId, setWorkplaceId] = useState<string | null>(null);
    const [occurrence, setOccurrence] = useState<string | null>(null);
    const [dateRange, setDateRange] = useState<DatesRangeValue<string>>([null, null]);
    const [sort, setSort] = useState<CorrectRateSort>(null);
    const [expandedIds, setExpandedIds] = useState<number[]>([]);

    const params = useMemo(
        () => ({
            workplace_id: workplaceId,
            occurrence,
            from: toDateParam(dateRange[0]),
            to: toDateParam(dateRange[1]),
        }),
        [workplaceId, occurrence, dateRange],
    );
    const { data, isPending } = useGetExamStatisticsQuery(examId, params);
    const statistics = data?.data;
    const occurrences = statistics?.available_occurrences ?? [];
    const showOccurrence = isRecurring || occurrences.some((value) => value > 1);

    const sortedQuestions = useMemo(
        () => sortQuestionStatistics(statistics?.questions ?? [], sort),
        [statistics, sort],
    );

    const toggleExpanded = (id: number) => {
        setExpandedIds((current) => (current.includes(id) ? current.filter((item) => item !== id) : [...current, id]));
    };

    const SortIcon = sort === 'asc' ? LuArrowUp : sort === 'desc' ? LuArrowDown : LuArrowUpDown;

    return (
        <Stack>
            <Group align="flex-end">
                <Select
                    label={t('Workplace')}
                    placeholder={t('All workplaces')}
                    data={masterData.workplaces.map((workplace) => ({
                        value: workplace.id.toString(),
                        label: workplace.name,
                    }))}
                    value={workplaceId}
                    onChange={setWorkplaceId}
                    searchable
                    clearable
                    miw={240}
                />
                {showOccurrence && (
                    <Select
                        label={t('Occurrence')}
                        placeholder={t('All')}
                        data={occurrences.map((value) => ({
                            value: String(value),
                            label: t('Round {{number}}', { number: value }),
                        }))}
                        value={occurrence}
                        onChange={setOccurrence}
                        clearable
                        miw={160}
                    />
                )}
                <DatePickerInput
                    type="range"
                    label={t('Period')}
                    placeholder={t('All time')}
                    value={dateRange}
                    onChange={(value) => setDateRange(value as DatesRangeValue<string>)}
                    valueFormat={t('FORMAT_DATE')}
                    leftSection={<LuCalendar size={16} />}
                    allowSingleDateInRange
                    clearable
                    miw={260}
                />
            </Group>

            {isPending || !statistics ? (
                <Loader />
            ) : (
                <>
                    <SimpleGrid cols={{ base: 2, sm: 3, lg: 5 }}>
                        <SummaryCard label={t('Participants')} value={statistics.summary.participant_count} />
                        <SummaryCard label={t('Passed')} value={statistics.summary.passed_count} />
                        <SummaryCard label={t('Pass rate')} value={formatRate(statistics.summary.pass_rate)} />
                        <SummaryCard
                            label={t('Average score')}
                            value={`${formatScore(statistics.summary.average_score)} / ${statistics.summary.total_questions}`}
                        />
                        <SummaryCard label={t('Attempts')} value={statistics.summary.attempt_count} />
                    </SimpleGrid>

                    {sortedQuestions.length === 0 ? (
                        <Text c="dimmed">{t('No question')}</Text>
                    ) : (
                        <Paper withBorder radius="md">
                            <Table.ScrollContainer minWidth={900}>
                                <Table highlightOnHover verticalSpacing="sm">
                                    <Table.Thead>
                                        <Table.Tr>
                                            <Table.Th w={60}>{t('No.')}</Table.Th>
                                            <Table.Th>{t('Question')}</Table.Th>
                                            <Table.Th w={90}>{t('Correct')}</Table.Th>
                                            <Table.Th w={90}>{t('Incorrect')}</Table.Th>
                                            <Table.Th w={110}>{t('Not answered')}</Table.Th>
                                            <Table.Th w={130}>
                                                <UnstyledButton onClick={() => setSort(nextCorrectRateSort(sort))}>
                                                    <Group gap={4} wrap="nowrap">
                                                        <Text size="sm" fw={700}>
                                                            {t('Correct rate')}
                                                        </Text>
                                                        <SortIcon size={14} />
                                                    </Group>
                                                </UnstyledButton>
                                            </Table.Th>
                                            <Table.Th w={180}>{t('Answer breakdown')}</Table.Th>
                                        </Table.Tr>
                                    </Table.Thead>
                                    <Table.Tbody>
                                        {sortedQuestions.map((question) => {
                                            const isExpanded = expandedIds.includes(question.id);
                                            return (
                                                <React.Fragment key={question.id}>
                                                    <Table.Tr
                                                        style={{ cursor: 'pointer' }}
                                                        onClick={() => toggleExpanded(question.id)}
                                                    >
                                                        <Table.Td>
                                                            <Group gap={4} wrap="nowrap">
                                                                {isExpanded ? (
                                                                    <LuChevronDown size={14} />
                                                                ) : (
                                                                    <LuChevronRight size={14} />
                                                                )}
                                                                {question.no}
                                                            </Group>
                                                        </Table.Td>
                                                        <Table.Td>
                                                            {TranslatableHelper.resolve(
                                                                question.content,
                                                                i18n.language,
                                                            )}
                                                        </Table.Td>
                                                        <Table.Td>{question.correct_count}</Table.Td>
                                                        <Table.Td>{question.wrong_count}</Table.Td>
                                                        <Table.Td>{question.unanswered_count}</Table.Td>
                                                        <Table.Td>{formatRate(question.correct_rate)}</Table.Td>
                                                        <Table.Td>
                                                            <AnswerBreakdownBar question={question} />
                                                        </Table.Td>
                                                    </Table.Tr>
                                                    {isExpanded && (
                                                        <Table.Tr>
                                                            <Table.Td colSpan={TABLE_COLUMN_COUNT} bg="gray.0">
                                                                <Stack gap="xs" pl="lg">
                                                                    {question.options.map((option) => (
                                                                        <Group
                                                                            key={option.id}
                                                                            justify="space-between"
                                                                            wrap="nowrap"
                                                                        >
                                                                            <Group gap="xs" wrap="nowrap">
                                                                                <Text size="sm">
                                                                                    {TranslatableHelper.resolve(
                                                                                        option.content,
                                                                                        i18n.language,
                                                                                    )}
                                                                                </Text>
                                                                                {option.is_correct && (
                                                                                    <Badge color="green" size="sm">
                                                                                        {t('Correct answer')}
                                                                                    </Badge>
                                                                                )}
                                                                            </Group>
                                                                            <Text size="sm" c="dimmed">
                                                                                {t('Selected: {{value}}', {
                                                                                    value: option.selected_count,
                                                                                })}
                                                                            </Text>
                                                                        </Group>
                                                                    ))}
                                                                </Stack>
                                                            </Table.Td>
                                                        </Table.Tr>
                                                    )}
                                                </React.Fragment>
                                            );
                                        })}
                                    </Table.Tbody>
                                </Table>
                            </Table.ScrollContainer>
                        </Paper>
                    )}
                </>
            )}
        </Stack>
    );
}
