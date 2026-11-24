import React from 'react';
import { useTranslation } from 'react-i18next';
import { LuArchive, LuShieldOff } from 'react-icons/lu';
import { Flex, Text } from '@mantine/core';

const TableEmptyState = () => {
    const { t } = useTranslation();
    return (
        <Flex p="md" align="center" justify="center" direction="column">
            <LuArchive size={40} color="var(--mantine-color-gray-3)" />
            <Text c="gray.5" mt="2">
                {t('No data')}
            </Text>
        </Flex>
    );
};

export const NoPermissionState = () => {
    const { t } = useTranslation();
    return (
        <Flex p="md" align="center" justify="center" direction="column">
            <LuShieldOff size={40} color="var(--mantine-color-gray-3)" />
            <Text c="gray.5" mt="2">
                {t('You do not have permission to view this data')}
            </Text>
        </Flex>
    );
};

export default TableEmptyState;
