import { TFunction } from 'i18next';
import { ReconciliationSource } from '@/types/models/reconciliation_batch';

export const RECONCILIATION_SOURCES: ReconciliationSource[] = [
    'amazon_kadou',
    'yamato_staff_data',
    'factory_timesheet',
];

export const sourceLabel = (source: string, t: TFunction): string => {
    switch (source) {
        case 'amazon_kadou':
            return t('Amazon work performance');
        case 'yamato_staff_data':
            return t('Yamato timesheet');
        case 'factory_timesheet':
            return t('Other factory timesheet');
        default:
            return source;
    }
};

export const externalSideLabel = (source: string, t: TFunction): string => {
    switch (source) {
        case 'amazon_kadou':
            return t('Amazon');
        case 'yamato_staff_data':
            return t('Yamato');
        case 'factory_timesheet':
            return t('Factory');
        default:
            return source;
    }
};

export const sourceOptions = (t: TFunction) =>
    RECONCILIATION_SOURCES.map((value) => ({ value, label: sourceLabel(value, t) }));
