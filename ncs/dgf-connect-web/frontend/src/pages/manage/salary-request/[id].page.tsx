import React from 'react';
import { useTranslation } from 'react-i18next';
import { LuCheck, LuCircleDollarSign, LuX } from 'react-icons/lu';
import Link from 'next/link';
import { useRouter } from 'next/router';
import { Anchor, Breadcrumbs, Button, Card, CardSection, Flex, Group, Stack, Text, Title } from '@mantine/core';
import { useDisclosure } from '@mantine/hooks';
import RouteConfig from '@/configs/route.config';
import { SalaryRequestStatusEnum } from '@/enums/salary_request_status.enum';
import { SalaryRequestTypeEnum } from '@/enums/salary_request_type.enum';
import CommonHelper from '@/helpers/common.helper';
import DateHelper, { DATE_STORE_FORMAT_WITH_TIME } from '@/helpers/date.helper';
import EnumHelper from '@/helpers/enum.helper';
import NotificationHelper from '@/helpers/notification.helper';
import DDataTable from '@/components/DDataTable';
import SalaryRejectModal from '@/pages/manage/salary-request/partials/salary_reject_modal';
import { usePermissions } from '@/hooks/usePermission';
import {
    useApproveSalaryRequestMutation,
    useConfirmPaidSalaryRequestMutation,
    useGetSalaryRequestDetailQuery,
} from '@/pages/manage/salary-request/api';

const IndexPage = () => {
    const { t } = useTranslation();
    const router = useRouter();
    const id = router.query.id as string;
    const perms = usePermissions([
        'SALARY_REQUEST_LIST',
        'SALARY_REQUEST_APPROVE',
        'SALARY_REQUEST_APPROVE_ACCOUNTANT',
        'SALARY_REQUEST_REJECT',
        'SALARY_REQUEST_CONFIRM_PAID',
    ]);
    const { data, isLoading } = useGetSalaryRequestDetailQuery(id, perms.SALARY_REQUEST_LIST);
    const approve = useApproveSalaryRequestMutation(id);
    const confirmPaid = useConfirmPaidSalaryRequestMutation(id);
    const [isShowReject, { open: openReject, close: closeReject }] = useDisclosure(false);

    if (isLoading || !data || !data.data) {
        return (
            <>
                <Breadcrumbs>
                    <Anchor component={Link} href={RouteConfig.HOME.path}>
                        {t('Home')}
                    </Anchor>
                    <Anchor component={Link} href={RouteConfig.MANAGE_SALARY_REQUEST.path}>
                        {t('Salary request')}
                    </Anchor>
                    <Text>{t('Salary request detail')}</Text>
                </Breadcrumbs>
                <Title order={4} lh="48px" my="md">
                    {t('Salary request detail')}
                </Title>
            </>
        );
    }

    const canApprove =
        (data.data.status === SalaryRequestStatusEnum.WAIT_FOR_APPROVAL.value && perms.SALARY_REQUEST_APPROVE) ||
        (data.data.status === SalaryRequestStatusEnum.WAIT_FOR_APPROVAL_ACCOUNTANT.value &&
            perms.SALARY_REQUEST_APPROVE_ACCOUNTANT);

    return (
        <>
            <Breadcrumbs>
                <Anchor component={Link} href={RouteConfig.HOME.path}>
                    {t('Home')}
                </Anchor>
                <Anchor component={Link} href={RouteConfig.MANAGE_SALARY_REQUEST.path}>
                    {t('Salary request')}
                </Anchor>
                <Text>{t('Salary request detail')}</Text>
            </Breadcrumbs>
            <Title order={4} lh="48px" my="md">
                {t('Salary request detail')}
            </Title>
            <Flex gap={8} mb={16}>
                <Button
                    variant="filled"
                    color="blue"
                    leftSection={<LuCheck />}
                    disabled={!canApprove}
                    loading={approve.isPending}
                    onClick={async () => {
                        try {
                            await approve.mutateAsync();
                            NotificationHelper.showSuccess(t('Successfully approve salary request'));
                        } catch (e) {
                            NotificationHelper.showError(t('error|Could not approve salary request'));
                        }
                    }}
                >
                    {t('Approve')}
                </Button>
                <Button
                    variant="filled"
                    color="red"
                    leftSection={<LuX />}
                    disabled={
                        data.data.status !== SalaryRequestStatusEnum.WAIT_FOR_APPROVAL.value ||
                        !perms.SALARY_REQUEST_REJECT
                    }
                    onClick={openReject}
                >
                    {t('Reject')}
                </Button>
                <Button
                    variant="filled"
                    color="teal"
                    leftSection={<LuCircleDollarSign />}
                    disabled={
                        data.data.status !== SalaryRequestStatusEnum.WAIT_FOR_PAYMENT.value ||
                        !perms.SALARY_REQUEST_CONFIRM_PAID
                    }
                    loading={confirmPaid.isPending}
                    onClick={async () => {
                        try {
                            await confirmPaid.mutateAsync();
                            NotificationHelper.showSuccess(t('Successfully confirm paid salary request'));
                        } catch (e) {
                            NotificationHelper.showError(t('error|Could not confirm paid salary request'));
                        }
                    }}
                >
                    {t('Confirm paid')}
                </Button>
            </Flex>
            <Card>
                <CardSection withBorder py="sm" px="md">
                    <Text fw={700}>{t('Request Info')}</Text>
                </CardSection>
                <CardSection p="md">
                    <Flex gap="md">
                        <Stack flex={1} gap={0}>
                            <Group px="sm" py="xs" bg="gray.2">
                                <Text size="sm" w={200}>
                                    {t('User')}
                                </Text>
                                <Text size="sm">
                                    {data.data.user.name} ({data.data.user.staff_no || '-'})
                                </Text>
                            </Group>
                            <Group px="sm" py="xs">
                                <Text size="sm" w={200}>
                                    {t('Date')}
                                </Text>
                                <Text size="sm">
                                    {DateHelper.parseAndFormat(
                                        data.data.created_at,
                                        DATE_STORE_FORMAT_WITH_TIME,
                                        t('FORMAT_TIMESTAMP'),
                                    )}
                                </Text>
                            </Group>
                            <Group px="sm" py="xs" bg="gray.2">
                                <Text size="sm" w={200}>
                                    {t('Maximum amount')}
                                </Text>
                                <Text size="sm">{CommonHelper.formatNumber(data.data.max_amount)}</Text>
                            </Group>
                            <Group px="sm" py="xs">
                                <Text size="sm" w={200}>
                                    {t('Desired amount')}
                                </Text>
                                <Text size="sm">{CommonHelper.formatNumber(data.data.amount)}</Text>
                            </Group>
                            <Group px="sm" py="xs" bg="gray.2">
                                <Text size="sm" w={200}>
                                    {t('Receive method')}
                                </Text>
                                <Text size="sm">{EnumHelper.getText(SalaryRequestTypeEnum, data.data.type)}</Text>
                            </Group>
                            <Group px="sm" py="xs">
                                <Text size="sm" w={200}>
                                    {t('Status')}
                                </Text>
                                <Text size="sm">{EnumHelper.getText(SalaryRequestStatusEnum, data.data.status)}</Text>
                            </Group>
                            {data.data.status === SalaryRequestStatusEnum.REJECTED.value && (
                                <Group px="sm" py="xs" bg="gray.2">
                                    <Text size="sm" w={200}>
                                        {t('Reject reason')}
                                    </Text>
                                    <Text size="sm">{data.data.reject_reason}</Text>
                                </Group>
                            )}
                        </Stack>
                        <Stack flex={1} gap={0}>
                            <Group px="sm" py="xs" bg="gray.2">
                                <Text size="sm" w={200}>
                                    {t('Approver')}
                                </Text>
                                <Text size="sm">{data.data.approver?.name || '-'}</Text>
                            </Group>
                            <Group px="sm" py="xs">
                                <Text size="sm" w={200}>
                                    {t('Approved at')}
                                </Text>
                                <Text size="sm">
                                    <Text size="sm">
                                        {DateHelper.parseAndFormat(
                                            data.data.approved_at ? data.data.approved_at : data.data.rejected_at,
                                            DATE_STORE_FORMAT_WITH_TIME,
                                            t('FORMAT_TIMESTAMP'),
                                        )}
                                    </Text>
                                </Text>
                            </Group>
                            <Group px="sm" py="xs" bg="gray.2">
                                <Text size="sm" w={200}>
                                    {t('Approver (accountant)')}
                                </Text>
                                <Text size="sm">{data.data.accountant_approver?.name || '-'}</Text>
                            </Group>
                            <Group px="sm" py="xs">
                                <Text size="sm" w={200}>
                                    {t('Approved at (accountant)')}
                                </Text>
                                <Text size="sm">
                                    {DateHelper.parseAndFormat(
                                        data.data.accountant_approved_at,
                                        DATE_STORE_FORMAT_WITH_TIME,
                                        t('FORMAT_TIMESTAMP'),
                                    )}
                                </Text>
                            </Group>
                            <Group px="sm" py="xs" bg="gray.2">
                                <Text size="sm" w={200}>
                                    {t('Paid at')}
                                </Text>
                                <Text size="sm">
                                    {DateHelper.parseAndFormat(
                                        data.data.paid_at,
                                        DATE_STORE_FORMAT_WITH_TIME,
                                        t('FORMAT_TIMESTAMP'),
                                    )}
                                </Text>
                            </Group>
                            <Group px="sm" py="xs">
                                <Text size="sm" w={200}>
                                    {t('Transfer confirmed at')}
                                </Text>
                                <Text size="sm">
                                    {DateHelper.parseAndFormat(
                                        data.data.received_at || data.data.returned_at,
                                        DATE_STORE_FORMAT_WITH_TIME,
                                        t('FORMAT_TIMESTAMP'),
                                    )}
                                </Text>
                            </Group>
                        </Stack>
                    </Flex>
                </CardSection>
            </Card>

            <Card mt={16}>
                <CardSection withBorder py="sm" px="md">
                    <Text fw={700}>{t('Salary detail')}</Text>
                </CardSection>
                <CardSection p="md">
                    <DDataTable
                        columns={[
                            {
                                name: t('Month'),
                                key: 'month',
                                render: (data) => {
                                    return data.salary.month;
                                },
                            },
                            {
                                name: t('Total amount'),
                                key: 'total_amount',
                                render: (data) => {
                                    return CommonHelper.formatNumber(data.salary.total_amount);
                                },
                            },
                            {
                                name: t('Total hours'),
                                key: 'total_minutes',
                                render: (data) => {
                                    return DateHelper.formatMinuteToHour(data.salary.total_minutes);
                                },
                            },
                            {
                                name: t('Base salary'),
                                key: 'min_salary',
                                render: (data) => {
                                    return CommonHelper.formatNumber(data.salary.min_salary);
                                },
                            },
                            {
                                name: t('Is finalized?'),
                                key: 'is_finalized',
                                render: (data) => {
                                    return data.salary.is_finalized ? t('Yes') : t('No');
                                },
                            },
                            {
                                name: t('Request amount'),
                                key: 'amount',
                                render: (data) => {
                                    return CommonHelper.formatNumber(data.amount);
                                },
                            },
                        ]}
                        data={data.data.details}
                        isFetching={false}
                    />
                </CardSection>
            </Card>
            <SalaryRejectModal id={id} opened={isShowReject} onClose={closeReject} />
        </>
    );
};

export default IndexPage;
