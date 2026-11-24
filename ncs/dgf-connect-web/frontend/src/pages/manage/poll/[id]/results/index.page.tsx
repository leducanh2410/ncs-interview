import React from 'react';
import { useTranslation } from 'react-i18next';
import Link from 'next/link';
import { useRouter } from 'next/router';
import {
    Anchor,
    Badge,
    Breadcrumbs,
    Card,
    Flex,
    Paper,
    Progress,
    Select,
    SimpleGrid,
    Skeleton,
    Text,
    Title,
} from '@mantine/core';
import i18n from '@/locales/i18n';
import { FeedbackResultCounters, Poll, PollChoice, VoteResultCounters } from '@/types/models/poll';
import { PollResponse } from '@/types/models/poll_response';
import RouteConfig from '@/configs/route.config';
import { PollTypeEnum } from '@/enums/poll_type.enum';
import DateHelper, { DATE_STORE_FORMAT_WITH_TIME } from '@/helpers/date.helper';
import EnumHelper from '@/helpers/enum.helper';
import DAvatar from '@/components/DAvatar';
import DDataTable from '@/components/DDataTable';
import { usePermissions } from '@/hooks/usePermission';
import { usePollQuery, usePollResponsesQuery } from '@/pages/manage/poll/api';

const getLabel = (choice: PollChoice) => {
    const lang = i18n.language;
    return lang === 'vi' ? choice.label_vi : lang === 'ja' ? choice.label_ja : choice.label_en;
};

const getTitle = (poll: Poll) => {
    const lang = i18n.language;
    return lang === 'vi' ? poll.title_vi : lang === 'ja' ? poll.title_ja : poll.title_en;
};

const VoteResults = ({ poll }: { poll: Poll }) => {
    const { t } = useTranslation();
    const counters = poll.result_counters as VoteResultCounters;
    const candidates = poll.candidate_users || [];

    return (
        <SimpleGrid cols={{ base: 1, sm: 2, md: 3 }} mb="md">
            {(poll.options as number[]).map((userId) => {
                const user = candidates.find((u) => u.id === userId);
                const counter = counters[userId.toString()] || { yes: 0, no: 0, blank: 0 };
                const total = counter.yes + counter.no + counter.blank;
                return (
                    <Card key={userId} withBorder padding="sm">
                        <Flex align="center" gap="xs" mb="xs">
                            <DAvatar name={user?.name || ''} src={user?.profile_picture} size="sm" />
                            <Text fw={600}>{user?.name || `User #${userId}`}</Text>
                        </Flex>
                        <Flex direction="column" gap={4}>
                            <Flex justify="space-between">
                                <Text size="sm" c="teal">
                                    {t('Yes')}: {counter.yes}
                                </Text>
                                <Text size="sm" c="red">
                                    {t('No')}: {counter.no}
                                </Text>
                                <Text size="sm" c="gray">
                                    {t('Blank')}: {counter.blank}
                                </Text>
                            </Flex>
                            {total > 0 && (
                                <Progress.Root size="lg">
                                    <Progress.Section value={(counter.yes / total) * 100} color="teal" />
                                    <Progress.Section value={(counter.no / total) * 100} color="red" />
                                    <Progress.Section value={(counter.blank / total) * 100} color="gray" />
                                </Progress.Root>
                            )}
                        </Flex>
                    </Card>
                );
            })}
        </SimpleGrid>
    );
};

const FeedbackResults = ({ poll }: { poll: Poll }) => {
    const counters = poll.result_counters as FeedbackResultCounters;
    const choices = poll.options as PollChoice[];
    const maxCount = Math.max(...Object.values(counters), 1);

    return (
        <SimpleGrid cols={1} mb="md">
            {choices.map((choice) => {
                const count = counters[choice.id] || 0;
                return (
                    <Card key={choice.id} withBorder padding="sm">
                        <Flex justify="space-between" mb={4}>
                            <Text size="sm" fw={500}>
                                {getLabel(choice)}
                            </Text>
                            <Badge variant="light">{count}</Badge>
                        </Flex>
                        <Progress value={(count / maxCount) * 100} color="blue" size="md" />
                    </Card>
                );
            })}
        </SimpleGrid>
    );
};

const VoteResponseRow = ({ response, poll }: { response: PollResponse; poll: Poll }) => {
    const { t } = useTranslation();
    const answer = response.answer as Record<string, 1 | -1 | 0>;
    const candidates = poll.candidate_users || [];

    return (
        <Flex gap="xs" wrap="wrap">
            {Object.entries(answer).map(([userId, value]) => {
                const user = candidates.find((u) => u.id === Number(userId));
                const color = value === 1 ? 'teal' : value === -1 ? 'red' : 'gray';
                const label = value === 1 ? t('Yes') : value === -1 ? t('No') : t('Blank');
                return (
                    <Badge
                        key={userId}
                        color={color}
                        variant="light"
                        size="sm"
                        leftSection={<DAvatar name={user?.name || ''} src={user?.profile_picture} size={18} />}
                    >
                        {user?.name || userId}: {label}
                    </Badge>
                );
            })}
        </Flex>
    );
};

const FeedbackResponseRow = ({ response, poll }: { response: PollResponse; poll: Poll }) => {
    const answer = response.answer as string[];
    const choices = poll.options as PollChoice[];

    return (
        <Flex gap="xs" wrap="wrap">
            {answer.map((choiceId) => {
                const choice = choices.find((c) => c.id === choiceId);
                return (
                    <Badge key={choiceId} variant="light" size="sm">
                        {choice ? getLabel(choice) : choiceId}
                    </Badge>
                );
            })}
        </Flex>
    );
};

const ResultsPage = () => {
    const { t } = useTranslation();
    const router = useRouter();
    const id = router.query.id as string;
    const perms = usePermissions(['POLL_LIST']);
    const occurrence = typeof router.query.occurrence === 'string' ? router.query.occurrence : null;
    const { data: pollData, isPending: isPollPending } = usePollQuery(id || '', occurrence);
    const { data: responsesData, isPending: isResponsesPending } = usePollResponsesQuery(
        id || '',
        router.query,
        perms.POLL_LIST,
    );

    const poll = pollData?.data;
    const occurrences = poll?.available_occurrences ?? [];
    const showOccurrence = !!poll && (poll.is_recurring || occurrences.some((value) => value > 1));

    const onOccurrenceChange = (value: string | null) => {
        const { occurrence: _previous, page: _page, ...rest } = router.query;
        router.push({ pathname: router.pathname, query: value ? { ...rest, occurrence: value } : rest }, undefined, {
            shallow: true,
        });
    };

    return (
        <>
            <Breadcrumbs>
                <Anchor component={Link} href={RouteConfig.HOME.path}>
                    {t('Home')}
                </Anchor>
                <Anchor component={Link} href={RouteConfig.MANAGE_POLL_LIST.path}>
                    {t('Vote/Feedback')}
                </Anchor>
                <Text>{t('Results')}</Text>
            </Breadcrumbs>

            {isPollPending && (
                <Flex direction="column" gap="md" mt="md">
                    <Skeleton h={30} w={300} />
                    <Skeleton h={200} />
                </Flex>
            )}

            {poll && (
                <>
                    <Flex justify="space-between" align="center" my="md">
                        <Flex align="center" gap="sm">
                            <Title order={4}>{getTitle(poll)}</Title>
                            <Badge color={poll.type === 'vote' ? 'blue' : 'grape'} variant="light">
                                {EnumHelper.getText(PollTypeEnum, poll.type)}
                            </Badge>
                            <Badge color={poll.is_active ? 'teal' : 'gray'} variant="light">
                                {poll.is_active ? t('Active') : t('Inactive')}
                            </Badge>
                        </Flex>
                        <Text size="sm" c="dimmed">
                            {t('Total responses')}: {responsesData?.data?.total ?? 0}
                        </Text>
                    </Flex>

                    {showOccurrence && (
                        <Select
                            label={t('Occurrence')}
                            placeholder={t('All')}
                            w={220}
                            mb="md"
                            data={occurrences.map((value) => ({
                                value: String(value),
                                label: t('Round {{number}}', { number: value }),
                            }))}
                            value={occurrence}
                            onChange={onOccurrenceChange}
                            clearable
                        />
                    )}

                    <Title order={5} mb="sm">
                        {t('Aggregated results')}
                    </Title>
                    {poll.type === 'vote' ? <VoteResults poll={poll} /> : <FeedbackResults poll={poll} />}

                    <Title order={5} mb="sm">
                        {t('Individual responses')}
                    </Title>
                    <Paper p="md">
                        <DDataTable<PollResponse>
                            columns={[
                                { name: 'ID', key: 'id', align: 'center', width: 60 },
                                {
                                    name: t('User'),
                                    key: 'user',
                                    width: 180,
                                    render: (data) => data.user?.name || `User #${data.user_id}`,
                                },
                                ...(showOccurrence
                                    ? [
                                          {
                                              name: t('Occurrence'),
                                              key: 'occurrence',
                                              align: 'center' as const,
                                              width: 90,
                                          },
                                          {
                                              name: t('Workplace'),
                                              key: 'workplace',
                                              width: 160,
                                              render: (data: PollResponse) => data.workplace?.name || '-',
                                          },
                                      ]
                                    : []),
                                {
                                    name: t('Answer'),
                                    key: 'answer',
                                    render: (data) =>
                                        poll.type === 'vote' ? (
                                            <VoteResponseRow response={data} poll={poll} />
                                        ) : (
                                            <FeedbackResponseRow response={data} poll={poll} />
                                        ),
                                },
                                {
                                    name: t('IP Address'),
                                    key: 'ip_address',
                                    width: 140,
                                },
                                {
                                    name: t('Submitted at'),
                                    key: 'created_at',
                                    width: 180,
                                    render: (data) => {
                                        const parsed = DateHelper.parse(data.created_at, null);
                                        return parsed ? DateHelper.format(parsed, DATE_STORE_FORMAT_WITH_TIME) : '-';
                                    },
                                },
                            ]}
                            data={responsesData?.data || []}
                            isFetching={isResponsesPending}
                        />
                    </Paper>
                </>
            )}
        </>
    );
};

export default ResultsPage;
