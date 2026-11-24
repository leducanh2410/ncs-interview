import React from 'react';
import { useTranslation } from 'react-i18next';
import { Box, Button, Flex, List, Modal, Text } from '@mantine/core';
import { User } from '@/types/models/user';
import { WorkplacePosition } from '@/types/models/workplace_position';
import {
    WeeklyWorkLoadProjection,
    formatHours,
    isAssignmentBlocked,
} from '@/pages/manage/shift/partials/weekly_work_load';
import { ShiftWeeklyWorkLoad } from '@/pages/manage/shift/api';

export type BulkAssignRequest = {
    userIds: string[];
    users: Record<string, User>;
    fromGroupId: string;
    toGroupId: string;
};

type Props = {
    request: BulkAssignRequest | null;
    positions: WorkplacePosition[];
    shiftHours?: number;
    weeklyWorkLoads?: Record<string, ShiftWeeklyWorkLoad>;
    onConfirm: () => void;
    onCancel: () => void;
};

const getGroupLabel = (groupId: string, positions: WorkplacePosition[], t: (key: string) => string): string => {
    if (groupId === 'pending') return t('Register list');
    if (groupId === 'rejected') return t('Rejected list');
    const pos = positions.find((p) => p.id.toString() === groupId);
    return pos?.name || groupId;
};

const isAssignmentTarget = (groupId: string) => groupId !== 'pending' && groupId !== 'rejected';

const BulkAssignModal = ({ request, positions, shiftHours, weeklyWorkLoads, onConfirm, onCancel }: Props) => {
    const { t } = useTranslation();

    if (!request) return null;

    const fromLabel = getGroupLabel(request.fromGroupId, positions, t);
    const toLabel = getGroupLabel(request.toGroupId, positions, t);
    const showsWorkLoad = isAssignmentTarget(request.toGroupId);
    const isBlockedHere = (id: string) =>
        request.toGroupId !== 'rejected' && isAssignmentBlocked(weeklyWorkLoads?.[id]);
    const hasBlockedUsers = request.userIds.some(isBlockedHere);

    return (
        <Modal opened={!!request} onClose={onCancel} title={t('Confirm assignment')}>
            <Box mb="md">
                <Text size="sm" mb="xs">
                    {t('Move {{count}} user(s) from {{from}} to {{to}}:', {
                        count: request.userIds.length,
                        from: fromLabel,
                        to: toLabel,
                    })}
                </Text>
                {showsWorkLoad && shiftHours !== undefined && (
                    <Text size="xs" c="dimmed" mb="xs">
                        {t(
                            'This shift: {{hours}}. Weekly hours are shown as current → after adding this shift / limit.',
                            {
                                hours: formatHours(shiftHours, t),
                            },
                        )}
                    </Text>
                )}
                <List size="sm">
                    {request.userIds.map((id) => (
                        <List.Item key={id}>
                            <Flex gap="xs" align="center">
                                <Text size="sm">{request.users[id]?.name || id}</Text>
                                {showsWorkLoad && <WeeklyWorkLoadProjection workLoad={weeklyWorkLoads?.[id]} />}
                                {isBlockedHere(id) && (
                                    <Text size="xs" c="red" fw={700}>
                                        {t('Cannot assign')}
                                    </Text>
                                )}
                            </Flex>
                        </List.Item>
                    ))}
                </List>
                {hasBlockedUsers && (
                    <Text size="xs" c="red" mt="xs">
                        {t('Users over the weekly work-hour limit will not be assigned.')}
                    </Text>
                )}
            </Box>
            <Flex justify="flex-end" gap={8}>
                <Button variant="filled" color="gray" onClick={onCancel}>
                    {t('Cancel')}
                </Button>
                <Button variant="filled" onClick={onConfirm}>
                    {t('Confirm')}
                </Button>
            </Flex>
        </Modal>
    );
};

export default BulkAssignModal;
