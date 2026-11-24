import React, { ReactNode, useEffect, useMemo, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { LuPlus, LuSave, LuSearch, LuX } from 'react-icons/lu';
import {
    ActionIcon,
    Box,
    Button,
    Center,
    Divider,
    Flex,
    Group,
    Loader,
    Modal,
    ScrollArea,
    Stack,
    Text,
    TextInput,
} from '@mantine/core';
import { ParsedUrlQuery } from 'querystring';
import { User } from '@/types/models/user';
import { ApiConfig } from '@/configs/api.config';
import ApiHelper from '@/helpers/api.helper';

type DUserSelectModalProps = {
    isOpen: boolean;
    onClose: () => void;
    onConfirm: (users: User[]) => void;
    title?: string;
    uri?: string;
    extraParams?: ParsedUrlQuery;
    minSearchLength?: number;
    initialSelectedUsers?: User[];
    renderUserRow?: (user: User) => ReactNode;
    confirmButtonLabel?: string;
    cancelButtonLabel?: string;
    searchPlaceholder?: string;
};

type UserRowProps = {
    user: User;
    actionType: 'add' | 'remove';
    onAction: (user: User) => void;
    renderUserRow?: (user: User) => ReactNode;
};

const DefaultUserRow = ({ user }: { user: User }) => (
    <>
        <Text size="sm" w={90} style={{ flexShrink: 0 }} c={user.staff_no ? undefined : 'dimmed'}>
            {user.staff_no || '-'}
        </Text>
        <Box style={{ flex: 1, minWidth: 0 }}>
            <Text size="sm" truncate>
                {user.name}
            </Text>
            <Text size="xs" c="dimmed" truncate>
                {user.email}
            </Text>
        </Box>
    </>
);

const UserRow = ({ user, actionType, onAction, renderUserRow }: UserRowProps) => {
    const { t } = useTranslation();

    return (
        <Flex align="center" gap={8} py={6} px={8}>
            {renderUserRow ? renderUserRow(user) : <DefaultUserRow user={user} />}
            {actionType === 'add' ? (
                <ActionIcon size="sm" variant="light" color="teal" onClick={() => onAction(user)} aria-label={t('Add')}>
                    <LuPlus size={14} />
                </ActionIcon>
            ) : (
                <ActionIcon
                    size="sm"
                    variant="light"
                    color="red"
                    onClick={() => onAction(user)}
                    aria-label={t('Remove')}
                >
                    <LuX size={14} />
                </ActionIcon>
            )}
        </Flex>
    );
};

const DUserSelectModal = ({
    isOpen,
    onClose,
    onConfirm,
    title,
    uri = ApiConfig.ADMIN_MASTER_USER_SEARCH,
    extraParams,
    minSearchLength = 1,
    initialSelectedUsers = [],
    renderUserRow,
    confirmButtonLabel,
    cancelButtonLabel,
    searchPlaceholder,
}: DUserSelectModalProps) => {
    const { t } = useTranslation();
    const [searchInput, setSearchInput] = useState('');
    const [searchResults, setSearchResults] = useState<User[] | null>(null);
    const [isSearching, setIsSearching] = useState(false);
    const [selectedUsers, setSelectedUsers] = useState<User[]>([]);
    const abortRef = useRef<AbortController | null>(null);

    const reset = () => {
        setSearchInput('');
        setSearchResults(null);
        setIsSearching(false);
        setSelectedUsers([]);
        abortRef.current?.abort();
        abortRef.current = null;
    };

    const closeHandler = () => {
        reset();
        onClose();
    };

    useEffect(() => {
        if (isOpen) {
            setSelectedUsers(initialSelectedUsers);
        } else {
            reset();
        }
    }, [isOpen]);

    const selectedIds = useMemo(() => new Set(selectedUsers.map((u) => u.id)), [selectedUsers]);

    const visibleResults = useMemo(
        () => (searchResults || []).filter((u) => !selectedIds.has(u.id)),
        [searchResults, selectedIds],
    );

    const handleAdd = (user: User) => {
        setSelectedUsers((prev) => (prev.some((u) => u.id === user.id) ? prev : [...prev, user]));
    };

    const handleRemove = (user: User) => {
        setSelectedUsers((prev) => prev.filter((u) => u.id !== user.id));
    };

    const runSearch = async (exactStaffNoOnly = false) => {
        const query = searchInput.trim();
        if (!query) return;

        abortRef.current?.abort();
        const controller = new AbortController();
        abortRef.current = controller;

        try {
            setIsSearching(true);
            const params: ParsedUrlQuery = { ...extraParams, q: query };
            if (exactStaffNoOnly) {
                params.staff_no = query;
            }
            const response = await ApiHelper.get(uri, {
                abortSignal: controller.signal,
                params,
            });
            const users = response.data as User[];
            setSearchResults(users);
            setSearchInput('');
        } catch (e) {
            if (!controller.signal.aborted) {
                console.error(e);
                setSearchResults([]);
            }
        } finally {
            if (!controller.signal.aborted) {
                setIsSearching(false);
            }
        }
    };

    return (
        <Modal opened={isOpen} onClose={closeHandler} title={title || t('Select users')} size="lg">
            <Stack gap="md">
                <Box>
                    <TextInput
                        placeholder={searchPlaceholder ?? t('Search user')}
                        leftSection={<LuSearch size={16} />}
                        rightSection={isSearching ? <Loader size="xs" /> : null}
                        value={searchInput}
                        onChange={(e) => setSearchInput(e.currentTarget.value)}
                        onKeyDown={(e) => {
                            if (e.key === 'Enter') {
                                e.preventDefault();
                                const isCtrlMeta = e.ctrlKey || e.metaKey;
                                runSearch(isCtrlMeta);
                            }
                        }}
                    />
                    <Text size="xs" c="dimmed" mt={4}>
                        {t('Enter staff number or email, then press Enter to search.')}
                    </Text>
                    <Text size="xs" c="dimmed">
                        {t('Press Ctrl+Enter to search by staff number only (exact match).')}
                    </Text>
                    <ScrollArea
                        h={150}
                        mt={8}
                        style={{ border: '1px solid var(--mantine-color-gray-3)', borderRadius: 4 }}
                    >
                        {searchResults === null ? (
                            <Center h={150}>
                                <Text size="sm" c="dimmed">
                                    {t('No search performed yet')}
                                </Text>
                            </Center>
                        ) : visibleResults.length === 0 ? (
                            <Center h={150}>
                                <Text size="sm" c="dimmed">
                                    {t('No results')}
                                </Text>
                            </Center>
                        ) : (
                            <Stack gap={0} p={4}>
                                {visibleResults.map((user) => (
                                    <UserRow
                                        key={user.id}
                                        user={user}
                                        actionType="add"
                                        onAction={handleAdd}
                                        renderUserRow={renderUserRow}
                                    />
                                ))}
                            </Stack>
                        )}
                    </ScrollArea>
                </Box>
                <Divider />
                <Box>
                    <Group justify="space-between" mb={4}>
                        <Text size="sm" fw={500}>
                            {t('Users to be selected')}
                        </Text>
                        <Text size="xs" c="dimmed">
                            {selectedUsers.length}
                        </Text>
                    </Group>
                    <ScrollArea h={260} style={{ border: '1px solid var(--mantine-color-gray-3)', borderRadius: 4 }}>
                        {selectedUsers.length === 0 ? (
                            <Center h={260}>
                                <Text size="sm" c="dimmed">
                                    {t('No users selected')}
                                </Text>
                            </Center>
                        ) : (
                            <Stack gap={0} p={4}>
                                {selectedUsers.map((user) => (
                                    <UserRow
                                        key={user.id}
                                        user={user}
                                        actionType="remove"
                                        onAction={handleRemove}
                                        renderUserRow={renderUserRow}
                                    />
                                ))}
                            </Stack>
                        )}
                    </ScrollArea>
                </Box>
            </Stack>
            <Flex justify="end" mt={24} gap={8}>
                <Button variant="filled" color="gray" onClick={closeHandler}>
                    {cancelButtonLabel || t('Cancel')}
                </Button>
                <Button
                    variant="filled"
                    color="teal"
                    leftSection={<LuSave />}
                    disabled={selectedUsers.length === 0}
                    onClick={() => {
                        onConfirm(selectedUsers);
                        closeHandler();
                    }}
                >
                    {confirmButtonLabel || t('Save')}
                </Button>
            </Flex>
        </Modal>
    );
};

export default DUserSelectModal;
