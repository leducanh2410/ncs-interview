import React from 'react';
import { useTranslation } from 'react-i18next';
import { Badge, Table, Text } from '@mantine/core';
import { PaperFieldComparison } from '@/types/models/paper_reconciliation';
import { GenderEnum } from '@/enums/gender.enum';
import { NationalityEnum } from '@/enums/nationality.enum';
import { PrefectureEnum } from '@/enums/prefecture.enum';
import { ResidenceStatusEnum } from '@/enums/residence_status.enum';
import DateHelper from '@/helpers/date.helper';
import EnumHelper from '@/helpers/enum.helper';

const FIELD_LABELS: Record<string, string> = {
    name: 'Name',
    birthday: 'Date of birth',
    gender: 'Gender',
    nationality: 'Nationality',
    residence_number: 'Residence card number',
    residence_expiry_date: 'Residence card expiry',
    residence_status: 'Residence status',
    last_name_kana: 'Last name (kana)',
    first_name_kana: 'First name (kana)',
    address: 'Address',
    prefecture: 'Prefecture',
    residence_status_raw: 'Residence status as printed',
};

const DATE_FIELDS = ['birthday', 'residence_expiry_date'];

/**
 * Both sides arrive as stored values — `permanent_resident`, `tokyo`, an ISO date — so
 * rendering them raw prints the enum key at the reader. Falls back to the value itself
 * for anything the enum does not know, which is what an unmapped OCR read looks like.
 */
const useFieldValue = () => {
    const { t } = useTranslation();

    return (field: string, value: string | null): React.ReactNode => {
        if (value === null || value === '') return null;
        if (field === 'gender') return EnumHelper.getText(GenderEnum, value) || value;
        if (field === 'nationality') return EnumHelper.getText(NationalityEnum, value) || value;
        if (field === 'residence_status') return EnumHelper.getText(ResidenceStatusEnum, value) || value;
        if (field === 'prefecture') return EnumHelper.getText(PrefectureEnum, value) || value;
        if (DATE_FIELDS.includes(field)) return DateHelper.parseAndFormat(value, null, t('FORMAT_DATE'), value);

        return value;
    };
};

/**
 * A Mantine badge clips its label to one line by default, which cut "Cannot compare"
 * short in every locale whose wording is longer than English. The verdict is the column
 * the table is read for, so it wraps onto a second line rather than being trimmed.
 */
const VERDICT_BADGE_STYLES = {
    root: { height: 'auto' },
    label: { whiteSpace: 'normal', overflow: 'visible' },
} as const;

const FieldVerdictMark = ({ verdict }: { verdict: PaperFieldComparison['verdict'] }) => {
    const { t } = useTranslation();

    if (verdict === null) return <Text c="dimmed">—</Text>;
    if (verdict === 'match') {
        return (
            <Badge color="green" variant="light" styles={VERDICT_BADGE_STYLES}>
                {t('Matches')}
            </Badge>
        );
    }
    if (verdict === 'mismatch') {
        return (
            <Badge color="red" variant="light" styles={VERDICT_BADGE_STYLES}>
                {t('Differs')}
            </Badge>
        );
    }

    // Never styled like a match: a blank value says nothing about agreement.
    return (
        <Badge color="gray" variant="light" styles={VERDICT_BADGE_STYLES}>
            {t('Cannot compare')}
        </Badge>
    );
};

/**
 * Three shades, in priority order: a disagreement, a reference row that can never
 * disagree, and a judged row that agreed. Without the middle one a reviewer has to read
 * the caption under each label to tell which rows their eye should be on.
 */
const rowBackground = (field: PaperFieldComparison): string | undefined => {
    if (field.verdict === 'mismatch') return 'var(--mantine-color-red-0)';
    if (!field.compared) return 'var(--mantine-color-gray-1)';

    return undefined;
};

const FieldComparisonTable = ({ fields }: { fields: PaperFieldComparison[] }) => {
    const { t } = useTranslation();
    const fieldValue = useFieldValue();

    return (
        <Table withTableBorder highlightOnHover>
            <Table.Thead>
                <Table.Tr>
                    <Table.Th>{t('Field')}</Table.Th>
                    <Table.Th>{t('On file')}</Table.Th>
                    <Table.Th>{t('Scanned by admin')}</Table.Th>
                    {/* Wide enough for the longest verdict plus the superseded-card note
                        that sits under it, in every locale. */}
                    <Table.Th w={220}>{t('Result')}</Table.Th>
                </Table.Tr>
            </Table.Thead>
            <Table.Tbody>
                {fields.map((field) => (
                    <Table.Tr key={field.field} bg={rowBackground(field)}>
                        <Table.Td>
                            {/* The judged fields carry the weight; the reference rows step
                                back so the eye lands on what can actually disagree. */}
                            <Text fw={field.compared ? 600 : 400} c={field.compared ? undefined : 'dimmed'}>
                                {t(FIELD_LABELS[field.field] ?? field.field)}
                            </Text>
                            {!field.compared && (
                                <Text size="xs" c="dimmed">
                                    {t('Shown for reference, never compared')}
                                </Text>
                            )}
                        </Table.Td>
                        <Table.Td c={field.compared ? undefined : 'dimmed'}>
                            {fieldValue(field.field, field.employee) ?? <Text c="dimmed">—</Text>}
                        </Table.Td>
                        <Table.Td c={field.compared ? undefined : 'dimmed'}>
                            {fieldValue(field.field, field.admin) ?? <Text c="dimmed">—</Text>}
                        </Table.Td>
                        <Table.Td>
                            <FieldVerdictMark verdict={field.verdict} />
                            {field.matches_previous_slot ? (
                                <Text size="xs" c="orange.8" mt={4}>
                                    {t(
                                        'This card is one the record has already replaced (card {{slot}}), not a card the record has never seen',
                                        { slot: field.matches_previous_slot },
                                    )}
                                </Text>
                            ) : null}
                        </Table.Td>
                    </Table.Tr>
                ))}
            </Table.Tbody>
        </Table>
    );
};

export default FieldComparisonTable;
