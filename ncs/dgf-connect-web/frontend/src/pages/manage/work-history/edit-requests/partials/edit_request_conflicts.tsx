import { useTranslation } from 'react-i18next';
import { Alert, Table, Text } from '@mantine/core';
import { WorkHistoryEditRequestConflict } from '@/types/models/work_history_edit_request';
import { fieldLabel, formatValue } from '@/pages/manage/work-history/edit-requests/partials/edit_request_items';

const EditRequestConflicts = ({ conflicts }: { conflicts: WorkHistoryEditRequestConflict[] }) => {
    const { t } = useTranslation();

    return (
        <Alert color="orange" mb="md" title={t('error|The check-in data has changed since this request was submitted')}>
            <Text size="sm" mb="sm">
                {t(
                    'Review the values below, then apply the requested values anyway or reject the request and submit a new one.',
                )}
            </Text>
            <Table>
                <Table.Thead>
                    <Table.Tr>
                        <Table.Th>{t('Field')}</Table.Th>
                        <Table.Th>{t('Value when submitted')}</Table.Th>
                        <Table.Th>{t('Actual value now')}</Table.Th>
                        <Table.Th>{t('Requested value')}</Table.Th>
                    </Table.Tr>
                </Table.Thead>
                <Table.Tbody>
                    {conflicts.map((conflict) => (
                        <Table.Tr key={conflict.field}>
                            <Table.Td>{fieldLabel(t, conflict.field)}</Table.Td>
                            <Table.Td>{formatValue(t, conflict.field, conflict.old_value)}</Table.Td>
                            <Table.Td>
                                <Text fw={600} c="orange">
                                    {formatValue(t, conflict.field, conflict.current_value)}
                                </Text>
                            </Table.Td>
                            <Table.Td>
                                <Text fw={600}>{formatValue(t, conflict.field, conflict.new_value)}</Text>
                            </Table.Td>
                        </Table.Tr>
                    ))}
                </Table.Tbody>
            </Table>
        </Alert>
    );
};

export default EditRequestConflicts;
