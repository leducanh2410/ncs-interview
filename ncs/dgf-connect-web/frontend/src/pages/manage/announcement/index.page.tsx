import React, { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { LuImage, LuPencilLine, LuPlus, LuTrash2 } from 'react-icons/lu';
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
    Image,
    Paper,
    Stack,
    Text,
    Title,
    Tooltip,
} from '@mantine/core';
import { useDisclosure } from '@mantine/hooks';
import { useAtom } from 'jotai';
import { AnnouncementWithRelation } from '@/types/models/announcement';
import RouteConfig from '@/configs/route.config';
import { AnnouncementStateEnum, getAnnouncementStateColor } from '@/enums/announcement_state.enum';
import { BASE_LOCALE, LocaleEnum, getLocaleFlag } from '@/enums/locale.enum';
import DateHelper, { DATE_STORE_FORMAT_WITH_TIME } from '@/helpers/date.helper';
import EnumHelper from '@/helpers/enum.helper';
import NotificationHelper from '@/helpers/notification.helper';
import CommonSearchBox from '@/components/CommonSearchBox';
import DConfirmModal from '@/components/DConfirmModal';
import DDataTable from '@/components/DDataTable';
import { masterAtom } from '@/pages/global.state';
import AnnouncementCreateUpdate from '@/pages/manage/announcement/partials/announcement_create_update';
import { usePermissions } from '@/hooks/usePermission';
import { useDeleteAnnouncementMutation, useGetAnnouncementsQuery } from '@/pages/manage/announcement/api';

const IndexPage = () => {
    const { t } = useTranslation();
    const perms = usePermissions([
        'ANNOUNCEMENT_LIST',
        'ANNOUNCEMENT_CREATE',
        'ANNOUNCEMENT_EDIT',
        'ANNOUNCEMENT_DELETE',
    ]);
    const router = useRouter();
    const [masterData] = useAtom(masterAtom);
    const [createUpdateModalOpened, createUpdateModalAction] = useDisclosure(false);
    const [currentAnnouncement, setCurrentAnnouncement] = useState<AnnouncementWithRelation | null>(null);
    const { data, isLoading } = useGetAnnouncementsQuery(router.query, perms.ANNOUNCEMENT_LIST);
    const { mutateAsync: deleteAnnouncement } = useDeleteAnnouncementMutation();
    const [deleteId, setDeleteId] = useState<string | null>(null);

    const formatDateTime = (value: string) =>
        DateHelper.parseAndFormat(value, DATE_STORE_FORMAT_WITH_TIME, t('FORMAT_DATE_WITH_TIME'));

    return (
        <>
            <Breadcrumbs>
                <Anchor component={Link} href={RouteConfig.HOME.path}>
                    {t('Home')}
                </Anchor>
                <Text>{t('Announcement management')}</Text>
            </Breadcrumbs>
            <Flex justify="space-between" align="center">
                <Title order={4} lh="48px" my="md">
                    {t('Announcement management')}
                </Title>
                <Button
                    leftSection={<LuPlus />}
                    onClick={() => {
                        setCurrentAnnouncement(null);
                        createUpdateModalAction.open();
                    }}
                    disabled={!perms.ANNOUNCEMENT_CREATE}
                >
                    {t('Create announcement')}
                </Button>
            </Flex>
            <CommonSearchBox
                initialValues={{
                    keyword: '',
                    workplace_id: 'all',
                    state: 'all',
                }}
                elements={[
                    {
                        id: 'keyword',
                        type: 'input-text',
                        label: t('Title'),
                        name: 'keyword',
                    },
                    {
                        id: 'workplace_id',
                        type: 'select',
                        label: t('Workplace'),
                        name: 'workplace_id',
                        options: masterData.workplaces.map((workplace) => ({
                            label: workplace.name,
                            value: workplace.id.toString(),
                        })),
                        isClearable: false,
                        isSearchable: true,
                        showAll: true,
                        allValue: 'all',
                    },
                    {
                        id: 'state',
                        type: 'select',
                        label: t('Status'),
                        name: 'state',
                        options: EnumHelper.toSelectArray(AnnouncementStateEnum),
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
                            name: t('Image'),
                            key: 'image',
                            width: '72px',
                            render: (data) => {
                                if (!data.image_url) {
                                    return <LuImage size={20} color="var(--mantine-color-dimmed)" />;
                                }
                                return <Image src={data.image_url} alt={data.title} w={48} h={48} radius="sm" />;
                            },
                        },
                        {
                            name: t('Title'),
                            key: 'title',
                            render: (data) => (
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
                                    {data.translations.map((translation) => (
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
                            ),
                        },
                        {
                            name: t('Workplace'),
                            key: 'workplaces',
                            width: '180px',
                            render: (data) => {
                                const names = data.workplaces.map((workplace) => workplace.name).join(', ');
                                return (
                                    <Tooltip label={names} multiline w={300} disabled={names.length <= 40}>
                                        <Text size="sm" lineClamp={2} miw={120}>
                                            {names || '-'}
                                        </Text>
                                    </Tooltip>
                                );
                            },
                        },
                        {
                            name: t('Display period'),
                            key: 'period',
                            render: (data) => (
                                <Text size="sm" style={{ whiteSpace: 'nowrap' }}>
                                    {formatDateTime(data.start_at)}
                                    <br />~ {formatDateTime(data.end_at)}
                                </Text>
                            ),
                        },
                        {
                            name: t('Status'),
                            key: 'state',
                            width: '170px',
                            render: (data) => (
                                <Badge
                                    color={getAnnouncementStateColor(data.state)}
                                    styles={{ root: { maxWidth: 'none' }, label: { overflow: 'visible' } }}
                                >
                                    {EnumHelper.getText(AnnouncementStateEnum, data.state)}
                                </Badge>
                            ),
                        },
                        {
                            name: t('Action'),
                            key: 'action',
                            width: '80px',
                            render: (data) => (
                                <Flex>
                                    <ActionIcon
                                        variant="subtle"
                                        color="blue"
                                        onClick={() => {
                                            setCurrentAnnouncement(data);
                                            createUpdateModalAction.open();
                                        }}
                                        disabled={!perms.ANNOUNCEMENT_EDIT}
                                    >
                                        <LuPencilLine size={16} />
                                    </ActionIcon>
                                    <ActionIcon
                                        variant="subtle"
                                        color="red"
                                        onClick={() => setDeleteId(data.id.toString())}
                                        disabled={!perms.ANNOUNCEMENT_DELETE}
                                    >
                                        <LuTrash2 size={16} />
                                    </ActionIcon>
                                </Flex>
                            ),
                        },
                    ]}
                    data={data?.data || []}
                    isFetching={isLoading}
                    noPermission={!perms.ANNOUNCEMENT_LIST}
                />
            </Paper>
            <AnnouncementCreateUpdate
                announcement={currentAnnouncement}
                opened={createUpdateModalOpened}
                onClose={createUpdateModalAction.close}
            />
            <DConfirmModal
                opened={deleteId !== null}
                title={t('Delete announcement')}
                message={t('Are you sure you want to delete this announcement? This action cannot be undone.')}
                onConfirm={async () => {
                    if (!deleteId) return;
                    try {
                        await deleteAnnouncement(deleteId);
                        NotificationHelper.showSuccess(t('Successfully deleted announcement'));
                    } catch {
                        NotificationHelper.showError(t('error|Could not delete announcement'));
                    } finally {
                        setDeleteId(null);
                    }
                }}
                onCancel={() => setDeleteId(null)}
                confirmButtonLabel={t('Delete announcement')}
                confirmButtonColor="red"
            />
        </>
    );
};

export default IndexPage;
