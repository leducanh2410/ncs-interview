import React, { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { LuEye, LuTriangleAlert } from 'react-icons/lu';
import Link from 'next/link';
import { useRouter } from 'next/router';
import { ActionIcon, Anchor, Badge, Breadcrumbs, Group, Paper, Stack, Text, Title, Tooltip } from '@mantine/core';
import { useAtom } from 'jotai';
import { BankAccountListRow } from '@/types/models/bank_account';
import RouteConfig from '@/configs/route.config';
import { BankAccountStatusEnum, getBankAccountStatusColor } from '@/enums/bank_account_status.enum';
import { NameCheckVerdictEnum, getNameCheckVerdictColor } from '@/enums/name_check_verdict.enum';
import { PayoutMethodEnum } from '@/enums/payout_method.enum';
import DateHelper from '@/helpers/date.helper';
import EnumHelper from '@/helpers/enum.helper';
import CommonSearchBox from '@/components/CommonSearchBox';
import DDataTable from '@/components/DDataTable';
import UserWithAvatar from '@/components/UserWithAvatar';
import BankAccountDetailDrawer from '@/pages/admin/bank-account/partials/bank_account_detail_drawer';
import { masterAtom } from '@/pages/global.state';
import { usePermissions } from '@/hooks/usePermission';
import { useGetBankAccountsQuery } from '@/pages/admin/bank-account/api';

const IndexPage = () => {
    const { t } = useTranslation();
    const router = useRouter();
    const perms = usePermissions(['BANK_ACCOUNT_LIST', 'BANK_ACCOUNT_APPROVE']);
    const [masterData] = useAtom(masterAtom);
    const [selectedId, setSelectedId] = useState<number | null>(null);
    const { data, isLoading } = useGetBankAccountsQuery(router.query, perms.BANK_ACCOUNT_LIST);

    const workplaceOptions = useMemo(
        () =>
            (masterData?.workplaces || []).map((workplace) => ({
                value: workplace.id.toString(),
                label: workplace.name,
            })),
        [masterData],
    );

    return (
        <>
            <Breadcrumbs>
                <Anchor component={Link} href={RouteConfig.HOME.path}>
                    {t('Home')}
                </Anchor>
                <Text>{t('Bank accounts')}</Text>
            </Breadcrumbs>
            <Title order={4} lh="48px" my="md">
                {t('Bank accounts')}
            </Title>
            <CommonSearchBox
                initialValues={{ keyword: '', workplace_id: [], status: [], payout_method: '', has_warning: '' }}
                elements={[
                    { id: 'keyword', type: 'input-text', label: t('Name or staff number'), name: 'keyword' },
                    {
                        id: 'workplace_id',
                        type: 'select',
                        label: t('Workplace'),
                        name: 'workplace_id',
                        options: workplaceOptions,
                        isMulti: true,
                        isSearchable: true,
                    },
                    {
                        id: 'status',
                        type: 'select',
                        label: t('Status'),
                        name: 'status',
                        options: EnumHelper.toSelectArray(BankAccountStatusEnum, [
                            BankAccountStatusEnum.WAIT_FOR_APPROVAL.value,
                            BankAccountStatusEnum.APPROVED.value,
                            BankAccountStatusEnum.REJECTED.value,
                        ]),
                        isMulti: true,
                    },
                    {
                        id: 'payout_method',
                        type: 'select',
                        label: t('Payout method'),
                        name: 'payout_method',
                        options: EnumHelper.toSelectArray(PayoutMethodEnum),
                        showAll: true,
                        allValue: '',
                    },
                    {
                        id: 'has_warning',
                        type: 'select',
                        label: t('Warnings'),
                        name: 'has_warning',
                        options: [
                            { value: 'true', label: t('With warnings') },
                            { value: 'false', label: t('Without warnings') },
                        ],
                        showAll: true,
                        allValue: '',
                    },
                ]}
            />
            <Paper p="md" mt="md">
                <DDataTable<BankAccountListRow>
                    columns={[
                        {
                            name: t('Employee'),
                            key: 'user',
                            render: (row) => <UserWithAvatar user={row.user} isShowName />,
                        },
                        {
                            name: t('Workplace'),
                            key: 'workplaces',
                            render: (row) => row.user.workplaces.map((workplace) => workplace.name).join(', ') || '-',
                        },
                        {
                            name: t('Payout method'),
                            key: 'payout_method',
                            render: (row) => EnumHelper.getText(PayoutMethodEnum, row.payout_method),
                        },
                        {
                            name: t('Bank'),
                            key: 'bank_name',
                            render: (row) =>
                                row.payout_method === PayoutMethodEnum.SEVEN_BANK_ATM.value ? (
                                    '-'
                                ) : (
                                    <Stack gap={2}>
                                        <Text size="sm">
                                            {[row.bank_name, row.branch_name].filter(Boolean).join(' / ') || '-'}
                                        </Text>
                                        <Text size="xs" c="dimmed">
                                            {row.account_number_masked || '-'}
                                        </Text>
                                    </Stack>
                                ),
                        },
                        {
                            name: t('Status'),
                            key: 'status',
                            width: 130,
                            render: (row) => (
                                <Badge color={getBankAccountStatusColor(row.status)} variant="light">
                                    {EnumHelper.getText(BankAccountStatusEnum, row.status)}
                                </Badge>
                            ),
                        },
                        {
                            name: t('Checks'),
                            key: 'checks',
                            render: (row) => {
                                if (row.payout_method === PayoutMethodEnum.SEVEN_BANK_ATM.value) return '-';
                                return (
                                    <Group gap={4}>
                                        {row.name_check_verdict && (
                                            <Badge
                                                color={getNameCheckVerdictColor(row.name_check_verdict)}
                                                variant="light"
                                            >
                                                {EnumHelper.getText(NameCheckVerdictEnum, row.name_check_verdict)}
                                            </Badge>
                                        )}
                                        {row.mismatch_count > 0 && (
                                            <Tooltip
                                                label={t('{{count}} fields differ from the card photo', {
                                                    count: row.mismatch_count,
                                                })}
                                            >
                                                <Badge
                                                    color="orange"
                                                    variant="light"
                                                    leftSection={<LuTriangleAlert size={12} />}
                                                >
                                                    {row.mismatch_count}
                                                </Badge>
                                            </Tooltip>
                                        )}
                                        {row.entered_manually && (
                                            <Badge color="gray" variant="light">
                                                {t('Entered manually')}
                                            </Badge>
                                        )}
                                    </Group>
                                );
                            },
                        },
                        {
                            name: t('Submitted at'),
                            key: 'submitted_at',
                            width: 160,
                            render: (row) =>
                                row.submitted_at
                                    ? DateHelper.parseAndFormat(row.submitted_at, null, t('FORMAT_DATE_WITH_TIME'))
                                    : '-',
                        },
                        {
                            name: t('Action'),
                            key: 'action',
                            width: 100,
                            align: 'center',
                            render: (row) => (
                                <ActionIcon
                                    variant="subtle"
                                    color="blue"
                                    onClick={() => setSelectedId(row.id)}
                                    aria-label={t('View')}
                                >
                                    <LuEye size={16} />
                                </ActionIcon>
                            ),
                        },
                    ]}
                    data={data?.data || []}
                    isFetching={isLoading}
                    noPermission={!perms.BANK_ACCOUNT_LIST}
                />
            </Paper>
            <BankAccountDetailDrawer
                bankAccountId={selectedId}
                canDecide={perms.BANK_ACCOUNT_APPROVE}
                canView={perms.BANK_ACCOUNT_LIST}
                onClose={() => setSelectedId(null)}
            />
        </>
    );
};

export default IndexPage;
