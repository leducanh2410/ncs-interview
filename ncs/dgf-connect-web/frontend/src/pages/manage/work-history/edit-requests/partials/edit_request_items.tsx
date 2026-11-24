import { useTranslation } from 'react-i18next';
import { Table, Text } from '@mantine/core';
import { TFunction } from 'i18next';
import { WorkHistoryEditRequestField, WorkHistoryEditRequestItem } from '@/types/models/work_history_edit_request';
import DateHelper, { DATE_STORE_FORMAT_WITH_TIME } from '@/helpers/date.helper';

export const fieldLabel = (t: TFunction, field: WorkHistoryEditRequestField): string => {
    switch (field) {
        case 'check_in_time':
            return t('Check in time');
        case 'check_out_time':
            return t('Check out time');
        case 'break_minutes':
            return t('Break time (minute)');
        default:
            return field;
    }
};

export const formatValue = (t: TFunction, field: WorkHistoryEditRequestField, value: string | null): string => {
    if (value === null) {
        return '-';
    }
    if (field === 'break_minutes') {
        return value;
    }
    return DateHelper.parseAndFormat(value, DATE_STORE_FORMAT_WITH_TIME, t('FORMAT_DATE_WITH_TIME'));
};

const EditRequestItems = ({ items }: { items: WorkHistoryEditRequestItem[] }) => {
    const { t } = useTranslation();

    return (
        <Table>
            <Table.Thead>
                <Table.Tr>
                    <Table.Th>{t('Field')}</Table.Th>
                    <Table.Th>{t('Value when submitted')}</Table.Th>
                    <Table.Th>{t('New value')}</Table.Th>
                    <Table.Th>{t('Reason')}</Table.Th>
                </Table.Tr>
            </Table.Thead>
            <Table.Tbody>
                {items.map((item) => (
                    <Table.Tr key={item.id}>
                        <Table.Td>{fieldLabel(t, item.field)}</Table.Td>
                        <Table.Td>{formatValue(t, item.field, item.old_value)}</Table.Td>
                        <Table.Td>
                            <Text fw={600}>{formatValue(t, item.field, item.new_value)}</Text>
                        </Table.Td>
                        <Table.Td>{item.reason}</Table.Td>
                    </Table.Tr>
                ))}
            </Table.Tbody>
        </Table>
    );
};

export default EditRequestItems;
