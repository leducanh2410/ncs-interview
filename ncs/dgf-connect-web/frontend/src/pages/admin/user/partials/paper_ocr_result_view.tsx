import React, { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { LuCheck, LuRefreshCw } from 'react-icons/lu';
import { Anchor, Badge, Button, Checkbox, Flex, Image, Stack, Table, Tabs, Text } from '@mantine/core';
import { notifications } from '@mantine/notifications';
import { UserPaperItem } from '@/types/models/user_paper';
import { GenderEnum } from '@/enums/gender.enum';
import { NationalityEnum } from '@/enums/nationality.enum';
import { ResidenceStatusEnum } from '@/enums/residence_status.enum';
import { UserPaperTypeEnum } from '@/enums/user_paper_type.enum';
import DateHelper from '@/helpers/date.helper';
import EnumHelper from '@/helpers/enum.helper';
import UrlHelper from '@/helpers/url.helper';
import DAlert from '@/components/DAlert';
import { OcrCompareStatus, buildCompareRows } from '@/pages/admin/user/partials/paper_ocr_compare';
import { usePermissions } from '@/hooks/usePermission';
import {
    PaperOcrGroup,
    PaperOcrGroupResult,
    useApplyUserPapersOcrMutation,
    useGetUserQuery,
} from '@/pages/admin/user/api';
import { getOcrWarningLabel } from '@/utils/ocr_warning';

export const TYPE_SECTIONS: Array<{ group: PaperOcrGroup; types: string[] }> = [
    {
        group: 'residence_card',
        types: [
            UserPaperTypeEnum.FOREIGNER_CARD_FRONT.value,
            UserPaperTypeEnum.FOREIGNER_CARD_BACK.value,
            UserPaperTypeEnum.FOREIGNER_CARD_SCAN.value,
        ],
    },
    {
        group: 'passport',
        types: [UserPaperTypeEnum.PASSPORT.value],
    },
    {
        group: 'student_card',
        types: [UserPaperTypeEnum.STUDENT_CARD_FRONT.value, UserPaperTypeEnum.STUDENT_CARD_BACK.value],
    },
];

export const OCR_ELIGIBLE_TYPES: string[] = TYPE_SECTIONS.flatMap((section) => section.types);

const DATE_FIELDS = ['birthday', 'residence_expiry_date', 'passport_expiry_date', 'student_card_expiry_date'];

// Extracted fields the backend accepts for apply; the combined name cannot be split reliably,
// but the kana names arrive already split from the extractor
const APPLICABLE_FIELDS: Record<PaperOcrGroup, string[]> = {
    residence_card: [
        'last_name_kana',
        'first_name_kana',
        'birthday',
        'gender',
        'nationality',
        'address',
        'postal_code',
        'prefecture',
        'residence_number',
        'residence_expiry_date',
        'residence_status',
    ],
    passport: ['passport_number', 'passport_expiry_date'],
    student_card: ['student_card_expiry_date'],
};

// Newest usable image per type (mirrors the backend selection):
// a still-pending upload wins over the newest approved image in the current folder
export const buildImageByType = (paperItems: UserPaperItem[]): Map<string, UserPaperItem> => {
    const newestByType = (predicate: (item: UserPaperItem) => boolean) => {
        const map = new Map<string, UserPaperItem>();
        paperItems
            .filter((item) => !item.deleted_at && predicate(item))
            .forEach((item) => {
                const existing = map.get(item.type);
                if (!existing || item.id > existing.id) {
                    map.set(item.type, item);
                }
            });
        return map;
    };
    const pending = newestByType((item) => !item.approved_at && !item.rejected_at);
    const approved = newestByType(
        (item) => item.folder === 'current' && Boolean(item.approved_at) && !item.rejected_at,
    );
    return new Map([...approved, ...pending]);
};

type Props = {
    userId: string;
    paperItems: UserPaperItem[];
    results: PaperOcrGroupResult[];
    onRerun?: () => void;
    isRerunning?: boolean;
};

const PaperOcrResultView = ({ userId, paperItems, results, onRerun, isRerunning = false }: Props) => {
    const { t } = useTranslation();
    const perms = usePermissions(['USER_PAPER_APPROVE']);
    const { data: userData } = useGetUserQuery(userId);
    const applyMutation = useApplyUserPapersOcrMutation(userId);

    const [activeTab, setActiveTab] = useState<string | null>(null);
    const [applySelection, setApplySelection] = useState<Set<string>>(new Set());

    const imageByType = React.useMemo(() => buildImageByType(paperItems), [paperItems]);

    const user = userData?.data;
    const compareUser = {
        last_name: user?.last_name ?? null,
        first_name: user?.first_name ?? null,
        last_name_kana: user?.last_name_kana ?? null,
        first_name_kana: user?.first_name_kana ?? null,
        info: user?.info ?? null,
    };

    const isApplicable = (group: PaperOcrGroup, field: string, extracted: string | null): boolean => {
        return APPLICABLE_FIELDS[group].includes(field) && extracted !== null && extracted !== '';
    };

    const applyKey = (group: PaperOcrGroup, field: string): string => `${group}|${field}`;

    // Preselect mismatched fields whenever a new extraction result arrives; a re-run with the
    // same groups keeps the tab the admin is looking at
    useEffect(() => {
        setActiveTab((current) =>
            current && results.some((result) => result.group === current) ? current : (results[0]?.group ?? null),
        );
        const selection = new Set<string>();
        results.forEach((result) => {
            if (result.status !== 'ok' || !result.fields) return;
            buildCompareRows(result.group, result.fields, compareUser).forEach((row) => {
                if (row.status === 'mismatch' && isApplicable(result.group, row.field, row.extracted)) {
                    selection.add(applyKey(result.group, row.field));
                }
            });
        });
        setApplySelection(selection);
    }, [results]);

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

    const getFieldLabel = (field: string): string => {
        switch (field) {
            case 'name':
                return t('Name');
            case 'last_name_kana':
                return t('Last name (kana)');
            case 'first_name_kana':
                return t('First name (kana)');
            case 'birthday':
                return t('Birthday');
            case 'gender':
                return t('Gender');
            case 'nationality':
                return t('Nationality');
            case 'address':
                return t('Address');
            case 'postal_code':
                return t('Postal code');
            case 'prefecture':
                return t('Prefecture');
            case 'residence_number':
                return t('Residence number');
            case 'residence_expiry_date':
                return t('Residence expiry date');
            case 'residence_status':
                return t('Residence status');
            case 'passport_number':
                return t('Passport number');
            case 'passport_expiry_date':
                return t('Passport expiry date');
            case 'student_card_expiry_date':
                return t('Student card expiry date');
            default:
                return field;
        }
    };

    const formatValue = (field: string, value: string | null, fields: Record<string, string | null>): string => {
        if (value === null || value === '') {
            // Surface the literal document text when the status could not be mapped
            if (field === 'residence_status' && fields.residence_status_raw) {
                return `- (${fields.residence_status_raw})`;
            }
            return '-';
        }
        if (field === 'gender') return EnumHelper.getText(GenderEnum, value) || value;
        if (field === 'nationality') return EnumHelper.getText(NationalityEnum, value) || value;
        if (field === 'residence_status') return EnumHelper.getText(ResidenceStatusEnum, value) || value;
        if (DATE_FIELDS.includes(field)) return DateHelper.parseAndFormat(value, null, t('FORMAT_DATE'), value);
        return value;
    };

    const getStatusBadge = (status: OcrCompareStatus) => {
        switch (status) {
            case 'match':
                return <Badge color="teal">{t('Match')}</Badge>;
            case 'mismatch':
                return <Badge color="red">{t('Mismatch')}</Badge>;
            case 'not_read':
                return <Badge color="yellow">{t('Not read')}</Badge>;
            case 'no_data':
                return <Badge color="gray">{t('No data')}</Badge>;
        }
    };

    const selectedFieldsFor = (group: PaperOcrGroup): string[] => {
        return APPLICABLE_FIELDS[group].filter((field) => applySelection.has(applyKey(group, field)));
    };

    const totalSelected = results.reduce((sum, result) => sum + selectedFieldsFor(result.group).length, 0);

    const onApply = async () => {
        const targets = results.filter(
            (result) => result.status === 'ok' && selectedFieldsFor(result.group).length > 0,
        );
        try {
            for (const result of targets) {
                await applyMutation.mutateAsync({
                    ocr_result_id: result.ocr_result_id,
                    fields: selectedFieldsFor(result.group),
                });
            }
            setApplySelection(new Set());
            notifications.show({
                color: 'teal',
                message: t('The selected values were applied to the profile'),
            });
        } catch (e) {
            notifications.show({
                color: 'red',
                message: t('error|Could not apply the extracted values'),
            });
        }
    };

    const toggleApplyField = (group: PaperOcrGroup, field: string, checked: boolean) => {
        setApplySelection((current) => {
            const next = new Set(current);
            if (checked) {
                next.add(applyKey(group, field));
            } else {
                next.delete(applyKey(group, field));
            }
            return next;
        });
    };

    return (
        <>
            <Tabs value={activeTab} onChange={setActiveTab} keepMounted={false}>
                <Tabs.List mb="md">
                    {results.map((result) => (
                        <Tabs.Tab key={result.group} value={result.group}>
                            {getGroupLabel(result.group)}
                        </Tabs.Tab>
                    ))}
                </Tabs.List>
                {results.map((result) => {
                    const images = result.types
                        .map((type) => imageByType.get(type))
                        .filter((item): item is UserPaperItem => Boolean(item));
                    return (
                        <Tabs.Panel key={result.group} value={result.group}>
                            {result.from_cache && (
                                <Text size="xs" c="dimmed" mb={8}>
                                    {t('Cached result from {{date}}', {
                                        date: DateHelper.parseAndFormat(
                                            result.cached_at,
                                            null,
                                            t('FORMAT_DATE_WITH_TIME'),
                                            result.cached_at ?? '',
                                        ),
                                    })}
                                </Text>
                            )}
                            <Flex gap="md" align="flex-start" wrap="wrap">
                                {images.length > 0 && (
                                    <Stack gap="sm" w={240} style={{ flexShrink: 0 }}>
                                        {images.map((item) => (
                                            <Anchor
                                                key={item.id}
                                                href={UrlHelper.getStaticUrl(item.file_path)}
                                                target="_blank"
                                            >
                                                <Image
                                                    src={UrlHelper.getStaticUrl(item.file_path)}
                                                    radius="sm"
                                                    fit="contain"
                                                    bd="1px solid var(--mantine-color-gray-3)"
                                                    alt={EnumHelper.getText(UserPaperTypeEnum, item.type)}
                                                />
                                                <Text size="xs" c="dimmed" mt={4} ta="center">
                                                    {EnumHelper.getText(UserPaperTypeEnum, item.type)}
                                                </Text>
                                            </Anchor>
                                        ))}
                                    </Stack>
                                )}
                                <div style={{ flex: 1, minWidth: 320 }}>
                                    {result.status === 'failed' || !result.fields ? (
                                        <DAlert type="danger">{t('error|Could not read this document')}</DAlert>
                                    ) : (
                                        <>
                                            <Table withTableBorder>
                                                <Table.Thead>
                                                    <Table.Tr>
                                                        <Table.Th w={160}>{t('Field')}</Table.Th>
                                                        <Table.Th>{t('Extracted value')}</Table.Th>
                                                        <Table.Th>{t('Current value')}</Table.Th>
                                                        <Table.Th w={110}>{t('Result')}</Table.Th>
                                                        {perms.USER_PAPER_APPROVE && (
                                                            <Table.Th w={80}>{t('Apply')}</Table.Th>
                                                        )}
                                                    </Table.Tr>
                                                </Table.Thead>
                                                <Table.Tbody>
                                                    {buildCompareRows(result.group, result.fields, compareUser).map(
                                                        (row) => (
                                                            <Table.Tr key={row.field}>
                                                                <Table.Td>{getFieldLabel(row.field)}</Table.Td>
                                                                <Table.Td>
                                                                    {formatValue(
                                                                        row.field,
                                                                        row.extracted,
                                                                        result.fields!,
                                                                    )}
                                                                </Table.Td>
                                                                <Table.Td>
                                                                    {formatValue(row.field, row.current, {})}
                                                                </Table.Td>
                                                                <Table.Td>{getStatusBadge(row.status)}</Table.Td>
                                                                {perms.USER_PAPER_APPROVE && (
                                                                    <Table.Td>
                                                                        {isApplicable(
                                                                            result.group,
                                                                            row.field,
                                                                            row.extracted,
                                                                        ) && (
                                                                            <Checkbox
                                                                                checked={applySelection.has(
                                                                                    applyKey(result.group, row.field),
                                                                                )}
                                                                                onChange={(event) =>
                                                                                    toggleApplyField(
                                                                                        result.group,
                                                                                        row.field,
                                                                                        event.currentTarget.checked,
                                                                                    )
                                                                                }
                                                                                aria-label={t('Apply')}
                                                                            />
                                                                        )}
                                                                    </Table.Td>
                                                                )}
                                                            </Table.Tr>
                                                        ),
                                                    )}
                                                </Table.Tbody>
                                            </Table>
                                            {result.warnings.length > 0 && (
                                                <Stack gap={4} mt={8}>
                                                    {result.warnings.map((warning) => (
                                                        <Text key={warning} size="sm" c="orange">
                                                            {getOcrWarningLabel(warning)}
                                                        </Text>
                                                    ))}
                                                </Stack>
                                            )}
                                        </>
                                    )}
                                </div>
                            </Flex>
                        </Tabs.Panel>
                    );
                })}
            </Tabs>
            {(onRerun || perms.USER_PAPER_APPROVE) && (
                <Flex justify="flex-end" mt={16} gap={8}>
                    {onRerun && (
                        <Button
                            variant="default"
                            leftSection={<LuRefreshCw />}
                            onClick={onRerun}
                            loading={isRerunning}
                            disabled={applyMutation.isPending}
                        >
                            {t('Re-run')}
                        </Button>
                    )}
                    {perms.USER_PAPER_APPROVE && (
                        <Button
                            color="teal"
                            leftSection={<LuCheck />}
                            onClick={onApply}
                            disabled={totalSelected === 0 || isRerunning}
                            loading={applyMutation.isPending}
                        >
                            {t('Apply selected ({{count}})', { count: totalSelected })}
                        </Button>
                    )}
                </Flex>
            )}
        </>
    );
};

export default PaperOcrResultView;
