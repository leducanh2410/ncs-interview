import React, { KeyboardEvent, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useRouter } from 'next/router';
import { Button, Center, Flex, Pagination, Select, Text, TextInput } from '@mantine/core';
import { PaginatedData } from '@/types/api';

interface Props {
    data: PaginatedData<any>;
    showPageSize?: boolean;
    onPageChanged?: (page: number) => void;
}

const PAGE_SIZE_OPTIONS = [
    { value: '15', label: '15' },
    { value: '20', label: '20' },
    { value: '30', label: '30' },
    { value: '50', label: '50' },
    { value: '100', label: '100' },
];

export default function DPagination({ data, showPageSize = true, onPageChanged }: Props) {
    const { push, query } = useRouter();
    const { t } = useTranslation();
    const [goToPageInput, setGoToPageInput] = useState(String(data.current_page));

    const handlePageChange = (page: number) => {
        push({ query: { ...query, page } }).catch(console.error);
    };

    const handleInputKeyPress = (e: KeyboardEvent<HTMLInputElement>) => {
        if (e.key === 'Enter') {
            const page = parseInt(goToPageInput);
            if (onPageChanged) {
                onPageChanged(page);
            } else {
                handlePageChange(parseInt(goToPageInput));
            }
        }
    };

    const to = Math.min(data.current_page * data.per_page, data.total);
    const from = Math.min((data.current_page - 1) * data.per_page + 1, to);

    return (
        <Center mt="md">
            <Flex w="100%" justify="space-between">
                <Flex align="center" gap="xs">
                    <Text>
                        {from} - {to} / {data.total}
                    </Text>
                    {showPageSize && (
                        <>
                            <Select
                                style={{ width: 80 }}
                                value={String(data.per_page)}
                                onChange={(pageSize) => {
                                    delete query.page;
                                    push({
                                        query: {
                                            ...query,
                                            page_size: parseInt(pageSize || String(data.per_page)),
                                        },
                                    }).catch(console.error);
                                }}
                                data={PAGE_SIZE_OPTIONS}
                            />
                            <Text>
                                {t('Records')} / {t('Page')}
                            </Text>
                        </>
                    )}
                </Flex>
                <Flex align="center" gap="xs">
                    <Pagination
                        total={data.last_page}
                        value={data.current_page}
                        onChange={(page) => {
                            if (onPageChanged) {
                                onPageChanged(page);
                            } else {
                                handlePageChange(page);
                            }
                        }}
                    />
                    <Text ml="sm">{t('Go to page')}</Text>
                    <TextInput
                        w="60"
                        value={goToPageInput}
                        onChange={(e) => setGoToPageInput(e.currentTarget.value)}
                        onKeyDown={handleInputKeyPress}
                        type="number"
                    />
                    <Button
                        onClick={() => {
                            const page = parseInt(goToPageInput);
                            if (onPageChanged) {
                                onPageChanged(page);
                            } else {
                                handlePageChange(page);
                            }
                        }}
                    >
                        {t('Go')}
                    </Button>
                </Flex>
            </Flex>
        </Center>
    );
}
