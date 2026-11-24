import React, { useCallback, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { LuCheck, LuCircleAlert, LuCopy, LuTriangleAlert, LuX } from 'react-icons/lu';
import Link from 'next/link';
import { useRouter } from 'next/router';
import {
    ActionIcon,
    Anchor,
    Badge,
    Box,
    Breadcrumbs,
    CopyButton,
    Flex,
    Paper,
    Stack,
    Tabs,
    Text,
    Title,
    Tooltip,
} from '@mantine/core';
import RouteConfig from '@/configs/route.config';
import { UserPaperStatusEnum, getColor } from '@/enums/user_paper_status.enum';
import DAlert from '@/components/DAlert';
import UserAction from '@/pages/admin/user/partials/user_action';
import UserConfigs from '@/pages/admin/user/partials/user_configs';
import UserDevices from '@/pages/admin/user/partials/user_devices';
import UserForm, { type UserFormHandle } from '@/pages/admin/user/partials/user_form';
import UserGroups from '@/pages/admin/user/partials/user_groups';
import UserImages from '@/pages/admin/user/partials/user_images';
import UserLockHistories from '@/pages/admin/user/partials/user_lock_histories';
import UserPapers from '@/pages/admin/user/partials/user_papers';
import UserRoles from '@/pages/admin/user/partials/user_roles';
import UserWorkplaces from '@/pages/admin/user/partials/user_workplaces';
import classes from './[id].module.css';
import { usePermissions } from '@/hooks/usePermission';
import { useGetUserQuery } from '@/pages/admin/user/api';

const DEFAULT_TAB = 'information';
const FORM_ID = 'user-detail-form';

const IndexPage = () => {
    const { t } = useTranslation();
    const router = useRouter();
    const id = router.query.id as string;
    const { data, isPending } = useGetUserQuery(id);
    const perms = usePermissions([
        'USER_PAPER_LIST',
        'USER_DEVICE_LIST',
        'USER_WORKPLACE_LIST',
        'USER_CONFIG_LIST',
        'USER_ASSIGN_ROLE',
        'GROUP_VIEW',
    ]);

    const [activeTab, setActiveTab] = useState<string | null>(DEFAULT_TAB);
    const [visitedTabs, setVisitedTabs] = useState<Set<string>>(new Set([DEFAULT_TAB]));
    const [isReadOnly, setIsReadOnly] = useState(true);
    const [isSaving, setIsSaving] = useState(false);
    const userFormRef = useRef<UserFormHandle>(null);

    const handleTabChange = useCallback((value: string | null) => {
        setActiveTab(value);
        if (value) {
            setVisitedTabs((prev) => new Set(prev).add(value));
        }
    }, []);

    const handleCancelEdit = useCallback(() => {
        setIsReadOnly(true);
        userFormRef.current?.resetForm();
    }, []);

    const isApproved = data && data.data ? data.data.paper_status === UserPaperStatusEnum.APPROVED.value : false;
    const isRejected = data && data.data ? data.data.paper_status === UserPaperStatusEnum.REJECTED.value : false;
    // A rejected file still belongs to a real employee on the roster: the admin has to be able
    // to read their schedule, reset the password and keep CrossStaff in step
    // while the paperwork is being sorted out.
    const canManageAccount = isApproved || isRejected;
    // Except for the two CrossStaff buttons, which write outside DGF Connect. A file rejected
    // at first registration never got a staff_no — ApprovePapersAction assigns one only on
    // approval — and syncing it takes the create branch, filing an HR record for somebody who
    // was never hired. Approved accounts keep the old gate untouched: a manager created by
    // CreateManagerAction is approved with no staff_no and could already sync.
    const canSyncCrossStaff = isApproved || (isRejected && Boolean(data?.data?.staff_no));
    const isWaitingForApproval =
        data && data.data ? data.data.paper_status === UserPaperStatusEnum.WAIT_FOR_APPROVAL.value : false;
    // An approved file that lacks documents its residence status requires needs the same
    // nudge: nothing puts it in the review queue, so the tab is the only way to notice it
    const isMissingRequiredDocuments = data?.data?.has_missing_required_documents ?? false;
    // The file owes nothing, so the flag above never fires — but the roster cannot tell
    // whether this employee is still enrolled, and they are working in the meantime
    const isMissingStudentCardExpiry = data?.data?.has_missing_student_card_expiry ?? false;

    const user = data?.data;

    return (
        <>
            <Breadcrumbs>
                <Anchor component={Link} href={RouteConfig.HOME.path}>
                    {t('Home')}
                </Anchor>
                <Anchor component={Link} href={RouteConfig.ADMIN_USER_LIST.path}>
                    {t('Employee list')}
                </Anchor>
                <Text>{t('Employee detail')}</Text>
            </Breadcrumbs>
            <Title order={4} lh="48px" my="md">
                {t('Employee detail')}
            </Title>

            <Tabs variant="unstyled" value={activeTab} onChange={handleTabChange} classNames={classes}>
                <Tabs.List grow>
                    <Tabs.Tab value="information">{t('Information')}</Tabs.Tab>
                    <Tabs.Tab value="papers" disabled={!perms.USER_PAPER_LIST}>
                        <Flex gap={4} justify="center">
                            {t('Papers')}
                            {isWaitingForApproval && <LuCircleAlert size={16} color="orange" />}
                            {isMissingRequiredDocuments && (
                                <Tooltip label={t('Missing documents for this residence status')}>
                                    <LuTriangleAlert size={16} color="var(--mantine-color-yellow-7)" />
                                </Tooltip>
                            )}
                            {isMissingStudentCardExpiry && (
                                <Tooltip label={t('Student card expiry date is missing')}>
                                    <LuTriangleAlert size={16} color="var(--mantine-color-red-7)" />
                                </Tooltip>
                            )}
                        </Flex>
                    </Tabs.Tab>
                    <Tabs.Tab value="devices" disabled={!perms.USER_DEVICE_LIST}>
                        {t('Devices')}
                    </Tabs.Tab>
                    <Tabs.Tab value="workplaces" disabled={!perms.USER_WORKPLACE_LIST}>
                        {t('Workplaces')}
                    </Tabs.Tab>
                    <Tabs.Tab value="roles">{t('Roles')}</Tabs.Tab>
                    <Tabs.Tab value="configuration" disabled={!perms.USER_CONFIG_LIST}>
                        {t('Configuration')}
                    </Tabs.Tab>
                    <Tabs.Tab value="lock_histories">{t('Lock & Unlock History')}</Tabs.Tab>
                    {perms.GROUP_VIEW && <Tabs.Tab value="groups">{t('Groups')}</Tabs.Tab>}
                </Tabs.List>

                <Tabs.Panel value="information" className={classes.panel}>
                    {/* Always-visible action bar */}
                    <UserAction
                        canManageAccount={canManageAccount}
                        canSyncCrossStaff={canSyncCrossStaff}
                        id={id}
                        user={user}
                        isLoading={isPending}
                        isReadOnly={isReadOnly}
                        onEdit={() => setIsReadOnly(false)}
                        onCancel={handleCancelEdit}
                        formId={FORM_ID}
                        isSaving={isSaving}
                    />

                    {/* Scrollable content */}
                    <Box className={classes.scrollContent} style={{ paddingBottom: 24 }}>
                        {/* Profile section */}
                        <Paper radius="md" p={24} mb={24}>
                            <Flex gap={24}>
                                <UserImages id={id} user={user} isLoading={isPending} />
                                <Stack gap={16} style={{ flex: 1 }}>
                                    <Stack gap={2}>
                                        <Text fz="xs" c="dimmed">
                                            {t('Common name')}
                                        </Text>
                                        <Flex gap="md" align="center">
                                            <Text fz="24" fw={700}>
                                                {user?.name}
                                            </Text>
                                            <Text fz="lg" fw={700} c="dimmed">
                                                {user?.name_kana}
                                            </Text>
                                        </Flex>
                                    </Stack>
                                    <Stack gap={8}>
                                        <Flex gap={8} align="center">
                                            <Text fz="sm" c="dimmed" w={180}>
                                                {t('Staff No')}
                                            </Text>
                                            <Text fz="sm" fw={500}>
                                                {user?.staff_no || 'N/A'}
                                            </Text>
                                            {user?.staff_no && (
                                                <CopyButton value={user.staff_no}>
                                                    {({ copied, copy }) => (
                                                        <Tooltip label={copied ? t('Copied') : t('Copy')}>
                                                            <ActionIcon
                                                                variant="subtle"
                                                                color={copied ? 'teal' : 'gray'}
                                                                size="sm"
                                                                onClick={copy}
                                                            >
                                                                {copied ? <LuCheck size={14} /> : <LuCopy size={14} />}
                                                            </ActionIcon>
                                                        </Tooltip>
                                                    )}
                                                </CopyButton>
                                            )}
                                        </Flex>
                                        <Flex gap={8} align="center">
                                            <Text fz="sm" c="dimmed" w={180}>
                                                {t('Paper status')}
                                            </Text>
                                            <Badge variant="light" color={getColor(user?.paper_status)}>
                                                {Object.values(UserPaperStatusEnum).find(
                                                    (s) => s.value === user?.paper_status,
                                                )?.text || 'N/A'}
                                            </Badge>
                                        </Flex>
                                        <Flex gap={8} align="center">
                                            <Text fz="sm" c="dimmed" w={180}>
                                                {t('Face registration')}
                                            </Text>
                                            <Badge
                                                color={user?.face_registered ? 'green' : 'gray'}
                                                leftSection={
                                                    user?.face_registered ? <LuCheck size={14} /> : <LuX size={14} />
                                                }
                                                variant="light"
                                            >
                                                {user?.face_registered
                                                    ? t('Face registered')
                                                    : t('Face not registered')}
                                            </Badge>
                                        </Flex>
                                    </Stack>
                                    {user?.residence_card_expiry_alert && (
                                        <DAlert type="warning" title={t('Card expired or expiring soon')}>
                                            {t('Residence card has expired or is about to expire, please update')}
                                        </DAlert>
                                    )}
                                </Stack>
                            </Flex>
                        </Paper>

                        {/* Form sections */}
                        <UserForm
                            ref={userFormRef}
                            id={id}
                            user={user}
                            isLoading={isPending}
                            isReadOnly={isReadOnly}
                            formId={FORM_ID}
                            onSaved={() => setIsReadOnly(true)}
                            onSavingChange={setIsSaving}
                        />
                    </Box>
                </Tabs.Panel>

                <Tabs.Panel value="papers" className={classes.panel}>
                    <Box className={classes.scrollContent}>
                        {visitedTabs.has('papers') && (
                            <UserPapers
                                status={data?.data?.paper_status || ''}
                                id={id}
                                residenceCardExpiryAlert={user?.residence_card_expiry_alert}
                            />
                        )}
                    </Box>
                </Tabs.Panel>

                <Tabs.Panel value="devices" className={classes.panel}>
                    <Box className={classes.scrollContent}>
                        {visitedTabs.has('devices') && (
                            <Paper p="md">
                                <UserDevices isApproved={isApproved} id={id} />
                            </Paper>
                        )}
                    </Box>
                </Tabs.Panel>

                <Tabs.Panel value="workplaces" className={classes.panel}>
                    <Box className={classes.scrollContent}>
                        {visitedTabs.has('workplaces') && (
                            <Paper p="md">
                                <UserWorkplaces id={id} />
                            </Paper>
                        )}
                    </Box>
                </Tabs.Panel>

                <Tabs.Panel value="roles" className={classes.panel}>
                    <Box className={classes.scrollContent}>
                        {visitedTabs.has('roles') && (
                            <Paper p="md">
                                <UserRoles isApproved={isApproved} id={id} />
                            </Paper>
                        )}
                    </Box>
                </Tabs.Panel>

                <Tabs.Panel value="configuration" className={classes.panel}>
                    <Box className={classes.scrollContent}>
                        {visitedTabs.has('configuration') && (
                            <Paper p="md">
                                <UserConfigs id={id} />
                            </Paper>
                        )}
                    </Box>
                </Tabs.Panel>

                <Tabs.Panel value="lock_histories" className={classes.panel}>
                    <Box className={classes.scrollContent}>
                        {visitedTabs.has('lock_histories') && (
                            <Paper p="md">
                                <UserLockHistories id={id} />
                            </Paper>
                        )}
                    </Box>
                </Tabs.Panel>

                {perms.GROUP_VIEW && (
                    <Tabs.Panel value="groups" className={classes.panel}>
                        <Box className={classes.scrollContent}>
                            {visitedTabs.has('groups') && <UserGroups id={id} isLocked={!!user?.is_disabled} />}
                        </Box>
                    </Tabs.Panel>
                )}
            </Tabs>
        </>
    );
};

export default IndexPage;
