import React, { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { LuArrowLeft, LuScanText } from 'react-icons/lu';
import { Button, Checkbox, Flex, Modal, Stack, Text } from '@mantine/core';
import { notifications } from '@mantine/notifications';
import { UserPaperItem } from '@/types/models/user_paper';
import { UserPaperTypeEnum } from '@/enums/user_paper_type.enum';
import EnumHelper from '@/helpers/enum.helper';
import PaperOcrResultView, { TYPE_SECTIONS, buildImageByType } from '@/pages/admin/user/partials/paper_ocr_result_view';
import { PaperOcrGroup, PaperOcrGroupResult, useExtractUserPapersOcrMutation } from '@/pages/admin/user/api';

type Props = {
    opened: boolean;
    onClose: () => void;
    userId: string;
    paperItems: UserPaperItem[];
};

const PaperOcrModal = ({ opened, onClose, userId, paperItems }: Props) => {
    const { t } = useTranslation();
    const mutation = useExtractUserPapersOcrMutation(userId);

    const [step, setStep] = useState<'select' | 'result'>('select');
    const [selectedTypes, setSelectedTypes] = useState<string[]>([]);
    const [results, setResults] = useState<PaperOcrGroupResult[]>([]);

    const imageByType = useMemo(() => buildImageByType(paperItems), [paperItems]);

    const eligibleTypes = useMemo(() => new Set(imageByType.keys()), [imageByType]);

    const getGroupLabel = (group: PaperOcrGroup): string => {
        switch (group) {
            case 'residence_card':
                return t('Residence card');
            case 'passport':
                return t('Passport');
            case 'student_card':
                return t('Student card');
        }
    };

    const resetAndClose = () => {
        setStep('select');
        setSelectedTypes([]);
        setResults([]);
        onClose();
    };

    const onSubmit = async (force = false) => {
        try {
            const response = await mutation.mutateAsync({ types: selectedTypes, force });
            setResults(response.data?.results ?? []);
            setStep('result');
        } catch (e) {
            notifications.show({
                color: 'red',
                message: t('error|Could not read from image'),
            });
        }
    };

    return (
        <Modal
            opened={opened}
            onClose={resetAndClose}
            title={step === 'select' ? t('Read from image') : t('Extracted information')}
            size={step === 'select' ? 'md' : '80%'}
        >
            {step === 'select' && (
                <>
                    <Text size="sm" c="dimmed" mb="md">
                        {t('Select papers to read with AI. Values are only saved when you apply them.')}
                    </Text>
                    <Checkbox.Group value={selectedTypes} onChange={setSelectedTypes}>
                        <Stack gap="lg">
                            {TYPE_SECTIONS.map((section) => (
                                <div key={section.group}>
                                    <Text fw={600} size="sm" mb={8}>
                                        {getGroupLabel(section.group)}
                                    </Text>
                                    <Stack gap={8}>
                                        {section.types.map((type) => {
                                            const eligible = eligibleTypes.has(type);
                                            return (
                                                <Checkbox
                                                    key={type}
                                                    value={type}
                                                    label={EnumHelper.getText(UserPaperTypeEnum, type)}
                                                    description={eligible ? undefined : t('No usable image')}
                                                    disabled={!eligible}
                                                />
                                            );
                                        })}
                                    </Stack>
                                </div>
                            ))}
                        </Stack>
                    </Checkbox.Group>
                    {mutation.isPending && (
                        <Text size="sm" c="dimmed" mt="md">
                            {t('Reading document images, this may take up to a minute')}
                        </Text>
                    )}
                    <Flex justify="flex-end" mt={16} gap={8}>
                        <Button variant="filled" color="gray" onClick={resetAndClose} disabled={mutation.isPending}>
                            {t('Cancel')}
                        </Button>
                        <Button
                            color="teal"
                            leftSection={<LuScanText />}
                            onClick={() => onSubmit()}
                            disabled={selectedTypes.length === 0}
                            loading={mutation.isPending}
                        >
                            {t('Read from image')}
                        </Button>
                    </Flex>
                </>
            )}
            {step === 'result' && (
                <>
                    <PaperOcrResultView
                        userId={userId}
                        paperItems={paperItems}
                        results={results}
                        onRerun={() => onSubmit(true)}
                        isRerunning={mutation.isPending}
                    />
                    <Flex justify="space-between" mt={16}>
                        <Button
                            variant="filled"
                            color="gray"
                            leftSection={<LuArrowLeft />}
                            onClick={() => setStep('select')}
                            disabled={mutation.isPending}
                        >
                            {t('Back')}
                        </Button>
                        <Button color="teal" variant="light" onClick={resetAndClose}>
                            {t('Close')}
                        </Button>
                    </Flex>
                </>
            )}
        </Modal>
    );
};

export default PaperOcrModal;
