import React, { useEffect, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { LuPlus, LuSave, LuTrash2 } from 'react-icons/lu';
import Link from 'next/link';
import {
    ActionIcon,
    Anchor,
    Badge,
    Box,
    Button,
    Flex,
    MultiSelect,
    Paper,
    Select,
    TextInput,
    Title,
} from '@mantine/core';
import { GroupDetail, GroupMember, PredicateClause, PredicateClauseField } from '@/types/group';
import RouteConfig from '@/configs/route.config';
import { GenderEnum } from '@/enums/gender.enum';
import { JapaneseLevelEnum } from '@/enums/japanese_level.enum';
import { NationalityEnum } from '@/enums/nationality.enum';
import { PrefectureEnum } from '@/enums/prefecture.enum';
import { ApiValidationError } from '@/helpers/api.helper';
import EnumHelper from '@/helpers/enum.helper';
import NotificationHelper from '@/helpers/notification.helper';
import DDataTable from '@/components/DDataTable';
import { usePermissions } from '@/hooks/usePermission';
import { useUpdateGroupMutation } from '@/pages/admin/group/api';

type Props = {
    group: GroupDetail;
    isFetching: boolean;
    onMembersPageChange: (page: number) => void;
};

const FIELD_OPTIONS: { value: PredicateClauseField; label: string }[] = [
    { value: 'prefecture', label: 'Prefecture' },
    { value: 'japanese_level', label: 'Japanese level' },
    { value: 'gender', label: 'Gender' },
    { value: 'nationality', label: 'Nationality' },
];

const GroupRuleView = ({ group, isFetching, onMembersPageChange }: Props) => {
    const { t } = useTranslation();
    const perms = usePermissions(['GROUP_VIEW', 'GROUP_EDIT']);
    const updateMutation = useUpdateGroupMutation(group.id);

    const [name, setName] = useState(group.name ?? '');
    const [predicate, setPredicate] = useState<PredicateClause[]>(group.predicate ?? []);
    const [errors, setErrors] = useState<Record<string, string>>({});

    useEffect(() => {
        setName(group.name ?? '');
        setPredicate(group.predicate ?? []);
    }, [group.id, group.name, group.predicate]);

    const fieldOptions = useMemo(
        () => FIELD_OPTIONS.map((option) => ({ value: option.value, label: t(option.label) })),
        [t],
    );

    const valueOptionsFor = (field: PredicateClauseField | undefined) => {
        switch (field) {
            case 'prefecture':
                return EnumHelper.toSelectArray(PrefectureEnum);
            case 'japanese_level':
                return EnumHelper.toSelectArray(JapaneseLevelEnum);
            case 'gender':
                return EnumHelper.toSelectArray(GenderEnum);
            case 'nationality':
                return EnumHelper.toSelectArray(NationalityEnum);
            default:
                return [];
        }
    };

    const updateClause = (index: number, partial: Partial<PredicateClause>) => {
        setPredicate((prev) =>
            prev.map((clause, i) => {
                if (i !== index) return clause;
                const next = { ...clause, ...partial };
                if (partial.field !== undefined && partial.field !== clause.field) {
                    next.values = [];
                }
                return next;
            }),
        );
    };

    const addClause = () => {
        setPredicate((prev) => [...prev, { field: 'prefecture', values: [] }]);
    };

    const removeClause = (index: number) => {
        setPredicate((prev) => prev.filter((_, i) => i !== index));
    };

    const onSave = async () => {
        setErrors({});
        const trimmedName = name.trim();
        if (!trimmedName) {
            setErrors({ name: t('validation|Please enter {{field}}', { field: t('field|name') }) });
            return;
        }
        if (predicate.length === 0) {
            setErrors({ predicate: t('At least one condition is required.') });
            return;
        }
        if (predicate.some((clause) => !clause.field || !clause.values || clause.values.length === 0)) {
            setErrors({ predicate: t('Each condition must have a field and value.') });
            return;
        }
        try {
            await updateMutation.mutateAsync({ name: trimmedName, predicate });
            NotificationHelper.showSuccess(t('Successfully updated group'));
        } catch (e) {
            if (e instanceof ApiValidationError) {
                const next: Record<string, string> = {};
                for (const key of Object.keys(e.data)) {
                    next[key] = t(`validation|${e.data[key][0]}`);
                }
                setErrors(next);
            } else {
                NotificationHelper.showError(t('error|Could not update group'));
            }
        }
    };

    return (
        <Box>
            <Paper p="md" radius="md" withBorder mt="md">
                <Flex justify="space-between" align="center">
                    <Box>
                        <Title order={3} size={18}>
                            {group.name}
                        </Title>
                        <Badge color="red" variant="light" mt={4}>
                            {t('Rule based')}
                        </Badge>
                    </Box>
                    <Badge size="lg" variant="light">
                        {t('{{count}} members', { count: group.member_count })}
                    </Badge>
                </Flex>
            </Paper>
            <Paper p="md" mt="md">
                <Title order={3} size={16} mb="md">
                    {t('Conditions')}
                </Title>
                <TextInput
                    label={t('Name')}
                    value={name}
                    onChange={(e) => setName(e.currentTarget.value)}
                    error={errors.name}
                    maxLength={255}
                    withAsterisk
                    mb="md"
                    readOnly={!perms.GROUP_EDIT}
                />
                {predicate.length === 0 && (
                    <Box c="dimmed" mb="sm">
                        {t('No conditions added yet.')}
                    </Box>
                )}
                {predicate.map((clause, index) => {
                    const valueOptions = valueOptionsFor(clause.field);
                    return (
                        <Flex key={index} gap="sm" align="flex-start" mb="sm">
                            <Select
                                label={index === 0 ? t('Field') : undefined}
                                data={fieldOptions}
                                value={clause.field}
                                onChange={(value) =>
                                    updateClause(index, { field: (value as PredicateClauseField) ?? clause.field })
                                }
                                allowDeselect={false}
                                disabled={!perms.GROUP_EDIT}
                                style={{ flex: 1 }}
                            />
                            <MultiSelect
                                label={index === 0 ? t('Values') : undefined}
                                data={valueOptions}
                                value={clause.values}
                                onChange={(values) => updateClause(index, { values })}
                                placeholder={t('Select values')}
                                searchable={clause.field === 'prefecture'}
                                disabled={!perms.GROUP_EDIT}
                                style={{ flex: 1 }}
                            />
                            <ActionIcon
                                variant="subtle"
                                color="red"
                                mt={index === 0 ? 26 : 0}
                                onClick={() => removeClause(index)}
                                disabled={!perms.GROUP_EDIT}
                            >
                                <LuTrash2 size={16} />
                            </ActionIcon>
                        </Flex>
                    );
                })}
                {errors.predicate && (
                    <Box c="red" fz="xs" mb="sm">
                        {errors.predicate}
                    </Box>
                )}
                <Flex gap="sm" mt="md">
                    <Button
                        variant="default"
                        leftSection={<LuPlus size={14} />}
                        onClick={addClause}
                        disabled={!perms.GROUP_EDIT}
                    >
                        {t('Add condition')}
                    </Button>
                    <Button
                        leftSection={<LuSave size={14} />}
                        onClick={onSave}
                        loading={updateMutation.isPending}
                        disabled={!perms.GROUP_EDIT}
                    >
                        {t('Save')}
                    </Button>
                </Flex>
            </Paper>
            <Paper p="md" mt="md">
                <Title order={3} size={16} mb="md">
                    {t('Members')}
                </Title>
                <DDataTable
                    columns={[
                        { name: 'ID', key: 'id', align: 'center', width: 80 },
                        {
                            name: t('Staff number'),
                            key: 'staff_no',
                            render: (member: GroupMember) => member.staff_no || '-',
                        },
                        {
                            name: t('Name'),
                            key: 'name',
                            render: (member: GroupMember) => (
                                <Anchor
                                    component={Link}
                                    href={RouteConfig.ADMIN_USER_DETAIL.path.replace('[id]', member.id.toString())}
                                >
                                    {member.name}
                                </Anchor>
                            ),
                        },
                        {
                            name: t('Workplace'),
                            key: 'current_workplaces',
                            render: (member: GroupMember) =>
                                member.current_workplaces.length
                                    ? member.current_workplaces.map((w) => w.name).join(', ')
                                    : '-',
                        },
                    ]}
                    data={group.members}
                    isFetching={isFetching}
                    showPageSize={false}
                    onPageChanged={onMembersPageChange}
                />
            </Paper>
        </Box>
    );
};

export default GroupRuleView;
