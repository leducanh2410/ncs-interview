import React, { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { LuFileText, LuPause, LuPencilLine, LuPlay, LuPlus, LuTrash2 } from 'react-icons/lu';
import Link from 'next/link';
import { useRouter } from 'next/router';
import {
    ActionIcon,
    Anchor,
    Badge,
    Breadcrumbs,
    Button,
    Flex,
    Modal,
    Paper,
    Text,
    Title,
    Tooltip,
} from '@mantine/core';
import { useDisclosure } from '@mantine/hooks';
import i18n from '@/locales/i18n';
import { Poll } from '@/types/models/poll';
import RouteConfig from '@/configs/route.config';
import { PollTargetTypeEnum } from '@/enums/poll_target_type.enum';
import { PollTypeEnum } from '@/enums/poll_type.enum';
import DateHelper from '@/helpers/date.helper';
import EnumHelper from '@/helpers/enum.helper';
import NotificationHelper from '@/helpers/notification.helper';
import RouteHelper from '@/helpers/route.helper';
import CommonSearchBox from '@/components/CommonSearchBox';
import DConfirmModal from '@/components/DConfirmModal';
import DDataTable from '@/components/DDataTable';
import PollForm from '@/pages/manage/poll/partials/poll_form';
import { usePermissions } from '@/hooks/usePermission';
import {
    useActivatePollMutation,
    useDeactivatePollMutation,
    useDeletePollMutation,
    usePollQuery,
    usePollsQuery,
} from '@/pages/manage/poll/api';

const IndexPage = () => {
    const { t } = useTranslation();
    const router = useRouter();
    const perms = usePermissions(['POLL_LIST', 'POLL_CREATE', 'POLL_EDIT', 'POLL_DELETE']);
    const { data, isPending } = usePollsQuery(router.query, perms.POLL_LIST);
    const { mutateAsync: deletePoll } = useDeletePollMutation();
    const { mutateAsync: activatePoll } = useActivatePollMutation();
    const { mutateAsync: deactivatePoll } = useDeactivatePollMutation();
    const [deleteId, setDeleteId] = useState<string | null>(null);
    const [isShowCreate, { open: openCreate, close: closeCreate }] = useDisclosure(false);
    const [editId, setEditId] = useState<string | null>(null);
    const { data: editPollData } = usePollQuery(editId || '');

    return (
        <>
            <Breadcrumbs>
                <Anchor component={Link} href={RouteConfig.HOME.path}>
                    {t('Home')}
                </Anchor>
                <Text>{t('Vote/Feedback')}</Text>
            </Breadcrumbs>
            <Flex justify="space-between" align="center">
                <Title order={4} lh="48px" my="md">
                    {t('Vote/Feedback')}
                </Title>
                <Button leftSection={<LuPlus />} onClick={openCreate} disabled={!perms.POLL_CREATE}>
                    {t('Create poll')}
                </Button>
            </Flex>
            <CommonSearchBox
                initialValues={{ type: '' }}
                elements={[
                    {
                        id: 'type',
                        type: 'select',
                        label: t('Type'),
                        name: 'type',
                        options: EnumHelper.toSelectArray(PollTypeEnum),
                        isClearable: true,
                    },
                ]}
            />
            <Paper mt="md" p="md">
                <DDataTable<Poll>
                    columns={[
                        { name: 'ID', key: 'id', align: 'center', width: 60 },
                        {
                            name: t('Type'),
                            key: 'type',
                            width: 120,
                            render: (data) => {
                                return (
                                    <Badge color={data.type === 'vote' ? 'blue' : 'grape'} variant="light">
                                        {EnumHelper.getText(PollTypeEnum, data.type)}
                                    </Badge>
                                );
                            },
                        },
                        {
                            name: t('Target Audience'),
                            key: 'target_type',
                            width: 120,
                            render: (data) => (
                                <Text size="sm">
                                    {data.target_type === PollTargetTypeEnum.GLOBAL.value || !data.target_type
                                        ? t('Global')
                                        : data.target_type === PollTargetTypeEnum.SPECIFIC_USERS.value
                                          ? t('Specific Users')
                                          : data.workplace
                                            ? data.workplace.name
                                            : t('Workplace')}
                                </Text>
                            ),
                        },
                        {
                            name: t('Title'),
                            key: 'title_en',
                            width: 300,
                            render: (data) => {
                                const lang = i18n.language;
                                const title =
                                    lang === 'vi' ? data.title_vi : lang === 'ja' ? data.title_ja : data.title_en;
                                return (
                                    <Text truncate="end" maw={280}>
                                        <Anchor
                                            component={Link}
                                            href={RouteHelper.getPath('MANAGE_POLL_RESULTS', { id: data.id })}
                                            size="sm"
                                        >
                                            {title || data.title_vi || data.title_ja}
                                        </Anchor>
                                    </Text>
                                );
                            },
                        },
                        {
                            name: t('Required'),
                            key: 'is_required',
                            width: 90,
                            align: 'center',
                            render: (data) => (
                                <Badge color={data.is_required ? 'red' : 'gray'} variant="light" size="sm">
                                    {data.is_required ? t('Yes') : t('No')}
                                </Badge>
                            ),
                        },
                        {
                            name: t('Expires'),
                            key: 'expires_at',
                            width: 120,
                            render: (data) => DateHelper.parseAndFormat(data.expires_at, null, t('FORMAT_DATE')),
                        },
                        {
                            name: t('Responses'),
                            key: 'responses_count',
                            width: 100,
                            align: 'center',
                            render: (data) => data.responses_count ?? 0,
                        },
                        {
                            name: t('Active'),
                            key: 'is_active',
                            width: 100,
                            align: 'center',
                            render: (data) => (
                                <Badge color={data.is_active ? 'teal' : 'gray'} variant="light" size="sm">
                                    {data.is_active ? t('Yes') : t('No')}
                                </Badge>
                            ),
                        },
                        {
                            name: t('Action'),
                            key: 'action',
                            width: 160,
                            render: (data) => (
                                <Flex gap={4}>
                                    <Tooltip label={t('View results')}>
                                        <ActionIcon
                                            variant="subtle"
                                            color="blue"
                                            component={Link}
                                            aria-label={t('View results')}
                                            data-testid="view-results-btn"
                                            href={RouteHelper.getPath('MANAGE_POLL_RESULTS', { id: data.id })}
                                        >
                                            <LuFileText size={16} />
                                        </ActionIcon>
                                    </Tooltip>
                                    {perms.POLL_EDIT && (
                                        <>
                                            <Tooltip label={data.is_active ? t('Deactivate') : t('Activate')}>
                                                <ActionIcon
                                                    variant="subtle"
                                                    color={data.is_active ? 'orange' : 'teal'}
                                                    aria-label={data.is_active ? t('Deactivate') : t('Activate')}
                                                    data-testid={data.is_active ? 'deactivate-btn' : 'activate-btn'}
                                                    onClick={async () => {
                                                        try {
                                                            if (data.is_active) {
                                                                await deactivatePoll(data.id.toString());
                                                                NotificationHelper.showSuccess(t('Poll deactivated'));
                                                            } else {
                                                                await activatePoll(data.id.toString());
                                                                NotificationHelper.showSuccess(t('Poll activated'));
                                                            }
                                                        } catch {
                                                            NotificationHelper.showError(t('Action failed'));
                                                        }
                                                    }}
                                                >
                                                    {data.is_active ? <LuPause size={16} /> : <LuPlay size={16} />}
                                                </ActionIcon>
                                            </Tooltip>
                                            <Tooltip label={t('Edit')}>
                                                <ActionIcon
                                                    variant="subtle"
                                                    color="gray"
                                                    aria-label={t('Edit')}
                                                    data-testid="edit-btn"
                                                    onClick={() => setEditId(data.id.toString())}
                                                >
                                                    <LuPencilLine size={16} />
                                                </ActionIcon>
                                            </Tooltip>
                                        </>
                                    )}
                                    {perms.POLL_DELETE && (
                                        <Tooltip label={t('Delete')}>
                                            <ActionIcon
                                                variant="subtle"
                                                color="red"
                                                aria-label={t('Delete')}
                                                data-testid="delete-btn"
                                                onClick={() => setDeleteId(data.id.toString())}
                                                disabled={(data.responses_count ?? 0) > 0}
                                            >
                                                <LuTrash2 size={16} />
                                            </ActionIcon>
                                        </Tooltip>
                                    )}
                                </Flex>
                            ),
                        },
                    ]}
                    data={data?.data || []}
                    isFetching={isPending}
                />
            </Paper>

            <Modal size="xl" opened={isShowCreate} onClose={closeCreate} title={t('Create poll')}>
                <PollForm poll={null} onClose={closeCreate} />
            </Modal>

            <Modal size="xl" opened={editId !== null} onClose={() => setEditId(null)} title={t('Edit poll')}>
                {editPollData?.data && <PollForm poll={editPollData.data} onClose={() => setEditId(null)} />}
            </Modal>

            <DConfirmModal
                opened={deleteId !== null}
                title={t('Delete poll')}
                message={t('Are you sure you want to delete this poll? This action cannot be undone.')}
                onConfirm={async () => {
                    if (!deleteId) return;
                    try {
                        await deletePoll(deleteId);
                        NotificationHelper.showSuccess(t('Successfully deleted poll'));
                    } catch {
                        NotificationHelper.showError(
                            t('Could not delete poll. Polls with responses cannot be deleted.'),
                        );
                    } finally {
                        setDeleteId(null);
                    }
                }}
                onCancel={() => setDeleteId(null)}
                confirmButtonLabel={t('Delete poll')}
                confirmButtonColor="red"
            />
        </>
    );
};

export default IndexPage;
