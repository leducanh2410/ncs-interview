import React, { useState } from 'react';
import { useTranslation } from 'react-i18next';
import Link from 'next/link';
import { useRouter } from 'next/router';
import { Anchor, Badge, Breadcrumbs, Button, Flex, Group, Paper, Text, Title } from '@mantine/core';
import RouteConfig from '@/configs/route.config';
import DateHelper from '@/helpers/date.helper';
import RouteHelper from '@/helpers/route.helper';
import DDataTable, { headerWrapClassName } from '@/components/DDataTable';
import UserWithAvatar from '@/components/UserWithAvatar';
import ComparisonModal from '@/pages/admin/user-paper-reconciliation/partials/comparison_modal';
import ReconciliationFilters from '@/pages/admin/user-paper-reconciliation/partials/filters';
import VerdictBadge from '@/pages/admin/user-paper-reconciliation/partials/verdict_badge';
import { useGetPaperReconciliationQuery } from '@/pages/admin/user-paper-reconciliation/api';

const AdminPaperReconciliationPage = () => {
    const { t } = useTranslation();
    const router = useRouter();
    const { data, isPending } = useGetPaperReconciliationQuery(router.query);
    const [selectedUserId, setSelectedUserId] = useState<number | null>(null);

    return (
        <>
            <Breadcrumbs>
                <Anchor component={Link} href={RouteConfig.HOME.path}>
                    {t('Home')}
                </Anchor>
                <Text>{t('Document reconciliation')}</Text>
            </Breadcrumbs>
            <Flex justify="space-between" align="center">
                <Title order={4} lh="48px" my="md">
                    {t('Document reconciliation')}
                </Title>
            </Flex>

            <ReconciliationFilters />

            <Paper p="md" mt="md">
                <DDataTable
                    tableKey={'admin-paper-reconciliation'}
                    isFetching={isPending}
                    data={data?.data ?? []}
                    columns={[
                        {
                            name: t('Employee'),
                            key: 'user',
                            // Capped rather than flexible: as the only unsized column it used to
                            // absorb all the slack, squeezing the two columns the page is actually
                            // read for.
                            width: 300,
                            render: (row) => <UserWithAvatar user={row.user} isShowStaffNo={true} />,
                        },
                        {
                            name: t("Employee's own scan"),
                            key: 'employee_scan',
                            align: 'center',
                            width: 200,
                            titleClassName: headerWrapClassName,
                            render: (row) =>
                                row.employee_scan ? (
                                    <Badge color="blue" variant="light">
                                        {DateHelper.parseAndFormat(
                                            row.employee_scan.uploaded_at,
                                            null,
                                            t('FORMAT_DATE'),
                                        )}
                                    </Badge>
                                ) : (
                                    <Text c="dimmed">{t('None')}</Text>
                                ),
                        },
                        {
                            name: t('Scanned by an admin'),
                            key: 'admin_scan',
                            // Sized like the rest: with every other column fixed, the one
                            // column left unsized swallows all the leftover width.
                            width: 260,
                            titleClassName: headerWrapClassName,
                            render: (row) =>
                                row.admin_scan ? (
                                    <Group gap={4}>
                                        {/* Linked so a reviewer can check who the scanning admin is.
                                            A deleted submitter has no account page left to open. */}
                                        {row.admin_scan.submitted_by?.name ? (
                                            <Anchor
                                                component={Link}
                                                size="sm"
                                                href={RouteHelper.getPath('ADMIN_USER_DETAIL', {
                                                    id: row.admin_scan.submitted_by.id,
                                                })}
                                            >
                                                {row.admin_scan.submitted_by.name}
                                            </Anchor>
                                        ) : (
                                            <Text size="sm">{t('Deleted admin')}</Text>
                                        )}
                                        <Text size="sm" c="dimmed">
                                            {DateHelper.parseAndFormat(
                                                row.admin_scan.submitted_at,
                                                null,
                                                t('FORMAT_DATE_WITH_TIME'),
                                            )}
                                        </Text>
                                    </Group>
                                ) : (
                                    // The main thing the list is for: finding these people.
                                    <Badge color="yellow">{t('No admin scan yet')}</Badge>
                                ),
                        },
                        {
                            name: t('Result'),
                            key: 'verdict',
                            align: 'center',
                            // Holds up to two badges side by side, and the longest verdict label
                            // ("Chưa có thẻ cư trú trên hồ sơ") is a full line on its own.
                            width: 340,
                            titleClassName: headerWrapClassName,
                            render: (row) => (
                                <Group gap={4} justify="center">
                                    <VerdictBadge verdict={row.verdict} mismatchCount={row.mismatch_count} />
                                    {/* Shown alongside the badge: a row can be mismatched
                                        and still have no card on file. */}
                                    {row.employee_residence_missing && row.verdict !== 'employee_data_missing' && (
                                        <Badge color="gray" variant="outline">
                                            {t('No card on file')}
                                        </Badge>
                                    )}
                                </Group>
                            ),
                        },
                        {
                            name: t('Action'),
                            key: 'actions',
                            align: 'center',
                            width: 140,
                            render: (row) => (
                                <Button size="xs" variant="light" onClick={() => setSelectedUserId(row.user.id)}>
                                    {t('Compare')}
                                </Button>
                            ),
                        },
                    ]}
                />
            </Paper>

            <ComparisonModal userId={selectedUserId} onClose={() => setSelectedUserId(null)} />
        </>
    );
};

export default AdminPaperReconciliationPage;
