import React from 'react';
import { useTranslation } from 'react-i18next';
import { LuTriangleAlert } from 'react-icons/lu';
import { Alert, Button, Flex, Text } from '@mantine/core';

/**
 * A 留学 file that has a student card but no expiry date for it.
 *
 * The status only permits work while the student is enrolled, and the shift checks read
 * that date to refuse anyone whose enrolment has lapsed. Approving the card without it
 * would hand out a file those checks cannot test, so the backend refuses it — this says so
 * before the admin opens the approve modal and is turned away by it.
 *
 * An employee with no student card at all is not shown this: they owe a document, not a
 * date, and the missing-required-documents warning already covers that.
 *
 * `onRequestCompletion` is the second way out, for a date the admin cannot read off the
 * images: the employee is asked to file the card again, and the form that upload forces is
 * the only other place the date is ever written.
 *
 * Both ways out are named in the text, because the alert is also shown inside the approve
 * modal — where the Submit button is disabled and neither button is offered, since a file
 * under review cannot be sent back. An admin told only that the date is missing has no way
 * to guess that the field lives on another tab, and the refusal the backend would return
 * never reaches them: the button that would send the request is the one that is disabled.
 */
const PaperStudentCardExpiryAlert = ({ missing, canRequestCompletion, onRequestCompletion }: Props) => {
    const { t } = useTranslation();

    if (!missing) {
        return null;
    }

    return (
        <Alert color="red" icon={<LuTriangleAlert />} title={t('Student card expiry date is missing')} mb="md">
            <Text size="sm">{t('The student card is on file but its expiry date has not been recorded.')}</Text>
            <Text size="sm" mt={4}>
                {t(
                    'Enter it in Student card expiry date on the Information tab, or apply it from the OCR result, and this file can be approved.',
                )}
            </Text>
            {canRequestCompletion && onRequestCompletion && (
                <Flex justify="flex-start" mt={12}>
                    <Button size="xs" variant="filled" color="red" onClick={onRequestCompletion}>
                        {t('Ask for the student card again')}
                    </Button>
                </Flex>
            )}
        </Alert>
    );
};

type Props = {
    missing: boolean | null | undefined;
    /** Only an approved file can be sent back; a file under review belongs to the approve modal */
    canRequestCompletion?: boolean;
    onRequestCompletion?: () => void;
};

export default PaperStudentCardExpiryAlert;
