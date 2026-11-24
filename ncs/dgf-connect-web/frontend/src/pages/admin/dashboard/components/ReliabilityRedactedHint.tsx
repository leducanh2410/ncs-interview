import React from 'react';
import { useTranslation } from 'react-i18next';
import { LuLock } from 'react-icons/lu';
import { Alert } from '@mantine/core';

const ReliabilityRedactedHint: React.FC = () => {
    const { t } = useTranslation();

    return (
        <Alert icon={<LuLock size={16} />} color="gray" variant="light" title={t('Reliability data hidden')}>
            {t('Needs the work_history.list permission to view check-in derived metrics.')}
        </Alert>
    );
};

export default ReliabilityRedactedHint;
