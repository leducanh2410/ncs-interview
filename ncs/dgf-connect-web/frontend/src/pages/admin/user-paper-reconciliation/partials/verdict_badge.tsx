import React from 'react';
import { useTranslation } from 'react-i18next';
import { Badge, MantineColor } from '@mantine/core';
import { PaperReconciliationVerdictEnum } from '@/enums/paper_reconciliation_verdict.enum';

/**
 * One mapping for the whole page, so "cannot compare" can never be styled like a match in
 * one place and not another.
 */
const VERDICT_COLORS: Record<string, MantineColor> = {
    matched: 'green',
    mismatched: 'red',
    employee_data_missing: 'gray',
    not_comparable: 'gray',
    not_uploaded: 'yellow',
};

const verdictLabel = (verdict: string): string => {
    const entry = Object.values(PaperReconciliationVerdictEnum).find((v) => v.value === verdict);
    return entry?.text ?? verdict;
};

const VerdictBadge = ({ verdict, mismatchCount }: { verdict: string; mismatchCount?: number }) => {
    const { t } = useTranslation();
    const showCount = verdict === 'mismatched' && (mismatchCount ?? 0) > 0;

    return (
        <Badge color={VERDICT_COLORS[verdict] ?? 'gray'} variant="light">
            {showCount ? t('{{count}} field differs', { count: mismatchCount }) : t(verdictLabel(verdict))}
        </Badge>
    );
};

export default VerdictBadge;
