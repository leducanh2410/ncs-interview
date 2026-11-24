import React, { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { LuPlus, LuTrash2 } from 'react-icons/lu';
import { ActionIcon, ComboboxItem, Flex, Group, Loader, Stack, Text, Title, Tooltip } from '@mantine/core';
import { SupportType, SupportTypeConfig } from '@/types/models/chat';
import { User } from '@/types/models/user.d';
import { ApiConfig } from '@/configs/api.config';
import { PermissionNameEnum } from '@/enums/permission_name.enum';
import DAvatar from '@/components/DAvatar';
import DSelectRemote from '@/components/DSelectRemote';
import { useAddSupportTypeAdminMutation, useRemoveSupportTypeAdminMutation } from '@/pages/admin/chat/support/api';

const renderUserItem = (user: User): ComboboxItem => ({
    value: user.id.toString(),
    label: `${user.name} (${user.staff_no || '-'})`,
});

const SUPPORT_ADMIN_PERMISSIONS = [PermissionNameEnum.CHAT_SUPPORT.value, PermissionNameEnum.CHAT_VIEW.value];

type TypeSectionProps = {
    config: SupportTypeConfig;
    canEdit: boolean;
};

function TypeSection({ config, canEdit }: TypeSectionProps) {
    const { t } = useTranslation();
    const [selectedUserId, setSelectedUserId] = useState<string | null>(null);
    const addMutation = useAddSupportTypeAdminMutation();
    const removeMutation = useRemoveSupportTypeAdminMutation();

    const handleAdd = () => {
        if (!selectedUserId) return;
        addMutation.mutate(
            { type: config.type as SupportType, userId: Number(selectedUserId) },
            { onSuccess: () => setSelectedUserId(null) },
        );
    };

    const handleRemove = (userId: number) => {
        removeMutation.mutate({ type: config.type as SupportType, userId });
    };

    return (
        <Stack gap="xs">
            <Text fw={600} size="sm">
                {t(`support_type|${config.type}`)}
            </Text>

            <Stack gap={4}>
                {config.admins.length === 0 && (
                    <Text size="sm" c="dimmed">
                        {t('No admins assigned')}
                    </Text>
                )}
                {config.admins.map((admin) => (
                    <Flex key={admin.user_id} align="center" justify="space-between" gap="sm">
                        <Group gap="xs">
                            <DAvatar size="sm" name={admin.name ?? ''} src={admin.profile_picture} />
                            <Text size="sm">{admin.name}</Text>
                            {admin.staff_no && (
                                <Text size="xs" c="dimmed">
                                    ({admin.staff_no})
                                </Text>
                            )}
                        </Group>
                        {canEdit && (
                            <Tooltip label={t('Remove')}>
                                <ActionIcon
                                    color="red"
                                    variant="subtle"
                                    size="sm"
                                    loading={removeMutation.isPending}
                                    onClick={() => handleRemove(admin.user_id)}
                                >
                                    <LuTrash2 size={14} />
                                </ActionIcon>
                            </Tooltip>
                        )}
                    </Flex>
                ))}
            </Stack>

            {canEdit && (
                <Flex gap="xs" align="center">
                    <DSelectRemote<User>
                        uri={ApiConfig.ADMIN_MASTER_USER_SEARCH}
                        renderItem={renderUserItem}
                        value={selectedUserId}
                        onChange={setSelectedUserId}
                        placeholder={t('Search admin')}
                        style={{ flex: 1 }}
                        clearable
                        extraParams={{ permission: SUPPORT_ADMIN_PERMISSIONS }}
                    />
                    <ActionIcon
                        onClick={handleAdd}
                        disabled={!selectedUserId}
                        loading={addMutation.isPending}
                        variant="filled"
                    >
                        <LuPlus size={16} />
                    </ActionIcon>
                </Flex>
            )}
        </Stack>
    );
}

type Props = {
    configs: SupportTypeConfig[] | undefined;
    isLoading: boolean;
    canEdit: boolean;
};

export default function SupportTypeConfigSection({ configs, isLoading, canEdit }: Props) {
    const { t } = useTranslation();

    return (
        <Stack mt="xl">
            <Title order={5}>{t('Support type admin assignment')}</Title>
            {isLoading ? (
                <Flex align="center" justify="center" h={100}>
                    <Loader size="sm" />
                </Flex>
            ) : (
                <Stack gap="lg">
                    {(configs ?? []).map((config) => (
                        <TypeSection key={config.type} config={config} canEdit={canEdit} />
                    ))}
                </Stack>
            )}
        </Stack>
    );
}
