import React from 'react';
import { useTranslation } from 'react-i18next';
import { LuInfo } from 'react-icons/lu';
import { Alert, Button, Flex, Text } from '@mantine/core';
import { paperStepLabel } from '@/utils/paper_step_label';

/**
 * Documents the employee's residence status calls for but that are not on file.
 *
 * Nothing revokes the approval on its own — someone approved before a document became
 * mandatory keeps working — so the admin is told, and decides. `onRequestCompletion` is
 * that decision: it is offered only for an approved file, because a file already waiting
 * for review is settled through the approve modal, which can reject individual documents.
 *
 * A pending file gets `onReopenSteps` instead, under the same label but never alongside the
 * approved-file button. It reopens only documents that still have images on file but no step
 * row, which the app would otherwise show as done with no way to resubmit. Missing documents
 * the employee can already file from the app are listed in the blue `awaitingSteps` notice.
 */
const PaperMissingDocumentsAlert = ({
    missingSteps,
    onRequestCompletion,
    canRequestCompletion,
    canReopenSteps,
    onReopenSteps,
    reopening,
    awaitingSteps,
}: Props) => {
    const { t } = useTranslation();

    const hasMissingSteps = !!missingSteps && missingSteps.length > 0;
    const hasAwaitingSteps = !!awaitingSteps && awaitingSteps.length > 0;

    if (!hasMissingSteps && !hasAwaitingSteps) {
        return null;
    }

    return (
        <>
            {hasMissingSteps && (
                <MissingStepsAlert
                    missingSteps={missingSteps}
                    canRequestCompletion={canRequestCompletion}
                    onRequestCompletion={onRequestCompletion}
                    canReopenSteps={canReopenSteps}
                    onReopenSteps={onReopenSteps}
                    reopening={reopening}
                />
            )}
            {hasAwaitingSteps && (
                <Alert color="blue" icon={<LuInfo />} title={t('Waiting for the employee to submit documents')} mb="md">
                    <Text size="sm">
                        {t('Requested in the app:')} {awaitingSteps.map((step) => paperStepLabel(step, t)).join(', ')}
                    </Text>
                </Alert>
            )}
        </>
    );
};

const MissingStepsAlert = ({
    missingSteps,
    onRequestCompletion,
    canRequestCompletion,
    canReopenSteps,
    onReopenSteps,
    reopening,
}: Omit<Props, 'missingSteps' | 'awaitingSteps'> & { missingSteps: string[] }) => {
    const { t } = useTranslation();

    return (
        <Alert color="yellow" icon={<LuInfo />} title={t('Missing documents for this residence status')} mb="md">
            <Text size="sm">
                {t('Not on file:')} {missingSteps.map((step) => paperStepLabel(step, t)).join(', ')}
            </Text>
            {canRequestCompletion && onRequestCompletion && (
                <Flex justify="flex-start" mt={12}>
                    <Button size="xs" variant="filled" color="yellow" onClick={onRequestCompletion}>
                        {t('Request document completion')}
                    </Button>
                </Flex>
            )}
            {canReopenSteps && onReopenSteps && (
                <Flex justify="flex-start" mt={12}>
                    <Button size="xs" variant="filled" color="yellow" loading={reopening} onClick={onReopenSteps}>
                        {t('Request document completion')}
                    </Button>
                </Flex>
            )}
        </Alert>
    );
};

type Props = {
    missingSteps: string[] | null | undefined;
    /** Only an approved file can be sent back; a file under review belongs to the approve modal */
    canRequestCompletion?: boolean;
    onRequestCompletion?: () => void;
    canReopenSteps?: boolean;
    onReopenSteps?: () => void;
    reopening?: boolean;
    awaitingSteps?: string[];
};

export default PaperMissingDocumentsAlert;
