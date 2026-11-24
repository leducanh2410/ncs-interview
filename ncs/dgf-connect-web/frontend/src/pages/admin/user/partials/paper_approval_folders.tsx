import React, { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { LuChevronDown, LuFolderCheck } from 'react-icons/lu';
import { Badge, Flex, Paper, Text, UnstyledButton } from '@mantine/core';
import { UserPaperItem } from '@/types/models/user_paper';
import DateHelper from '@/helpers/date.helper';
import { groupPapersByApproval } from '@/pages/admin/user/partials/paper_approval_groups';
import PaperFileRow from '@/pages/admin/user/partials/paper_file_row';

type Props = {
    items: UserPaperItem[];
    confirmedApprovalAt: string | null;
    onPreview: (item: UserPaperItem) => void;
};

const noop = () => {};

const PaperApprovalFolders = ({ items, confirmedApprovalAt, onPreview }: Props) => {
    const { t } = useTranslation();
    const [expandedApprovals, setExpandedApprovals] = useState<Set<string>>(new Set());
    const groups = useMemo(() => groupPapersByApproval(items, confirmedApprovalAt), [items, confirmedApprovalAt]);

    const toggleApproval = (approvedAt: string) => {
        setExpandedApprovals((prev) => {
            const next = new Set(prev);
            if (next.has(approvedAt)) {
                next.delete(approvedAt);
            } else {
                next.add(approvedAt);
            }
            return next;
        });
    };

    return (
        <>
            {groups.map((group, index) => {
                const isExpanded = expandedApprovals.has(group.approvedAt);

                return (
                    <Paper key={group.approvedAt} withBorder radius="md" style={{ overflow: 'hidden' }}>
                        <UnstyledButton
                            w="100%"
                            px="md"
                            py="sm"
                            onClick={() => toggleApproval(group.approvedAt)}
                            style={{
                                borderBottom: isExpanded ? '1px solid var(--mantine-color-gray-2)' : undefined,
                                minHeight: 68,
                            }}
                        >
                            <Flex align="center" justify="space-between" gap="sm">
                                <Flex align="center" gap="sm" style={{ flex: 1, minWidth: 0 }}>
                                    <LuFolderCheck size={18} />
                                    <Text fw={500}>
                                        {t('Approved on {{date}}', {
                                            date: DateHelper.parseAndFormat(
                                                group.approvedAt,
                                                null,
                                                t('FORMAT_TIMESTAMP'),
                                            ),
                                        })}
                                    </Text>
                                    <Badge size="sm" color="blue" variant="light">
                                        {group.items.length}
                                    </Badge>
                                    {index === 0 && (
                                        <Badge size="sm" color="teal" variant="filled">
                                            {t('Latest approval')}
                                        </Badge>
                                    )}
                                </Flex>
                                <LuChevronDown
                                    size={16}
                                    style={{
                                        transition: 'transform 0.2s',
                                        transform: isExpanded ? 'rotate(180deg)' : undefined,
                                    }}
                                />
                            </Flex>
                        </UnstyledButton>

                        {isExpanded &&
                            group.items.map((item) => (
                                <PaperFileRow
                                    key={item.id}
                                    item={item}
                                    isSelected={false}
                                    isSelectionDisabled
                                    onContextMenu={noop}
                                    onPreview={onPreview}
                                    onRestore={noop}
                                    onToggleSelect={noop}
                                />
                            ))}
                    </Paper>
                );
            })}
        </>
    );
};

export default PaperApprovalFolders;
