import { useTranslation } from 'react-i18next';
import { LuTriangleAlert } from 'react-icons/lu';
import { Box, Tooltip } from '@mantine/core';

type Props = {
    absenceCount: number;
    // Lift the tooltip above floating panels (e.g. the Super Plan cell editor).
    zIndex?: number;
};

const AbsenceWarning = ({ absenceCount, zIndex }: Props) => {
    const { t } = useTranslation();

    if (absenceCount < 1) {
        return null;
    }

    return (
        <Tooltip label={t('{{count}} recent absences', { count: absenceCount })} withArrow zIndex={zIndex}>
            <Box c="yellow" style={{ display: 'flex' }}>
                <LuTriangleAlert size={16} />
            </Box>
        </Tooltip>
    );
};

export default AbsenceWarning;
