import React from 'react';
import { useTranslation } from 'react-i18next';
import { LuTriangleAlert } from 'react-icons/lu';
import { Alert, List, Text } from '@mantine/core';
import { DocumentMismatch } from '@/types/models/user_paper';

/**
 * Warns the reviewer that the uploaded documents do not agree about who they belong to.
 *
 * The employee is shown the same warning while submitting and is allowed to continue, so
 * the admin is the one who decides — hence a warning rather than a block on approving.
 */
const PaperMismatchAlert = ({ mismatches }: Props) => {
    const { t } = useTranslation();

    if (!mismatches || mismatches.length === 0) {
        return null;
    }

    // Explicit keys rather than an interpolated one, so the scanner can find them
    const fieldLabel = (field: string) => {
        switch (field) {
            case 'name':
                return t('Full name');
            case 'birthday':
                return t('Date of birth');
            case 'nationality':
                return t('Nationality');
            case 'residence_number':
                // Same key the OCR result view uses for this field, so the papers screen
                // names it the same way in both places
                return t('Residence number');
            default:
                return field;
        }
    };

    const groupLabel = (group: string) => {
        switch (group) {
            case 'residence_card':
                return t('Residence card (front/back)');
            case 'residence_card_scan':
                return t('Residence card (scan)');
            case 'passport':
                return t('Passport');
            case 'student_card':
                return t('Student card');
            default:
                return group;
        }
    };

    return (
        <Alert color="red" icon={<LuTriangleAlert />} title={t('Documents do not match')} mb="md">
            <Text size="sm" mb={8}>
                {t('These documents were read as belonging to different people. Check them before approving.')}
            </Text>
            <List size="sm" spacing={4}>
                {mismatches.map((mismatch) => (
                    <List.Item key={mismatch.field}>
                        <Text size="sm" fw={500} component="span">
                            {fieldLabel(mismatch.field)}:
                        </Text>{' '}
                        {mismatch.values.map((entry, index) => (
                            <Text size="sm" component="span" key={entry.group}>
                                {index > 0 && ' / '}
                                {groupLabel(entry.group)} — {entry.value}
                            </Text>
                        ))}
                    </List.Item>
                ))}
            </List>
        </Alert>
    );
};

type Props = {
    mismatches: DocumentMismatch[] | null | undefined;
};

export default PaperMismatchAlert;
