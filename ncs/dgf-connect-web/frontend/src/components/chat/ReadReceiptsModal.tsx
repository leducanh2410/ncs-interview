import { useTranslation } from 'react-i18next';
import { Box, Button, Center, Divider, Group, Loader, Modal, ScrollArea, Stack, Text } from '@mantine/core';
import { ChatReadReceiptUser } from '@/types/models/chat';
import DAvatar from '@/components/DAvatar';
import { useAdminChatMessageReceiptsQuery } from './api';
import { formatMessageTime } from './helpers';

type ReadReceiptsModalProps = {
    conversationId: number | null;
    messageId: number | null;
    onClose: () => void;
};

const ReceiptRow = ({ user, seenAt }: { user: ChatReadReceiptUser; seenAt?: string | null }) => {
    return (
        <Group gap="xs" wrap="nowrap" py={4}>
            <DAvatar size="sm" radius="xl" name={user.name ?? ''} src={user.profile_picture ?? undefined} />
            <Box style={{ minWidth: 0, flex: 1 }}>
                <Text size="sm" truncate>
                    {user.name}
                    {user.staff_no && (
                        <Text span c="dimmed" size="xs">
                            {' '}
                            ({user.staff_no})
                        </Text>
                    )}
                </Text>
            </Box>
            {seenAt && (
                <Text size="xs" c="dimmed" style={{ flexShrink: 0 }}>
                    {formatMessageTime(seenAt)}
                </Text>
            )}
        </Group>
    );
};

const ReadReceiptsModal = ({ conversationId, messageId, onClose }: ReadReceiptsModalProps) => {
    const { t } = useTranslation();
    const { data, isLoading, isError, refetch, isFetching } = useAdminChatMessageReceiptsQuery(
        conversationId,
        messageId,
    );
    const receipts = data?.data;

    return (
        <Modal opened={messageId !== null} onClose={onClose} title={t('Message read status')} centered size="sm">
            {isLoading ? (
                <Center py="lg">
                    <Loader size="sm" />
                </Center>
            ) : isError || !receipts ? (
                <Stack gap="xs" py="md">
                    <Text size="sm" c="dimmed">
                        {t('Could not load the read status.')}
                    </Text>
                    <Group gap="xs">
                        <Button size="xs" variant="light" loading={isFetching} onClick={() => refetch()}>
                            {t('Retry')}
                        </Button>
                        <Button size="xs" variant="subtle" color="gray" onClick={onClose}>
                            {t('Close')}
                        </Button>
                    </Group>
                </Stack>
            ) : (
                <ScrollArea.Autosize mah={360}>
                    <Stack gap="xs">
                        <Text size="xs" c="dimmed">
                            {t('Seen by {{seen}} of {{total}}', {
                                seen: receipts.seen_count,
                                total: receipts.total_count,
                            })}
                        </Text>

                        {receipts.seen.length > 0 && (
                            <Box>
                                <Text size="xs" fw={600} c="dimmed" mb={2}>
                                    {t('Seen')}
                                </Text>
                                {receipts.seen.map((user) => (
                                    <ReceiptRow key={user.user_id} user={user} seenAt={user.seen_at} />
                                ))}
                            </Box>
                        )}

                        {receipts.seen.length > 0 && receipts.unseen.length > 0 && <Divider />}

                        {receipts.unseen.length > 0 && (
                            <Box>
                                <Text size="xs" fw={600} c="dimmed" mb={2}>
                                    {t('Not seen yet')}
                                </Text>
                                {receipts.unseen.map((user) => (
                                    <ReceiptRow key={user.user_id} user={user} />
                                ))}
                            </Box>
                        )}

                        {receipts.total_count === 0 && (
                            <Text size="sm" c="dimmed">
                                {t('No other members received this message.')}
                            </Text>
                        )}
                    </Stack>
                </ScrollArea.Autosize>
            )}
        </Modal>
    );
};

export default ReadReceiptsModal;
