import React from 'react';
import { useTranslation } from 'react-i18next';
import { LuSave, LuX } from 'react-icons/lu';
import { Button, Flex } from '@mantine/core';

type ActionBarProps = {
    isReadOnly: boolean;
    hasChanges: boolean;
    isSaving: boolean;
    onCancel: () => void;
    onSave: () => void;
};

const ActionBar = ({ isReadOnly, hasChanges, isSaving, onCancel, onSave }: ActionBarProps) => {
    const { t } = useTranslation();

    if (!hasChanges) {
        return null;
    }

    return (
        <Flex justify="center" align="center" gap={8} py="sm" style={{ flexShrink: 0, background: 'transparent' }}>
            <Button size="sm" variant="filled" color="gray" leftSection={<LuX size={14} />} onClick={onCancel}>
                {t('Cancel')}
            </Button>
            <Button
                size="sm"
                leftSection={<LuSave size={14} />}
                disabled={isReadOnly}
                loading={isSaving}
                onClick={onSave}
            >
                {t('Save')}
            </Button>
        </Flex>
    );
};

export default ActionBar;
