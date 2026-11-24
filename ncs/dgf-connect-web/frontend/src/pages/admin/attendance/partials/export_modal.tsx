import React, { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { LuCalendar, LuFileSpreadsheet } from 'react-icons/lu';
import { Button, Flex, Modal } from '@mantine/core';
import { MonthPickerInput } from '@mantine/dates';
import DateHelper, { DATE_STORE_FORMAT, DATE_STORE_FORMAT_MONTH } from '@/helpers/date.helper';
import NotificationHelper from '@/helpers/notification.helper';
import { DUserSelectRemoteMultiple } from '@/components/DUserSelectRemote';
import { ATTENDANCE_ADMIN_PERMISSIONS } from '@/pages/admin/attendance/constants';
import { exportMonthlyAttendance } from '@/pages/admin/attendance/api';

type Props = {
    opened: boolean;
    onClose: () => void;
    defaultUserIds?: string[];
};

const AttendanceExportModal = ({ opened, onClose, defaultUserIds }: Props) => {
    const { t } = useTranslation();
    const [month, setMonth] = useState<string | null>(DateHelper.now().format(DATE_STORE_FORMAT));
    const [userIds, setUserIds] = useState<string[]>(defaultUserIds ?? []);
    const [isExporting, setIsExporting] = useState(false);

    useEffect(() => {
        if (opened) {
            setUserIds(defaultUserIds ?? []);
        }
    }, [opened, defaultUserIds]);

    const onExport = async () => {
        if (!month) {
            return;
        }

        const monthParam = DateHelper.parseAndFormat(month, DATE_STORE_FORMAT, DATE_STORE_FORMAT_MONTH, '');
        if (!monthParam) {
            return;
        }

        setIsExporting(true);
        try {
            await exportMonthlyAttendance(monthParam, userIds);
            onClose();
        } catch (e) {
            NotificationHelper.showError(t('error|Could not export admin attendance report'));
        } finally {
            setIsExporting(false);
        }
    };

    return (
        <Modal opened={opened} onClose={onClose} title={t('Export attendance')}>
            <Flex direction="column" gap="md">
                <MonthPickerInput
                    label={t('Month')}
                    valueFormat={t('FORMAT_MONTH')}
                    rightSection={<LuCalendar size={16} />}
                    value={month}
                    onChange={setMonth}
                />
                <DUserSelectRemoteMultiple
                    label={t('Admin')}
                    placeholder={userIds.length ? undefined : t('All admins')}
                    extraParams={{ permission: ATTENDANCE_ADMIN_PERMISSIONS }}
                    clearable
                    value={userIds}
                    onChange={setUserIds}
                />
                <Flex justify="flex-end" gap="8">
                    <Button variant="default" onClick={onClose}>
                        {t('Cancel')}
                    </Button>
                    <Button
                        leftSection={<LuFileSpreadsheet />}
                        loading={isExporting}
                        disabled={!month}
                        onClick={onExport}
                    >
                        {t('Export excel')}
                    </Button>
                </Flex>
            </Flex>
        </Modal>
    );
};

export default AttendanceExportModal;
