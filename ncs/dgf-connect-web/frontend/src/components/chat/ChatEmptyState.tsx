import { ReactNode } from 'react';
import { Box, Text } from '@mantine/core';
import classes from './chat.module.css';

type ChatEmptyStateProps = {
    icon: ReactNode;
    message: string;
    mih?: number;
};

/**
 * Shared "nothing here" state for the chat panes: a centered icon over a single
 * muted line. One vocabulary so every empty pane (no chats, no messages, no
 * search matches, no selection) reads the same. The danger sibling is ChatErrorState.
 */
const ChatEmptyState = ({ icon, message, mih }: ChatEmptyStateProps) => (
    <Box className={classes.emptyState} mih={mih} py="xl">
        {icon}
        <Text size="sm" c="gray.5" ta="center" maw={260}>
            {message}
        </Text>
    </Box>
);

export default ChatEmptyState;
