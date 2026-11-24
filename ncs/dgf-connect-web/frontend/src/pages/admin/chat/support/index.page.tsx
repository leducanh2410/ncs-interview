import React, { useEffect } from 'react';
import { useTranslation } from 'react-i18next';
import { LuArrowRight } from 'react-icons/lu';
import Link from 'next/link';
import { useRouter } from 'next/router';
import { Anchor, Badge, Breadcrumbs, Button, Flex, Group, Paper, Text, Title } from '@mantine/core';
import { ChatSupportConversationListItem, SupportType } from '@/types/models/chat';
import RouteConfig from '@/configs/route.config';
import RouteHelper from '@/helpers/route.helper';
import CommonSearchBox from '@/components/CommonSearchBox';
import DAvatar from '@/components/DAvatar';
import DDataTable from '@/components/DDataTable';
import SupportTypeLabel from '@/components/SupportTypeLabel';
import { formatMessageTime, messagePreview } from '@/components/chat/helpers';
import { usePermissions } from '@/hooks/usePermission';
import { useSupportConversationsQuery } from '@/pages/admin/chat/support/api';

const SUPPORT_TYPE_LABELS: Record<SupportType, string> = {
    document: 'support_type|document',
    shift: 'support_type|shift',
    salary: 'support_type|salary',
    transportation: 'support_type|transportation',
    other: 'support_type|other',
};

const SUPPORT_TYPE_OPTIONS = (Object.entries(SUPPORT_TYPE_LABELS) as [SupportType, string][]).map(([value, label]) => ({
    value,
    label,
}));

const IndexPage = () => {
    const { t } = useTranslation();
    const router = useRouter();
    const perms = usePermissions(['CHAT_ASSIGN']);
    const { data, isPending } = useSupportConversationsQuery(router.query, perms.CHAT_ASSIGN);

    useEffect(() => {
        if (!router.isReady) return;
        if (router.query.status && router.query.conversation_status) return;
        router.replace({
            query: {
                ...router.query,
                status: router.query.status ?? 'all',
                conversation_status: router.query.conversation_status ?? 'open',
            },
        });
    }, [router.isReady, router.query.status, router.query.conversation_status]);

    const requesterTitle = (conversation: ChatSupportConversationListItem) => {
        if (conversation.title) {
            return conversation.title;
        }
        const name = conversation.created_by_name ?? conversation.guest_name;
        if (name) {
            return t('Support from {{name}}', { name });
        }
        return t('Support request');
    };

    return (
        <>
            <Breadcrumbs>
                <Anchor component={Link} href={RouteConfig.HOME.path}>
                    {t('Home')}
                </Anchor>
                <Text>{t('Support chats')}</Text>
            </Breadcrumbs>
            <Flex justify="space-between" align="center">
                <Title order={4} lh="48px" my="md">
                    {t('Support chats')}
                </Title>
            </Flex>
            <CommonSearchBox
                initialValues={{ status: 'all', conversation_status: 'open', search: '', support_type: '' }}
                elements={[
                    {
                        id: 'search',
                        type: 'input-text',
                        label: t('Search by name or employee code'),
                        name: 'search',
                    },
                    {
                        id: 'status',
                        type: 'select',
                        label: t('Assignment status'),
                        name: 'status',
                        options: [
                            { value: 'unassigned', label: t('Unassigned') },
                            { value: 'assigned', label: t('Assigned') },
                            { value: 'all', label: t('All') },
                        ],
                        isClearable: true,
                    },
                    {
                        id: 'conversation_status',
                        type: 'select',
                        label: t('Status'),
                        name: 'conversation_status',
                        options: [
                            { value: 'open', label: t('Open') },
                            { value: 'closed', label: t('Closed') },
                            { value: 'all', label: t('All') },
                        ],
                        isClearable: true,
                    },
                    {
                        id: 'support_type',
                        type: 'select',
                        label: t('Support type'),
                        name: 'support_type',
                        options: SUPPORT_TYPE_OPTIONS.map((o) => ({ value: o.value, label: t(o.label) })),
                        isClearable: true,
                    },
                ]}
            />
            <Paper mt="md" p="md">
                <DDataTable<ChatSupportConversationListItem>
                    columns={[
                        { name: 'ID', key: 'id', align: 'center', width: 60 },
                        {
                            name: t('Requester'),
                            key: 'created_by_name',
                            width: 240,
                            render: (conversation) => (
                                <Group gap={4} wrap="nowrap">
                                    <Anchor
                                        component={Link}
                                        href={RouteHelper.getPath('ADMIN_CHAT_SUPPORT_DETAIL', {
                                            id: conversation.id,
                                        })}
                                        size="sm"
                                    >
                                        {requesterTitle(conversation)}
                                    </Anchor>
                                    {conversation.is_guest && (
                                        <Badge color="teal" variant="filled" size="xs">
                                            {t('Guest')}
                                        </Badge>
                                    )}
                                </Group>
                            ),
                        },
                        {
                            name: t('Support type'),
                            key: 'support_type',
                            width: 140,
                            render: (conversation) => <SupportTypeLabel type={conversation.support_type} />,
                        },
                        {
                            name: t('Last message'),
                            key: 'last_message',
                            width: 320,
                            render: (conversation) =>
                                conversation.last_message ? (
                                    <Group gap={6} wrap="nowrap" maw={300}>
                                        {conversation.last_message.sender_name && (
                                            <DAvatar
                                                size={18}
                                                radius="xl"
                                                name={conversation.last_message.sender_name}
                                                src={conversation.last_message.sender_profile_picture ?? undefined}
                                                style={{ flexShrink: 0 }}
                                            />
                                        )}
                                        <Text size="sm" truncate>
                                            {messagePreview(conversation.last_message, t)}
                                        </Text>
                                    </Group>
                                ) : (
                                    <Text size="sm" c="dimmed">
                                        {t('No messages yet')}
                                    </Text>
                                ),
                        },
                        {
                            name: t('Status'),
                            key: 'status',
                            width: 110,
                            align: 'center',
                            render: (conversation) => (
                                <Badge
                                    color={conversation.status === 'closed' ? 'gray' : 'teal'}
                                    variant="light"
                                    size="sm"
                                >
                                    {conversation.status === 'closed' ? t('Closed') : t('Open')}
                                </Badge>
                            ),
                        },
                        {
                            name: t('Participants'),
                            key: 'participant_count',
                            width: 110,
                            align: 'center',
                            render: (conversation) => conversation.participant_count,
                        },
                        {
                            name: t('Last activity'),
                            key: 'last_message_at',
                            width: 150,
                            render: (conversation) => (
                                <Text size="sm" c={conversation.last_message_at ? undefined : 'dimmed'}>
                                    {conversation.last_message_at
                                        ? formatMessageTime(conversation.last_message_at)
                                        : '-'}
                                </Text>
                            ),
                        },
                        {
                            name: t('Action'),
                            key: 'action',
                            width: 100,
                            align: 'center',
                            render: (conversation) => (
                                <Button
                                    size="xs"
                                    variant="light"
                                    component={Link}
                                    href={RouteHelper.getPath('ADMIN_CHAT_SUPPORT_DETAIL', { id: conversation.id })}
                                    rightSection={<LuArrowRight size={14} />}
                                >
                                    {t('View')}
                                </Button>
                            ),
                        },
                    ]}
                    data={data?.data || []}
                    isFetching={isPending}
                    noPermission={!perms.CHAT_ASSIGN}
                />
            </Paper>
        </>
    );
};

export default IndexPage;
