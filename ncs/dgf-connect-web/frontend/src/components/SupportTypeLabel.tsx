import React from 'react';
import { useTranslation } from 'react-i18next';
import { Badge } from '@mantine/core';
import { SupportType } from '@/types/models/chat';

const TYPE_COLOR: Record<SupportType, string> = {
    document: 'blue',
    shift: 'green',
    salary: 'orange',
    transportation: 'violet',
    other: 'gray',
};

const TYPE_LABEL_KEY: Record<SupportType, string> = {
    document: 'support_type|document',
    shift: 'support_type|shift',
    salary: 'support_type|salary',
    transportation: 'support_type|transportation',
    other: 'support_type|other',
};

type Props = {
    type: SupportType | null | undefined;
};

export default function SupportTypeLabel({ type }: Props) {
    const { t } = useTranslation();

    if (!type) return null;

    return (
        <Badge color={TYPE_COLOR[type]} variant="light">
            {t(TYPE_LABEL_KEY[type])}
        </Badge>
    );
}
