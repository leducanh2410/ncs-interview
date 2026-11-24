import React, { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { LuCheck, LuX } from 'react-icons/lu';
import {
    Alert,
    Badge,
    Button,
    Divider,
    Drawer,
    Flex,
    Group,
    Image,
    Loader,
    Modal,
    SimpleGrid,
    Stack,
    Table,
    Text,
    Textarea,
    Title,
} from '@mantine/core';
import { BankAccount, BankAccountDetail } from '@/types/models/bank_account';
import { BankAccountStatusEnum, getBankAccountStatusColor } from '@/enums/bank_account_status.enum';
import { ErrorCodeEnum } from '@/enums/error_code.enum';
import { NameCheckVerdictEnum, getNameCheckVerdictColor } from '@/enums/name_check_verdict.enum';
import { PayoutMethodEnum } from '@/enums/payout_method.enum';
import { ApiError } from '@/helpers/api.helper';
import DateHelper from '@/helpers/date.helper';
import EnumHelper from '@/helpers/enum.helper';
import NotificationHelper from '@/helpers/notification.helper';
import UserWithAvatar from '@/components/UserWithAvatar';
import { buildBankAccountCompareRows } from '@/pages/admin/bank-account/partials/bank_account_compare';
import {
    useApproveBankAccountMutation,
    useGetBankAccountDetailQuery,
    useRejectBankAccountMutation,
} from '@/pages/admin/bank-account/api';

const fieldLabel = (t: (key: string) => string, field: string): string => {
    switch (field) {
        case 'bank_name':
            return t('Bank name');
        case 'bank_code':
            return t('Bank code');
        case 'branch_name':
            return t('Branch name');
        case 'branch_code':
            return t('Branch code');
        case 'account_number':
            return t('Account number');
        case 'account_holder_kana':
            return t('Account holder (katakana)');
        case 'account_holder_latin':
            return t('Account holder (Latin)');
        case 'yucho_symbol':
            return t('Yucho symbol');
        case 'yucho_number':
            return t('Yucho number');
        default:
            return field;
    }
};

const BankAccountDetailDrawer = ({ bankAccountId, canDecide, canView, onClose }: Props) => {
    const { t } = useTranslation();
    const { data, isLoading } = useGetBankAccountDetailQuery(bankAccountId, canView);
    const approveMutation = useApproveBankAccountMutation();
    const rejectMutation = useRejectBankAccountMutation();
    const [rejectOpened, setRejectOpened] = useState(false);
    const [note, setNote] = useState('');
    const [noteError, setNoteError] = useState<string | null>(null);

    const account = data?.data ?? null;

    const handleDecisionError = (error: unknown) => {
        if (error instanceof ApiError && error.message === ErrorCodeEnum.BANK_ACCOUNT_INVALID_STATE.value) {
            NotificationHelper.showError(t('error|This record was already decided by someone else'));
            return;
        }
        NotificationHelper.showError(t('error|Something went wrong. Please try again'));
    };

    const onApprove = async () => {
        if (!account) return;
        try {
            await approveMutation.mutateAsync(account.id);
            NotificationHelper.showSuccess(t('Bank account approved'));
        } catch (error) {
            handleDecisionError(error);
        }
    };

    const onReject = async () => {
        if (!account) return;
        if (!note.trim()) {
            setNoteError(t('validation|Please enter a reason'));
            return;
        }
        try {
            await rejectMutation.mutateAsync({ id: account.id, note: note.trim() });
            NotificationHelper.showSuccess(t('Bank account rejected'));
            setRejectOpened(false);
            setNote('');
        } catch (error) {
            handleDecisionError(error);
        }
    };

    return (
        <Drawer
            opened={bankAccountId !== null}
            onClose={onClose}
            position="right"
            size="xl"
            title={t('Bank account detail')}
        >
            {isLoading && (
                <Flex justify="center" py="xl">
                    <Loader />
                </Flex>
            )}
            {account && (
                <Stack gap="md">
                    <AccountHeader account={account} />
                    {account.payout_method === PayoutMethodEnum.SEVEN_BANK_ATM.value ? (
                        <Alert color="blue">
                            {t(
                                'This employee receives salary in cash at a Seven Bank ATM. No bank details are required.',
                            )}
                        </Alert>
                    ) : (
                        <>
                            <NameCheckSection account={account} />
                            <ComparisonSection account={account} />
                            <ImagesSection account={account} />
                        </>
                    )}
                    {account.rejected_note && (
                        <Alert color="red" title={t('Rejection note')}>
                            {account.rejected_note}
                        </Alert>
                    )}
                    {canDecide && account.status === BankAccountStatusEnum.WAIT_FOR_APPROVAL.value && (
                        <Group justify="flex-end">
                            <Button
                                color="red"
                                variant="light"
                                leftSection={<LuX size={16} />}
                                onClick={() => setRejectOpened(true)}
                            >
                                {t('Reject')}
                            </Button>
                            <Button
                                color="green"
                                leftSection={<LuCheck size={16} />}
                                loading={approveMutation.isPending}
                                onClick={onApprove}
                            >
                                {t('Approve')}
                            </Button>
                        </Group>
                    )}
                    <HistorySection history={account.history} />
                </Stack>
            )}
            <Modal opened={rejectOpened} onClose={() => setRejectOpened(false)} title={t('Reject bank account')}>
                <Stack>
                    <Textarea
                        label={t('Reason')}
                        description={t('The employee will see this note and be asked to submit again')}
                        withAsterisk
                        minRows={3}
                        value={note}
                        error={noteError}
                        onChange={(event) => {
                            setNote(event.currentTarget.value);
                            setNoteError(null);
                        }}
                    />
                    <Group justify="flex-end">
                        <Button color="gray" onClick={() => setRejectOpened(false)}>
                            {t('Cancel')}
                        </Button>
                        <Button color="red" loading={rejectMutation.isPending} onClick={onReject}>
                            {t('Reject')}
                        </Button>
                    </Group>
                </Stack>
            </Modal>
        </Drawer>
    );
};

const AccountHeader = ({ account }: { account: BankAccountDetail }) => {
    const { t } = useTranslation();
    return (
        <Stack gap={4}>
            <Group justify="space-between">
                <UserWithAvatar user={account.user} size="md" isShowName />
                <Badge color={getBankAccountStatusColor(account.status)} variant="light">
                    {EnumHelper.getText(BankAccountStatusEnum, account.status)}
                </Badge>
            </Group>
            {account.user.name_kana && (
                <Text size="sm" c="dimmed">
                    {account.user.name_kana}
                </Text>
            )}
            <Text size="sm" c="dimmed">
                {account.user.workplaces.map((workplace) => workplace.name).join(', ') || '-'}
            </Text>
            <Text size="sm">
                {t('Payout method')}: {EnumHelper.getText(PayoutMethodEnum, account.payout_method)}
            </Text>
            {account.submitted_at && (
                <Text size="sm" c="dimmed">
                    {t('Submitted at')}:{' '}
                    {DateHelper.parseAndFormat(account.submitted_at, null, t('FORMAT_DATE_WITH_TIME'))}
                </Text>
            )}
            {account.approver && (
                <Text size="sm" c="dimmed">
                    {t('Decided by')}: {account.approver.name}
                </Text>
            )}
        </Stack>
    );
};

const NameCheckSection = ({ account }: { account: BankAccount }) => {
    const { t } = useTranslation();
    const check = account.name_check;
    if (!check) return null;
    const color = getNameCheckVerdictColor(check.verdict);
    return (
        <Alert color={color} title={EnumHelper.getText(NameCheckVerdictEnum, check.verdict)}>
            <Stack gap={4}>
                {check.kana && (
                    <Text size="sm">
                        {t('Registered')}: {check.kana.registered} / {t('On card')}: {check.kana.holder}
                    </Text>
                )}
                {check.latin && (
                    <Text size="sm">
                        {t('Registered')}: {check.latin.registered} / {t('On card')}: {check.latin.holder}
                    </Text>
                )}
                {check.warnings.includes('kana_unavailable') && (
                    <Text size="sm">
                        {t('The employee has no registered katakana name; only the Latin name was compared.')}
                    </Text>
                )}
                {check.ai && check.ai.available && (
                    <Text size="sm">
                        {t('AI opinion')}: {check.ai.verdict}
                        {typeof check.ai.confidence === 'number' ? ` (${Math.round(check.ai.confidence * 100)}%)` : ''}
                        {check.ai.reason ? ` - ${check.ai.reason}` : ''}
                    </Text>
                )}
                {check.ai && !check.ai.available && <Text size="sm">{t('AI opinion unavailable')}</Text>}
            </Stack>
        </Alert>
    );
};

const ComparisonSection = ({ account }: { account: BankAccount }) => {
    const { t } = useTranslation();
    const rows = useMemo(() => buildBankAccountCompareRows(account, account.account_number), [account]);
    return (
        <Stack gap="xs">
            <Group justify="space-between">
                <Title order={6}>{t('Card photo vs. entered details')}</Title>
                {account.entered_manually && (
                    <Badge color="gray" variant="light">
                        {t('Entered manually')}
                    </Badge>
                )}
            </Group>
            <Table withTableBorder striped>
                <Table.Thead>
                    <Table.Tr>
                        <Table.Th>{t('Field')}</Table.Th>
                        <Table.Th>{t('Read from photo')}</Table.Th>
                        <Table.Th>{t('Entered by employee')}</Table.Th>
                    </Table.Tr>
                </Table.Thead>
                <Table.Tbody>
                    {rows.map((row) => (
                        <Table.Tr
                            key={row.field}
                            data-testid={`compare-${row.field}`}
                            bg={row.mismatch ? 'red.0' : undefined}
                        >
                            <Table.Td>{fieldLabel(t, row.field)}</Table.Td>
                            <Table.Td>{row.ocr ?? '-'}</Table.Td>
                            <Table.Td c={row.mismatch ? 'red' : undefined} fw={row.mismatch ? 600 : undefined}>
                                {row.input ?? '-'}
                            </Table.Td>
                        </Table.Tr>
                    ))}
                </Table.Tbody>
            </Table>
            {account.ocr_warnings.length > 0 && (
                <Text size="xs" c="dimmed">
                    {t('OCR warnings')}: {account.ocr_warnings.join(', ')}
                </Text>
            )}
        </Stack>
    );
};

const ImagesSection = ({ account }: { account: BankAccount }) => {
    const { t } = useTranslation();
    const current = account.images.filter((image) => image.folder === 'current');
    if (account.images_restricted) {
        return (
            <Stack gap="xs">
                <Title order={6}>{t('Card photos')}</Title>
                <Text size="sm" c="dimmed">
                    {t('Card photos are only shown to admins who can approve bank accounts.')}
                </Text>
            </Stack>
        );
    }
    if (current.length === 0) return null;
    return (
        <Stack gap="xs">
            <Title order={6}>{t('Card photos')}</Title>
            <SimpleGrid cols={{ base: 1, sm: 2 }}>
                {current.map((image) => (
                    <a key={image.id} href={image.url} target="_blank" rel="noreferrer">
                        <Image src={image.url} alt={t('Card photo')} radius="sm" fit="contain" mah={260} />
                    </a>
                ))}
            </SimpleGrid>
        </Stack>
    );
};

const HistorySection = ({ history }: { history: BankAccount[] }) => {
    const { t } = useTranslation();
    if (history.length === 0) return null;
    return (
        <Stack gap="xs">
            <Divider />
            <Title order={6}>{t('History')}</Title>
            {history.map((item) => (
                <Group key={item.id} justify="space-between">
                    <Text size="sm">
                        {EnumHelper.getText(PayoutMethodEnum, item.payout_method)}
                        {item.bank_name ? ` · ${item.bank_name}` : ''}
                        {item.account_number_masked ? ` · ${item.account_number_masked}` : ''}
                    </Text>
                    <Group gap={6}>
                        <Badge color={getBankAccountStatusColor(item.status)} variant="light">
                            {EnumHelper.getText(BankAccountStatusEnum, item.status)}
                        </Badge>
                        <Text size="xs" c="dimmed">
                            {item.approved_at
                                ? DateHelper.parseAndFormat(item.approved_at, null, t('FORMAT_DATE_WITH_TIME'))
                                : ''}
                        </Text>
                    </Group>
                </Group>
            ))}
        </Stack>
    );
};

type Props = {
    bankAccountId: number | null;
    canDecide: boolean;
    canView: boolean;
    onClose: () => void;
};

export default BankAccountDetailDrawer;
