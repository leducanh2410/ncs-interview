import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Button, Flex, List, Modal, Text } from '@mantine/core';
import { DatePickerInput } from '@mantine/dates';
import DateHelper, { DATE_STORE_FORMAT } from '@/helpers/date.helper';

const BulkConfigModal = ({ request, onConfirm, onCancel, isProcessing }: Props) => {
    const { t } = useTranslation();
    const [deadline, setDeadline] = useState<string | null>(null);
    // Mirrors the backend's window rule (is_window_open: today <= deadline): today is a valid
    // deadline that keeps the window open through end of day.
    const today = DateHelper.now().format(DATE_STORE_FORMAT);
    const isValidDeadline = !!deadline && deadline >= today;

    useEffect(() => {
        setDeadline(null);
    }, [request]);

    if (!request) return null;

    return (
        <Modal
            opened
            onClose={onCancel}
            title={
                request.isEnabled
                    ? t('Enable change requests for {{count}} workplaces', { count: request.workplaceIds.length })
                    : t('Disable change requests for {{count}} workplaces', { count: request.workplaceIds.length })
            }
        >
            <Text size="sm" mb="xs">
                {t('This will apply to the following workplaces:')}
            </Text>
            <List size="sm" mb="md">
                {request.workplaceNames.map((name) => (
                    <List.Item key={name}>{name}</List.Item>
                ))}
            </List>
            {request.isEnabled && (
                <DatePickerInput
                    label={t('Deadline')}
                    valueFormat={t('FORMAT_DATE')}
                    value={deadline}
                    onChange={setDeadline}
                    minDate={today}
                    required
                    mb="md"
                />
            )}
            <Flex justify="flex-end" gap="xs">
                <Button color="gray" onClick={onCancel}>
                    {t('Cancel')}
                </Button>
                <Button
                    color={request.isEnabled ? 'teal' : 'red'}
                    loading={isProcessing}
                    disabled={request.isEnabled && !isValidDeadline}
                    onClick={() => onConfirm(deadline)}
                >
                    {t('Confirm')}
                </Button>
            </Flex>
        </Modal>
    );
};

export type BulkConfigRequest = {
    workplaceIds: number[];
    workplaceNames: string[];
    isEnabled: boolean;
};

type Props = {
    request: BulkConfigRequest | null;
    onConfirm: (deadline: string | null) => void;
    onCancel: () => void;
    isProcessing?: boolean;
};

export default BulkConfigModal;
