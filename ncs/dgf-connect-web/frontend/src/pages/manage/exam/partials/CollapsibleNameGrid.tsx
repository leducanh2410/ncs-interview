import React, { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { LuChevronDown, LuChevronUp } from 'react-icons/lu';
import { Badge, Button, Group, Paper, SimpleGrid, Text, Title } from '@mantine/core';

const PREVIEW_COUNT = 8;

type NamedItem = {
    id: number;
    name: string;
};

const CollapsibleNameGrid = ({ title, emptyText, items }: { title: string; emptyText: string; items: NamedItem[] }) => {
    const { t } = useTranslation();
    const [expanded, setExpanded] = useState(false);

    const hiddenCount = items.length - PREVIEW_COUNT;
    const visibleItems = expanded ? items : items.slice(0, PREVIEW_COUNT);

    return (
        <>
            <Group gap="xs" mb="sm">
                <Title order={5}>{title}</Title>
                {items.length > 0 && (
                    <Badge variant="light" radius="sm">
                        {items.length}
                    </Badge>
                )}
            </Group>

            {items.length === 0 ? (
                <Text c="dimmed">{emptyText}</Text>
            ) : (
                <>
                    <SimpleGrid cols={{ base: 1, sm: 2, md: 3, xl: 4 }} spacing="sm">
                        {visibleItems.map((item) => (
                            <Paper key={item.id} withBorder radius="md" shadow="xs" px="sm" py="xs">
                                <Text size="sm" fw={500} lineClamp={2} title={item.name}>
                                    {item.name}
                                </Text>
                            </Paper>
                        ))}
                    </SimpleGrid>

                    {hiddenCount > 0 && (
                        <Button
                            variant="subtle"
                            size="xs"
                            mt="xs"
                            leftSection={expanded ? <LuChevronUp /> : <LuChevronDown />}
                            onClick={() => setExpanded((value) => !value)}
                        >
                            {expanded ? t('Collapse') : t('See more (+{{count}})', { count: hiddenCount })}
                        </Button>
                    )}
                </>
            )}
        </>
    );
};

export default CollapsibleNameGrid;
