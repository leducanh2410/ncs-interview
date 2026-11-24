import React, { RefObject, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { LuUpload } from 'react-icons/lu';
import { Button, FileButton, Modal, Select, Stack, Switch } from '@mantine/core';
import { EncodingEnum } from '@/enums/encoding.enum';
import EnumHelper from '@/helpers/enum.helper';

const ModalImportCSV = ({
    isShow,
    onClose,
    encoding,
    setEncoding,
    isButtonLoading,
    onShiftCsvUpload,
    fileInputRef,
}: Props) => {
    const { t } = useTranslation();
    const [isFixed, setIsFixed] = useState(false);

    const handleClose = () => {
        setIsFixed(false);
        onClose();
    };

    return (
        <Modal opened={isShow} onClose={handleClose} title={t('Import CSV')}>
            <Stack gap="sm">
                <Select
                    value={encoding}
                    label={t('Encoding Select')}
                    data={EnumHelper.toSelectArray(EncodingEnum)}
                    onChange={(value) => {
                        setEncoding(value as string);
                    }}
                />
                <Switch
                    label={isFixed ? t('Fixed') : t('Temp')}
                    checked={isFixed}
                    onChange={(e) => setIsFixed(e.currentTarget.checked)}
                />
                <FileButton resetRef={fileInputRef} onChange={(file) => onShiftCsvUpload(file, isFixed)} accept=".csv">
                    {(props) => (
                        <Button {...props} color="yellow" leftSection={<LuUpload />} loading={isButtonLoading}>
                            {t('Import CSV')}
                        </Button>
                    )}
                </FileButton>
            </Stack>
        </Modal>
    );
};

type Props = {
    isShow: boolean;
    onClose: () => void;
    encoding: string;
    setEncoding: (value: string) => void;
    isButtonLoading: boolean;
    onShiftCsvUpload: (file: File | null, isFixed: boolean) => void;
    fileInputRef: RefObject<() => void>;
};
export default ModalImportCSV;
