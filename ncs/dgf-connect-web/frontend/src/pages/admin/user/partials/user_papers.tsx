import React, { useMemo } from 'react';
import { useTranslation } from 'react-i18next';
import { LuCheck, LuScanText } from 'react-icons/lu';
import {
    Anchor,
    Badge,
    Button,
    Flex,
    Modal,
    Paper,
    ScrollArea,
    Switch,
    Table,
    Text,
    Textarea,
    Title,
} from '@mantine/core';
import { useForm } from '@mantine/form';
import { useDisclosure } from '@mantine/hooks';
import { notifications } from '@mantine/notifications';
import { Validator, validatorResolver } from '@/validator';
import { UserPaperItem } from '@/types/models/user_paper';
import { ErrorCodeEnum } from '@/enums/error_code.enum';
import { UserPaperStatusEnum, getColor } from '@/enums/user_paper_status.enum';
import { UserPaperTypeEnum } from '@/enums/user_paper_type.enum';
import { ApiError } from '@/helpers/api.helper';
import DateHelper from '@/helpers/date.helper';
import EnumHelper from '@/helpers/enum.helper';
import FormHelper from '@/helpers/form.helper';
import UrlHelper from '@/helpers/url.helper';
import DAlert from '@/components/DAlert';
import DDataTable from '@/components/DDataTable';
import PaperCompletionRequestModal, {
    COMPLETION_SHIFT_WINDOW_DAYS,
} from '@/pages/admin/user/partials/paper_completion_request_modal';
import PaperEmptiedStepsAlert from '@/pages/admin/user/partials/paper_emptied_steps_alert';
import PaperFolders from '@/pages/admin/user/partials/paper_folders';
import PaperMismatchAlert from '@/pages/admin/user/partials/paper_mismatch_alert';
import PaperMissingDocumentsAlert from '@/pages/admin/user/partials/paper_missing_documents_alert';
import PaperOcrModal from '@/pages/admin/user/partials/paper_ocr_modal';
import PaperOcrResultView, { OCR_ELIGIBLE_TYPES } from '@/pages/admin/user/partials/paper_ocr_result_view';
import PaperStudentCardExpiryAlert from '@/pages/admin/user/partials/paper_student_card_expiry_alert';
import { usePermissions } from '@/hooks/usePermission';
import {
    PaperOcrGroupResult,
    useApproveUserPaperMutation,
    useExtractUserPapersOcrMutation,
    useGetUserPapersQuery,
    useGetUserUpcomingShiftsQuery,
    useReopenEmptiedPaperStepsMutation,
    useReopenPaperStepsMutation,
    useRequestPaperCompletionMutation,
} from '@/pages/admin/user/api';

type ItemDecision = {
    id: number;
    approved: boolean;
};

const UserPapers = ({ status, id, residenceCardExpiryAlert }: Props) => {
    const { t } = useTranslation();
    const perms = usePermissions(['USER_PAPER_LIST', 'USER_PAPER_APPROVE', 'USER_PAPER_REJECT']);
    const { data, isPending } = useGetUserPapersQuery(id, perms.USER_PAPER_LIST);
    const approveMutation = useApproveUserPaperMutation(id);
    const extractMutation = useExtractUserPapersOcrMutation(id);
    const requestCompletionMutation = useRequestPaperCompletionMutation(id);
    const reopenStepsMutation = useReopenPaperStepsMutation(id);
    const reopenEmptiedStepsMutation = useReopenEmptiedPaperStepsMutation(id);
    const upcomingShiftsQuery = useGetUserUpcomingShiftsQuery(id, COMPLETION_SHIFT_WINDOW_DAYS);
    const [opened, { open, close }] = useDisclosure(false);
    const [ocrOpened, { open: openOcr, close: closeOcr }] = useDisclosure(false);
    const [ocrResults, setOcrResults] = React.useState<PaperOcrGroupResult[]>([]);
    const [ocrFailed, setOcrFailed] = React.useState(false);
    const [completionOpened, { open: openCompletion, close: closeCompletion }] = useDisclosure(false);

    const isWaitingForApproval = status === UserPaperStatusEnum.WAIT_FOR_APPROVAL.value;
    const canApprove = perms.USER_PAPER_APPROVE && isWaitingForApproval;
    // Only an approved file can be sent back for completion. One under review is settled
    // through the approve modal, which can reject the individual documents instead.
    const canRequestCompletion = perms.USER_PAPER_APPROVE && status === UserPaperStatusEnum.APPROVED.value;

    // The shifts are only read when the admin is about to act, so the list stays cheap
    const onRequestCompletion = () => {
        upcomingShiftsQuery.refetch();
        openCompletion();
    };

    const isPendingPaper = (data?.data?.status ?? status) === UserPaperStatusEnum.PENDING.value;
    const missingRequiredSteps = data?.data?.missing_required_steps;
    const reopenableSteps = data?.data?.reopenable_steps ?? [];
    const notYetOpenedSteps = isPendingPaper ? reopenableSteps : missingRequiredSteps;
    const awaitingEmployeeSteps = isPendingPaper
        ? (missingRequiredSteps ?? []).filter((step) => !reopenableSteps.includes(step))
        : [];
    const canReopenSteps = perms.USER_PAPER_APPROVE && isPendingPaper && reopenableSteps.length > 0;

    const onReopenSteps = async () => {
        try {
            await reopenStepsMutation.mutateAsync();
            notifications.show({
                color: 'teal',
                message: t('The employee was asked to complete their documents'),
            });
        } catch (e) {
            notifications.show({
                color: 'red',
                message: t('error|Could not ask the employee to complete their documents'),
            });
        }
    };

    const emptiedSteps = data?.data?.emptied_steps ?? [];
    const canReopenEmptiedSteps = perms.USER_PAPER_APPROVE && emptiedSteps.length > 0;

    const onReopenEmptiedSteps = async () => {
        try {
            const response = await reopenEmptiedStepsMutation.mutateAsync();
            const askedToResubmit = (response.data?.reopened_steps.length ?? 0) > 0;
            notifications.show({
                color: 'teal',
                message: askedToResubmit
                    ? t('The employee was asked to resubmit the documents')
                    : t('The documents were unlocked for the employee to upload again'),
            });
        } catch (e) {
            notifications.show({
                color: 'red',
                message: t('error|Could not reopen the documents'),
            });
        }
    };

    const onConfirmCompletion = async () => {
        try {
            await requestCompletionMutation.mutateAsync();
            notifications.show({
                color: 'teal',
                message: t('The employee was asked to complete their documents'),
            });
            closeCompletion();
        } catch (e) {
            // The file may have moved on since the tab was loaded — someone else approved it,
            // or the employee filed what was missing. Refetching is what makes the button
            // disappear, so the admin is not left looking at an action that no longer applies.
            // The backend message is developer-facing English; the refreshed screen below
            // is what actually tells the admin what happened — the badge and the button
            notifications.show({
                color: 'red',
                message: t('error|Could not ask the employee to complete their documents'),
            });
            closeCompletion();
        }
    };

    const [pendingItems, processedItems] = useMemo(() => {
        if (!data || !data.data) {
            return [[], []];
        }

        const pendingItems: UserPaperItem[] = [],
            processedItems: UserPaperItem[] = [];
        data.data.items.forEach((item) => {
            if (item.folder === 'deleted') {
                processedItems.push(item);
            } else if (!item.approved_at && !item.rejected_at) {
                pendingItems.push(item);
            } else {
                processedItems.push(item);
            }
        });

        return [pendingItems, processedItems];
    }, [data]);

    const approveForm = useForm<{
        items: ItemDecision[];
        rejected_note?: string;
    }>({
        initialValues: {
            items: pendingItems.map((item) => ({ id: item.id, approved: true })),
            rejected_note: '',
        },
        validate: validatorResolver({
            items: Validator.array(),
            rejected_note: Validator.string()
                .requiredIf((values: any) => values.items?.some((item: ItemDecision) => !item.approved))
                .label(t('Reject reason')),
        }),
    });

    // Update form when pending items change
    React.useEffect(() => {
        approveForm.setValues({
            items: pendingItems.map((item) => ({ id: item.id, approved: true })),
            rejected_note: '',
        });
    }, [pendingItems]);

    const hasRejection = approveForm.values.items.some((item) => !item.approved);

    const studentCardExpiryMissing = data?.data?.student_card_expiry_missing ?? false;
    const hasMissingRequiredSteps = (data?.data?.missing_required_steps?.length ?? 0) > 0;
    // Mirrors the backend rule: only a full approval is held back. A rejection sends the
    // documents back rather than granting anything, so it stays available — it is often the
    // admin's answer to a file that arrived without the date.
    const blockedByStudentCardExpiry = studentCardExpiryMissing && !hasRejection;

    // What the one request actually does, read off the file rather than off the button that
    // opened this. A single request reopens both reasons at once, and both can be true of
    // the same file — an employee approved before 資格外活動許可 became mandatory whose
    // student card has been on file all along, with no date — so naming only the reason the
    // admin happened to click would have them confirm less than they are about to do.
    const completionMessage = useMemo(() => {
        if (studentCardExpiryMissing && hasMissingRequiredSteps) {
            return t(
                'The employee will be asked to file the missing documents, and to submit their student card again because that is the only form that records its expiry date. They will not be able to check in or register for shifts until they do.',
            );
        }
        if (studentCardExpiryMissing) {
            return t(
                'The employee will be asked to submit their student card again, because that is the only form that records its expiry date. They will not be able to check in or register for shifts until they do.',
            );
        }
        return undefined;
    }, [studentCardExpiryMissing, hasMissingRequiredSteps, t]);

    // OCR runs at upload time and is cached, so this is usually an instant cache hit
    const pendingOcrTypes = useMemo(() => {
        const pendingTypes = new Set(pendingItems.map((item) => item.type));
        return OCR_ELIGIBLE_TYPES.filter((type) => pendingTypes.has(type));
    }, [pendingItems]);

    const runOcr = async (force = false) => {
        if (pendingOcrTypes.length === 0) {
            return;
        }
        setOcrFailed(false);
        try {
            const response = await extractMutation.mutateAsync({ types: pendingOcrTypes, force });
            setOcrResults(response.data?.results ?? []);
        } catch (e) {
            setOcrFailed(true);
        }
    };

    const openProcessModal = () => {
        setOcrResults([]);
        setOcrFailed(false);
        open();
        runOcr();
    };

    const onApprove = async (values: typeof approveForm.values) => {
        try {
            await approveMutation.mutateAsync(values);
            notifications.show({
                color: 'teal',
                message: t('Successfully processed user paper'),
            });
            close();
        } catch (e) {
            FormHelper.setFormError(approveForm, e);
            if (e instanceof ApiError) {
                let message = t('error|Could not process user paper');
                if (e.message === ErrorCodeEnum.USER_FACE_APPROVAL_FAILED.value) {
                    message = t('error|Face approval failed');
                }
                if (e.message === ErrorCodeEnum.USER_PAPER_STUDENT_CARD_EXPIRY_MISSING.value) {
                    message = t('error|Cannot approve: the student card has no expiry date');
                }
                notifications.show({
                    color: 'red',
                    message: message,
                });
            }
        }
    };

    const getItemStatusBadge = (item: UserPaperItem) => {
        if (item.approved_at && !item.rejected_at) {
            return <Badge color="teal">{t('Approved')}</Badge>;
        }
        if (item.rejected_at) {
            return <Badge color="red">{t('Rejected')}</Badge>;
        }
        return <Badge color="gray">{t('Pending')}</Badge>;
    };

    return (
        <>
            <Paper p="md" mb="md">
                <Flex justify="space-between" align="center" mb={16}>
                    <Flex gap={8}>
                        <Title order={3} size={16}>
                            {t('Paper status')}
                        </Title>
                    </Flex>
                    <Button
                        size="sm"
                        h={32}
                        variant="light"
                        leftSection={<LuScanText />}
                        onClick={openOcr}
                        disabled={isPending || !data?.data?.items?.length}
                    >
                        {t('Read from image')}
                    </Button>
                </Flex>
                {/* An account that signs in to the admin files nothing, so the standing
                    warning at the top of the tab is dropped for it — the same one inside
                    the approve modal below is not, because there it informs the decision
                    the admin is making rather than reminding them of an obligation. The
                    expiry alert needs no guard here: the API already reports no missing
                    date for such an account. */}
                {!data?.data?.is_web_admin && <PaperMismatchAlert mismatches={data?.data?.document_mismatches} />}
                {residenceCardExpiryAlert && (
                    <DAlert type="warning" title={t('Card expired or expiring soon')} mb="md">
                        {t('Residence card has expired or is about to expire, please update')}
                    </DAlert>
                )}
                <PaperStudentCardExpiryAlert
                    missing={studentCardExpiryMissing}
                    canRequestCompletion={canRequestCompletion}
                    onRequestCompletion={onRequestCompletion}
                />
                <PaperEmptiedStepsAlert
                    emptiedSteps={emptiedSteps}
                    canReopen={canReopenEmptiedSteps}
                    onReopen={onReopenEmptiedSteps}
                    reopening={reopenEmptiedStepsMutation.isPending}
                />
                <PaperMissingDocumentsAlert
                    missingSteps={notYetOpenedSteps}
                    canRequestCompletion={canRequestCompletion}
                    onRequestCompletion={onRequestCompletion}
                    canReopenSteps={canReopenSteps}
                    onReopenSteps={onReopenSteps}
                    reopening={reopenStepsMutation.isPending}
                    awaitingSteps={awaitingEmployeeSteps}
                />
                <Flex align="center" gap={8}>
                    <Badge color={getColor(status)}>{EnumHelper.getText(UserPaperStatusEnum, status)}</Badge>
                    {data?.data?.rejected_note && (
                        <Text flex={1}>
                            {`${
                                status === UserPaperStatusEnum.REJECTED.value
                                    ? t('Reject reason')
                                    : t('Previous reject reason')
                            }: ${data.data.rejected_note}`}
                        </Text>
                    )}
                </Flex>
            </Paper>
            <Paper p="md" mb="md">
                <Flex justify="space-between" align="center" mb={16}>
                    <Flex gap={8}>
                        <Title order={3} size={16}>
                            {t('Waiting for approval papers')}
                        </Title>
                    </Flex>
                </Flex>
                <DDataTable
                    columns={[
                        {
                            name: t('Type'),
                            key: 'type',
                            enumObj: UserPaperTypeEnum,
                        },
                        {
                            name: t('File'),
                            key: 'file_path',
                            render: (record) => {
                                return (
                                    <Anchor
                                        href={UrlHelper.getStaticUrl(record.file_path)}
                                        target="_blank"
                                        mr={8}
                                        style={{
                                            display: 'block',
                                            maxWidth: '300px',
                                            textOverflow: 'ellipsis',
                                            overflow: 'hidden',
                                            whiteSpace: 'nowrap',
                                        }}
                                    >
                                        {record.file_name}
                                    </Anchor>
                                );
                            },
                        },
                        {
                            name: t('Uploaded at'),
                            key: 'created_at',
                            render: (record) =>
                                DateHelper.parseAndFormat(record.created_at, null, t('FORMAT_TIMESTAMP')),
                        },
                    ]}
                    data={pendingItems}
                    isFetching={isPending}
                />
                {pendingItems.length > 0 && (
                    <Flex justify="space-between" mt={16}>
                        <Button
                            size="sm"
                            h={32}
                            color="teal"
                            leftSection={<LuCheck />}
                            onClick={openProcessModal}
                            disabled={!canApprove}
                        >
                            {t('Process papers')}
                        </Button>
                    </Flex>
                )}
            </Paper>

            <Paper p="md">
                <PaperFolders
                    title={t('Processed papers')}
                    items={processedItems}
                    userId={id}
                    confirmedApprovalAt={
                        status === UserPaperStatusEnum.APPROVED.value ? (data?.data?.approved_at ?? null) : null
                    }
                />
            </Paper>

            <PaperCompletionRequestModal
                opened={completionOpened}
                message={completionMessage}
                shifts={upcomingShiftsQuery.data?.data ?? []}
                shiftsLoading={upcomingShiftsQuery.isFetching}
                shiftsFailed={!upcomingShiftsQuery.isFetching && upcomingShiftsQuery.isError}
                loading={requestCompletionMutation.isPending}
                onConfirm={onConfirmCompletion}
                onCancel={closeCompletion}
            />

            <Modal
                opened={opened}
                onClose={close}
                title={t('Process papers')}
                size={pendingOcrTypes.length > 0 ? '80%' : 'lg'}
            >
                <form onSubmit={approveForm.onSubmit(onApprove)}>
                    <PaperMismatchAlert mismatches={data?.data?.document_mismatches} />
                    <PaperStudentCardExpiryAlert missing={studentCardExpiryMissing} />
                    <ScrollArea.Autosize type="always" mah={400}>
                        <Table>
                            <Table.Thead>
                                <Table.Tr>
                                    <Table.Th w={400}>{t('Type')}</Table.Th>
                                    <Table.Th>{t('File')}</Table.Th>
                                    <Table.Th w={180}>{t('Approve')}</Table.Th>
                                </Table.Tr>
                            </Table.Thead>
                            <Table.Tbody>
                                {approveForm.values.items.map((item, index) => {
                                    const paperItem = pendingItems.find((p) => p.id === item.id);
                                    if (!paperItem) return null;
                                    return (
                                        <Table.Tr key={item.id}>
                                            <Table.Td>{EnumHelper.getText(UserPaperTypeEnum, paperItem.type)}</Table.Td>
                                            <Table.Td>
                                                <Anchor
                                                    href={UrlHelper.getStaticUrl(paperItem.file_path)}
                                                    target="_blank"
                                                    style={{
                                                        maxWidth: '300px',
                                                        textOverflow: 'ellipsis',
                                                        overflow: 'hidden',
                                                        whiteSpace: 'nowrap',
                                                        display: 'block',
                                                    }}
                                                >
                                                    {paperItem.file_name}
                                                </Anchor>
                                            </Table.Td>
                                            <Table.Td>
                                                <Switch
                                                    {...approveForm.getInputProps(`items.${index}.approved`, {
                                                        type: 'checkbox',
                                                    })}
                                                    label={item.approved ? t('Approve') : t('Reject')}
                                                />
                                            </Table.Td>
                                        </Table.Tr>
                                    );
                                })}
                            </Table.Tbody>
                        </Table>
                    </ScrollArea.Autosize>
                    {pendingOcrTypes.length > 0 && (
                        <>
                            <Title order={4} size={14} mt="md" mb={8}>
                                {t('Extracted information')}
                            </Title>
                            {extractMutation.isPending && ocrResults.length === 0 && (
                                <Text size="sm" c="dimmed">
                                    {t('Reading document images, this may take up to a minute')}
                                </Text>
                            )}
                            {ocrFailed && <DAlert type="danger">{t('error|Could not read from image')}</DAlert>}
                            {ocrResults.length > 0 && (
                                <PaperOcrResultView
                                    userId={id}
                                    paperItems={data?.data?.items ?? []}
                                    results={ocrResults}
                                    onRerun={() => runOcr(true)}
                                    isRerunning={extractMutation.isPending}
                                />
                            )}
                        </>
                    )}
                    {hasRejection && (
                        <Textarea
                            label={t('Reject reason')}
                            withAsterisk
                            mt="md"
                            {...approveForm.getInputProps('rejected_note')}
                        />
                    )}
                    <Flex justify="flex-end" mt={16} gap={8}>
                        <Button
                            variant="filled"
                            color="gray"
                            onClick={() => {
                                approveForm.reset();
                                close();
                            }}
                        >
                            {t('Cancel')}
                        </Button>
                        <Button
                            type="submit"
                            variant="filled"
                            color="teal"
                            leftSection={<LuCheck />}
                            loading={approveMutation.isPending}
                            disabled={blockedByStudentCardExpiry}
                        >
                            {t('Submit')}
                        </Button>
                    </Flex>
                </form>
            </Modal>
            <PaperOcrModal opened={ocrOpened} onClose={closeOcr} userId={id} paperItems={data?.data?.items ?? []} />
        </>
    );
};

type Props = {
    status: string;
    id: string;
    residenceCardExpiryAlert?: boolean;
};

export default UserPapers;
