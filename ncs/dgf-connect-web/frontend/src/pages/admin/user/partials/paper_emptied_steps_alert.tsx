import React from 'react';
import { useTranslation } from 'react-i18next';
import { LuTriangleAlert } from 'react-icons/lu';
import { Alert, Button, Flex, Text } from '@mantine/core';
import { paperStepLabel } from '@/utils/paper_step_label';

const PaperEmptiedStepsAlert = ({ emptiedSteps, canReopen, onReopen, reopening }: Props) => {
    const { t } = useTranslation();

    if (!emptiedSteps || emptiedSteps.length === 0) {
        return null;
    }

    return (
        <Alert
            color="red"
            icon={<LuTriangleAlert />}
            title={t('Documents marked as submitted have missing images')}
            mb="md"
        >
            <Text size="sm">
                {t('Images missing:')} {emptiedSteps.map((step) => paperStepLabel(step, t)).join(', ')}
            </Text>
            <Text size="sm">{t('The employee cannot upload these documents again until they are reopened.')}</Text>
            {canReopen && onReopen && (
                <Flex justify="flex-start" mt={12}>
                    <Button size="xs" variant="filled" color="red" loading={reopening} onClick={onReopen}>
                        {t('Reopen these documents')}
                    </Button>
                </Flex>
            )}
        </Alert>
    );
};

type Props = {
    emptiedSteps: string[] | null | undefined;
    canReopen?: boolean;
    onReopen?: () => void;
    reopening?: boolean;
};

export default PaperEmptiedStepsAlert;
