import React, { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { LuTrash2 } from 'react-icons/lu';
import Link from 'next/link';
import { useRouter } from 'next/router';
import { Anchor, Breadcrumbs, Button, Flex, Loader, Text, Title } from '@mantine/core';
import RouteConfig from '@/configs/route.config';
import NotificationHelper from '@/helpers/notification.helper';
import DConfirmModal from '@/components/DConfirmModal';
import GroupManualView from '@/pages/admin/group/partials/group_manual_view';
import GroupRuleView from '@/pages/admin/group/partials/group_rule_view';
import GroupSystemView from '@/pages/admin/group/partials/group_system_view';
import { usePermissions } from '@/hooks/usePermission';
import { useDeleteGroupMutation, useGetGroupQuery } from '@/pages/admin/group/api';
import { getGroupDisplayName } from '@/utils/group_display_name';

const ADMIN_KINDS = new Set(['manual', 'rule_based']);

const GroupDetailPage = () => {
    const { t } = useTranslation();
    const router = useRouter();
    const id = router.query.id as string | undefined;
    const perms = usePermissions(['GROUP_VIEW', 'GROUP_EDIT']);

    const [membersPage, setMembersPage] = useState<number>(1);
    const params = { members_page: membersPage.toString() };
    const { data, isFetching } = useGetGroupQuery(id ?? '', params, perms.GROUP_VIEW && !!id);

    const [isDeleteOpen, setIsDeleteOpen] = useState(false);
    const { mutateAsync: deleteGroup, isPending: isDeleting } = useDeleteGroupMutation();

    const group = data?.data ?? null;
    const isAdminKind = group ? ADMIN_KINDS.has(group.kind) : false;

    const onDelete = async () => {
        if (!group) return;
        try {
            await deleteGroup(group.id);
            NotificationHelper.showSuccess(t('Successfully deleted group'));
            await router.push(RouteConfig.ADMIN_GROUP_LIST.path);
        } catch {
            NotificationHelper.showError(t('error|Could not delete group'));
        } finally {
            setIsDeleteOpen(false);
        }
    };

    const renderBody = () => {
        if (!group) return null;
        if (group.kind === 'manual') {
            return <GroupManualView group={group} isFetching={isFetching} onMembersPageChange={setMembersPage} />;
        }
        if (group.kind === 'rule_based') {
            return <GroupRuleView group={group} isFetching={isFetching} onMembersPageChange={setMembersPage} />;
        }
        return <GroupSystemView group={group} isFetching={isFetching} onMembersPageChange={setMembersPage} />;
    };

    return (
        <>
            <Breadcrumbs>
                <Anchor component={Link} href={RouteConfig.HOME.path}>
                    {t('Home')}
                </Anchor>
                <Anchor component={Link} href={RouteConfig.ADMIN_GROUP_LIST.path}>
                    {t('Groups')}
                </Anchor>
                <Text>{group ? getGroupDisplayName(group) || t('Group detail') : t('Group detail')}</Text>
            </Breadcrumbs>
            <Flex justify="space-between" align="center">
                <Title order={4} lh="48px" my="md">
                    {group ? getGroupDisplayName(group) || t('Group detail') : t('Group detail')}
                </Title>
                {isAdminKind && (
                    <Button
                        variant="light"
                        color="red"
                        leftSection={<LuTrash2 size={14} />}
                        onClick={() => setIsDeleteOpen(true)}
                        disabled={!perms.GROUP_EDIT}
                    >
                        {t('Delete group')}
                    </Button>
                )}
            </Flex>
            {isFetching && !group && <Loader />}
            {renderBody()}
            <DConfirmModal
                opened={isDeleteOpen}
                title={t('Delete group')}
                message={t('Are you sure you want to delete this group? This action cannot be undone.')}
                onConfirm={onDelete}
                onCancel={() => setIsDeleteOpen(false)}
                confirmButtonLabel={t('Delete group')}
                confirmButtonColor="red"
                isProcessing={isDeleting}
            />
        </>
    );
};

export default GroupDetailPage;
