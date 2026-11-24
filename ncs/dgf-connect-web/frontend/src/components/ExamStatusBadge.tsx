import React from 'react';
import { useTranslation } from 'react-i18next';
import { Badge } from '@mantine/core';
import { ExamStatus } from '@/types/models/exam';
import DateHelper, { DATE_STORE_FORMAT_WITH_TIME } from '@/helpers/date.helper';

type ExamLike = {
    start_at: string | null;
    end_at: string | null;
    status?: ExamStatus;
};

/**
 * Resolve the activation status of an exam. Prefers a server-provided `status`
 * (computed by the Exam model accessor) and falls back to a client-side
 * derivation from start_at/end_at so the badge stays accurate even before the
 * page is refetched.
 */
export function getExamStatus(exam: ExamLike): ExamStatus {
    if (exam.status) {
        return exam.status;
    }
    const now = DateHelper.now();
    const start = exam.start_at ? DateHelper.parse(exam.start_at, DATE_STORE_FORMAT_WITH_TIME) : null;
    const end = exam.end_at ? DateHelper.parse(exam.end_at, DATE_STORE_FORMAT_WITH_TIME) : null;
    if (start && start.isAfter(now)) {
        return 'scheduled';
    }
    if (end && end.isBefore(now)) {
        return 'ended';
    }
    return 'active';
}

type Props = {
    exam: ExamLike;
};

const STATUS_COLOR: Record<ExamStatus, string> = {
    scheduled: 'blue',
    active: 'green',
    ended: 'gray',
};

const STATUS_LABEL_KEY: Record<ExamStatus, string> = {
    scheduled: 'Scheduled',
    active: 'Active',
    ended: 'Ended',
};

export default function ExamStatusBadge({ exam }: Props) {
    const { t } = useTranslation();
    const status = getExamStatus(exam);
    return (
        <Badge color={STATUS_COLOR[status]} variant="light">
            {t(STATUS_LABEL_KEY[status])}
        </Badge>
    );
}
