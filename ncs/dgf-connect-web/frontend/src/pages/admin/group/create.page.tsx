import React, { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { LuPlus, LuSave, LuTrash2 } from 'react-icons/lu';
import Link from 'next/link';
import { useRouter } from 'next/router';
import {
    ActionIcon,
    Anchor,
    Box,
    Breadcrumbs,
    Button,
    Flex,
    MultiSelect,
    Paper,
    Select,
    Text,
    TextInput,
    Title,
} from '@mantine/core';
import { CreateGroupPayload, CreateRuleBasedGroupPayload, PredicateClause, PredicateClauseField } from '@/types/group';
import RouteConfig from '@/configs/route.config';
import { GenderEnum } from '@/enums/gender.enum';
import { JapaneseLevelEnum } from '@/enums/japanese_level.enum';
import { NationalityEnum } from '@/enums/nationality.enum';
import { PrefectureEnum } from '@/enums/prefecture.enum';
import { ApiValidationError } from '@/helpers/api.helper';
import EnumHelper from '@/helpers/enum.helper';
import NotificationHelper from '@/helpers/notification.helper';
import { useCreateGroupMutation } from '@/pages/admin/group/api';

type Kind = 'manual' | 'rule_based';

const FIELD_OPTIONS: { value: PredicateClauseField; label: string }[] = [
    { value: 'prefecture', label: 'Prefecture' },
    { value: 'japanese_level', label: 'Japanese level' },
    { value: 'gender', label: 'Gender' },
    { value: 'nationality', label: 'Nationality' },
];

const CreateGroupPage = () => {
    const { t } = useTranslation();
    const router = useRouter();
    const createMutation = useCreateGroupMutation();

    const [kind, setKind] = useState<Kind>('manual');
    const [name, setName] = useState('');
    const [predicate, setPredicate] = useState<PredicateClause[]>([{ field: 'prefecture', values: [] }]);
    const [errors, setErrors] = useState<Record<string, string>>({});

    const fieldOptions = FIELD_OPTIONS.map((option) => ({ value: option.value, label: t(option.label) }));

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

    const addClause = () => setPredicate((prev) => [...prev, { field: 'prefecture', values: [] }]);
    const removeClause = (index: number) => setPredicate((prev) => prev.filter((_, i) => i !== index));

    const onSubmit = async () => {
        setErrors({});
        const trimmedName = name.trim();
        if (!trimmedName) {
            setErrors({ name: t('validation|Please enter {{field}}', { field: t('field|name') }) });
            return;
        }

        let payload: CreateGroupPayload;
        if (kind === 'manual') {
            payload = { kind: 'manual', name: trimmedName };
        } else {
            if (predicate.length === 0 || predicate.some((c) => !c.field || !c.values || c.values.length === 0)) {
                setErrors({ predicate: t('Each condition must have a field and value.') });
                return;
            }
            payload = { kind: 'rule_based', name: trimmedName, predicate } satisfies CreateRuleBasedGroupPayload;
        }

        try {
            const response = await createMutation.mutateAsync(payload);
            NotificationHelper.showSuccess(t('Successfully created group'));
            const newId = response.data?.id;
            if (newId) {
                await router.push(RouteConfig.ADMIN_GROUP_DETAIL.path.replace('[id]', newId.toString()));
            } else {
                await router.push(RouteConfig.ADMIN_GROUP_LIST.path);
            }
        } catch (e) {
            if (e instanceof ApiValidationError) {
                const next: Record<string, string> = {};
                for (const key of Object.keys(e.data)) {
                    next[key] = t(`validation|${e.data[key][0]}`);
                }
                setErrors(next);
            } else {
                NotificationHelper.showError(t('error|Could not create group'));
            }
        }
    };

    return (
        <>
            <Breadcrumbs>
                <Anchor component={Link} href={RouteConfig.HOME.path}>
                    {t('Home')}
                </Anchor>
                <Anchor component={Link} href={RouteConfig.ADMIN_GROUP_LIST.path}>
                    {t('Groups')}
                </Anchor>
                <Text>{t('Create group')}</Text>
            </Breadcrumbs>
            <Flex justify="space-between" align="center">
                <Title order={4} lh="48px" my="md">
                    {t('Create group')}
                </Title>
            </Flex>
            <Paper p="md" mt="md" radius="md" withBorder>
                <Select
                    label={t('Kind')}
                    data={[
                        { value: 'manual', label: t('Manual') },
                        { value: 'rule_based', label: t('Rule based') },
                    ]}
                    value={kind}
                    onChange={(value) => setKind((value as Kind) ?? 'manual')}
                    allowDeselect={false}
                    mb="md"
                />
                <TextInput
                    label={t('Name')}
                    value={name}
                    onChange={(e) => setName(e.currentTarget.value)}
                    error={errors.name}
                    maxLength={255}
                    withAsterisk
                    mb="md"
                />
                {kind === 'rule_based' && (
                    <Box>
                        <Text fw={500} mb="xs">
                            {t('Conditions')}
                        </Text>
                        {predicate.map((clause, index) => (
                            <Flex key={index} gap="sm" align="flex-start" mb="sm">
                                <Select
                                    label={index === 0 ? t('Field') : undefined}
                                    data={fieldOptions}
                                    value={clause.field}
                                    onChange={(value) =>
                                        updateClause(index, {
                                            field: (value as PredicateClauseField) ?? clause.field,
                                        })
                                    }
                                    allowDeselect={false}
                                    style={{ flex: 1 }}
                                />
                                <MultiSelect
                                    label={index === 0 ? t('Values') : undefined}
                                    data={valueOptionsFor(clause.field)}
                                    value={clause.values}
                                    onChange={(values) => updateClause(index, { values })}
                                    placeholder={t('Select values')}
                                    searchable={clause.field === 'prefecture'}
                                    style={{ flex: 1 }}
                                />
                                <ActionIcon
                                    variant="subtle"
                                    color="red"
                                    mt={index === 0 ? 26 : 0}
                                    onClick={() => removeClause(index)}
                                    disabled={predicate.length <= 1}
                                >
                                    <LuTrash2 size={16} />
                                </ActionIcon>
                            </Flex>
                        ))}
                        {errors.predicate && (
                            <Box c="red" fz="xs" mb="sm">
                                {errors.predicate}
                            </Box>
                        )}
                        <Button variant="default" leftSection={<LuPlus size={14} />} onClick={addClause} mb="md">
                            {t('Add condition')}
                        </Button>
                    </Box>
                )}
                <Flex justify="end" gap="sm">
                    <Button variant="default" component={Link} href={RouteConfig.ADMIN_GROUP_LIST.path}>
                        {t('Cancel')}
                    </Button>
                    <Button leftSection={<LuSave size={14} />} onClick={onSubmit} loading={createMutation.isPending}>
                        {t('Save')}
                    </Button>
                </Flex>
            </Paper>
        </>
    );
};

export default CreateGroupPage;
