import { useTranslation } from 'react-i18next';
import { LuCircleAlert } from 'react-icons/lu';
import { Box, Button, Text } from '@mantine/core';
import classes from './chat.module.css';

type ChatErrorStateProps = {
    message: string;
    onRetry: () => void;
    mih?: number;
};

/**
 * Shared failure state for the chat panes. Mirrors the empty-state layout
 * (centered icon + line) but in the danger color with a retry action, so a
 * failed load never gets mistaken for an empty inbox.
 */
const ChatErrorState = ({ message, onRetry, mih }: ChatErrorStateProps) => {
    const { t } = useTranslation();

    return (
        <Box className={classes.emptyState} mih={mih} py="xl">
            <LuCircleAlert size={40} color="var(--mantine-color-red-6)" />
            <Text size="sm" c="dark.4" ta="center" maw={260}>
                {message}
            </Text>
            <Button mt="xs" size="xs" variant="default" onClick={onRetry}>
                {t('Try again')}
            </Button>
        </Box>
    );
};

export default ChatErrorState;
