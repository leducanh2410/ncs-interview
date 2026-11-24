import React from 'react';
import { useTranslation } from 'react-i18next';
import { Flex, Group, Stack, Text } from '@mantine/core';
import DateHelper, { DATE_STORE_FORMAT_WITH_TIME } from '@/helpers/date.helper';
import TranslatableHelper from '@/helpers/translatable.helper';
import ExamStatusBadge from '@/components/ExamStatusBadge';

const InfoExam = ({ exam }: { exam: any }) => {
    const { t, i18n } = useTranslation();
    const isExternalFormPrep = exam.type === 'external_form_prep';
    return (
        <Flex gap="md">
            <Stack flex={1} gap={0}>
                <Group px="sm" py="xs" bg="gray.2">
                    <Text size="sm" w={150}>
                        {t('Exam name')}
                    </Text>
                    <Text size="sm">{TranslatableHelper.resolve(exam.title, i18n.language)}</Text>
                </Group>
                <Group px="sm" py="xs">
                    <Text size="sm" w={150}>
                        {t('Exam type')}
                    </Text>
                    <Text size="sm">
                        {isExternalFormPrep ? t('External form preparation') : t('Knowledge assessment')}
                    </Text>
                </Group>
                <Group px="sm" py="xs" bg="gray.2">
                    <Text size="sm" w={150}>
                        {t('Status')}
                    </Text>
                    <ExamStatusBadge exam={exam} />
                </Group>
                <Group px="sm" py="xs">
                    <Text size="sm" w={150}>
                        {t('Required')}
                    </Text>
                    <Text size="sm">{exam.is_required ? t('Yes') : t('No')}</Text>
                </Group>
                <Group px="sm" py="xs" bg="gray.2">
                    <Text size="sm" w={150}>
                        {t('Start date')}
                    </Text>
                    <Text size="sm">
                        {DateHelper.parseAndFormat(
                            exam.start_at,
                            DATE_STORE_FORMAT_WITH_TIME,
                            t('FORMAT_DATE_WITH_TIME'),
                        )}
                    </Text>
                </Group>
                <Group px="sm" py="xs">
                    <Text size="sm" w={150}>
                        {t('End date')}
                    </Text>
                    <Text size="sm">
                        {DateHelper.parseAndFormat(
                            exam.end_at,
                            DATE_STORE_FORMAT_WITH_TIME,
                            t('FORMAT_DATE_WITH_TIME'),
                        )}
                    </Text>
                </Group>
            </Stack>
            <Stack flex={1} gap={0}>
                {isExternalFormPrep ? (
                    <Group px="sm" py="xs" bg="gray.2">
                        <Text size="sm" w={150}>
                            {t('Google Form link')}
                        </Text>
                        <Text size="sm" style={{ wordBreak: 'break-all' }}>
                            {TranslatableHelper.resolve(exam.external_url, i18n.language) || '-'}
                        </Text>
                    </Group>
                ) : (
                    <>
                        <Group px="sm" py="xs" bg="gray.2">
                            <Text size="sm" w={150}>
                                {t('Retry limit')}
                            </Text>
                            <Text size="sm">{exam.retry_limit}</Text>
                        </Group>
                        <Group px="sm" py="xs">
                            <Text size="sm" w={150}>
                                {t('Min score')}
                            </Text>
                            <Text size="sm">{exam.min_score}</Text>
                        </Group>
                        <Group px="sm" py="xs" bg="gray.2">
                            <Text size="sm" w={150}>
                                {t('Time limit')}
                            </Text>
                            <Text size="sm">{exam.time_limit}</Text>
                        </Group>
                    </>
                )}
            </Stack>
        </Flex>
    );
};

export default InfoExam;
