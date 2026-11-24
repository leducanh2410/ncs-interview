import React from 'react';
import { useTranslation } from 'react-i18next';
import { Alert, Button, Flex } from '@mantine/core';

interface Props {
    onRetry: () => void;
    message?: string;
}

const WidgetError: React.FC<Props> = ({ onRetry, message }) => {
    const { t } = useTranslation();
    return (
        <Alert color="red" variant="light" title={t('Failed to load data')}>
            <Flex justify="space-between" align="center" gap="md" wrap="wrap">
                <span>{message ?? t('Something went wrong while loading this widget.')}</span>
                <Button size="xs" variant="light" onClick={onRetry}>
                    {t('Retry')}
                </Button>
            </Flex>
        </Alert>
    );
};

export default WidgetError;
