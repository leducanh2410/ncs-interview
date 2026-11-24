import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { LuPlus } from 'react-icons/lu';
import Link from 'next/link';
import { useRouter } from 'next/router';
import {
    Anchor,
    Badge,
    Breadcrumbs,
    Button,
    FileButton,
    Flex,
    Group,
    Modal,
    Paper,
    Select,
    Text,
    Title,
} from '@mantine/core';
import { TFunction } from 'i18next';
import { ReconciliationBatch } from '@/types/models/reconciliation_batch';
import { ApiConfig } from '@/configs/api.config';
import RouteConfig from '@/configs/route.config';
import { ErrorCodeEnum } from '@/enums/error_code.enum';
import { ReconciliationBatchStatusEnum } from '@/enums/reconciliation_batch_status.enum';
import { ApiError } from '@/helpers/api.helper';
import DateHelper from '@/helpers/date.helper';
import EnumHelper from '@/helpers/enum.helper';
import NotificationHelper from '@/helpers/notification.helper';
import RouteHelper from '@/helpers/route.helper';
import CommonSearchBox, { userRenderItem } from '@/components/CommonSearchBox';
import DDataTable, { headerWrapClassName } from '@/components/DDataTable';
import UserWithAvatar from '@/components/UserWithAvatar';
import { actorAvatarUser } from '@/pages/manage/work-history/reconciliation/partials/actor';
import { sourceLabel, sourceOptions } from '@/pages/manage/work-history/reconciliation/partials/source';
import { usePermissions } from '@/hooks/usePermission';
import {
    downloadReconciliationOriginal,
    useCreateReconciliationBatchMutation,
    useGetReconciliationBatchesQuery,
} from '@/pages/manage/work-history/reconciliation/api';

const MAX_UPLOAD_BYTES = 10 * 1024 * 1024;

// The parser answers with a machine-readable reason (and, where it can, the
// offending sheet row). Collapsing all of that into "Could not upload the file"
// leaves an admin with a several-hundred-row workbook nothing to act on.
// `details.message` is deliberately not shown: it is PhpSpreadsheet's own
// English technical text.
const uploadErrorMessage = (error: unknown, t: TFunction, selectedSource: string): string => {
    if (!(error instanceof ApiError)) {
        return t('Could not upload the file');
    }
    if (error.message === ErrorCodeEnum.RECONCILIATION_NO_PUNCH_ROWS.value) {
        return t('The uploaded file has no punch-log rows to reconcile');
    }
    if (error.message === ErrorCodeEnum.RECONCILIATION_UNKNOWN_SOURCE.value) {
        return t('Unknown reconciliation source');
    }
    if (error.message !== ErrorCodeEnum.RECONCILIATION_INVALID_FORMAT.value) {
        return t('Could not upload the file');
    }

    const reason = error.data?.reason as string | undefined;
    const detectedSource = error.data?.detected_source as string | null | undefined;
    if (reason === 'header_mismatch' && detectedSource) {
        return t(
            'This file is a {{detected}} file, but {{selected}} is selected. Choose {{detected}} and upload again',
            {
                detected: sourceLabel(detectedSource, t),
                selected: sourceLabel(selectedSource, t),
            },
        );
    }
    const row = error.data?.details?.row as number | undefined;
    if (reason === 'missing_name' && row) {
        return t('Row {{row}} of the file has punch times but no employee name', { row });
    }
    if (reason === 'invalid_work_date' && row) {
        return t('Row {{row}} of the file has no readable work date', { row });
    }
    const column = error.data?.details?.column as string | undefined;
    if (reason === 'invalid_time' && row && column) {
        return t('Row {{row}}, column {{column}} of the file has a time or duration outside the valid range', {
            row,
            column,
        });
    }
    if (reason === 'invalid_formula' && row) {
        return t('Row {{row}} of the file contains a formula that could not be read', { row });
    }
    if (reason === 'multiple_sheets') {
        const sheets = (error.data?.details?.sheets as string[] | undefined) ?? [];
        return t(
            'The file has several attendance sheets ({{sheets}}). Keep only the sheet to reconcile and upload again',
            {
                sheets: sheets.join(', '),
            },
        );
    }
    if (reason === 'unreadable_workbook') {
        return t('The uploaded file could not be read');
    }
    return t('The uploaded file does not match the selected source format');
};

const IndexPage = () => {
    const { t } = useTranslation();
    const router = useRouter();
    const perms = usePermissions(['RECONCILIATION_WORK', 'USER_LIST']);
    const now = DateHelper.now();
    const from = now.subtract(30, 'days');

    // The search box only writes its default date range into the URL once the user submits or
    // resets it, so until then fall back to the same last-30-days default here.
    const effectiveQuery = router.query.dates
        ? router.query
        : {
              ...router.query,
              dates: [from.format('YYYY-MM-DD'), now.format('YYYY-MM-DD')],
          };
    // `router.query` is only the real, URL-derived query once `isReady` is true — before that
    // it's `{}`, which would make `effectiveQuery` fall back to the default range and drop any
    // filters already in the URL. Wait for `isReady` so we never fetch with that throwaway query.
    const { data, isPending } = useGetReconciliationBatchesQuery(
        effectiveQuery,
        perms.RECONCILIATION_WORK && router.isReady,
    );
    const createMutation = useCreateReconciliationBatchMutation();
    const [uploadOpened, setUploadOpened] = useState(false);
    const [source, setSource] = useState<string | null>('amazon_kadou');
    const [file, setFile] = useState<File | null>(null);

    const handleDownloadOriginal = async (batch: ReconciliationBatch) => {
        try {
            await downloadReconciliationOriginal(batch.id, batch.original_filename);
        } catch {
            NotificationHelper.showError(t('Could not download the original file'));
        }
    };

    const handleUpload = async () => {
        if (!source || !file) {
            return;
        }
        if (!file.name.toLowerCase().endsWith('.xlsx')) {
            NotificationHelper.showError(t('Please select an xlsx file'));
            return;
        }
        if (file.size > MAX_UPLOAD_BYTES) {
            NotificationHelper.showError(t('The file is too large'));
            return;
        }
        const form = new FormData();
        form.append('source', source);
        form.append('file', file);
        try {
            const result = await createMutation.mutateAsync(form);
            if (!result.data) {
                NotificationHelper.showError(t('Could not upload the file'));
                return;
            }
            NotificationHelper.showSuccess(t('Upload successful'));
            setUploadOpened(false);
            setFile(null);
            await router.push(RouteHelper.getPath('MANAGE_WORK_HISTORY_RECONCILIATION_DETAIL', { id: result.data.id }));
        } catch (e) {
            NotificationHelper.showError(uploadErrorMessage(e, t, source));
        }
    };

    return (
        <>
            <Breadcrumbs>
                <Anchor component={Link} href={RouteConfig.HOME.path}>
                    {t('Home')}
                </Anchor>
                <Text>{t('Attendance reconciliation')}</Text>
            </Breadcrumbs>
            <Flex justify="space-between" align="center">
                <Title order={4} lh="48px" my="md">
                    {t('Attendance reconciliation')}
                </Title>
                <Button
                    leftSection={<LuPlus />}
                    onClick={() => setUploadOpened(true)}
                    disabled={!perms.RECONCILIATION_WORK}
                >
                    {t('New reconciliation')}
                </Button>
            </Flex>

            <CommonSearchBox
                initialValues={{ source: null, uploaded_by: '', dates: [from.toDate(), now.toDate()] }}
                elements={[
                    {
                        id: 'source',
                        type: 'select',
                        label: t('Source'),
                        name: 'source',
                        options: sourceOptions(t),
                        isClearable: true,
                    },
                    {
                        id: 'uploaded_by',
                        type: 'select-remote',
                        label: t('Uploaded by'),
                        name: 'uploaded_by',
                        uri: ApiConfig.MANAGE_WORK_HISTORY_RECONCILIATIONS_USERS,
                        renderItem: userRenderItem,
                        isClearable: true,
                    },
                    {
                        id: 'dates',
                        type: 'date-range',
                        label: t('Uploaded date range'),
                        name: 'dates',
                    },
                ]}
            />

            <Paper mt="md" p="md">
                <DDataTable
                    tableKey="reconciliation-batch"
                    columns={[
                        {
                            name: t('Uploaded at'),
                            key: 'created_at',
                            render: (row: ReconciliationBatch) =>
                                DateHelper.parseAndFormat(row.created_at, null, t('FORMAT_DATE_WITH_TIME')),
                        },
                        {
                            name: t('Uploaded by'),
                            key: 'uploaded_by',
                            render: (row: ReconciliationBatch) =>
                                row.uploaded_by ? (
                                    <UserWithAvatar
                                        user={actorAvatarUser(row.uploaded_by)}
                                        isLink={Boolean(perms.USER_LIST)}
                                    />
                                ) : (
                                    '-'
                                ),
                        },
                        {
                            name: t('Source'),
                            key: 'source',
                            render: (row: ReconciliationBatch) => sourceLabel(row.source, t),
                        },
                        {
                            name: t('File'),
                            key: 'original_filename',
                            align: 'left',
                            render: (row: ReconciliationBatch) => (
                                <Anchor
                                    component="button"
                                    type="button"
                                    ta="left"
                                    onClick={() => handleDownloadOriginal(row)}
                                >
                                    {row.original_filename}
                                </Anchor>
                            ),
                        },
                        {
                            name: t('Punch rows'),
                            key: 'punch_count',
                            width: 90,
                            align: 'center',
                            titleClassName: headerWrapClassName,
                        },
                        {
                            name: t('Skipped'),
                            key: 'skipped_non_punch_count',
                        },
                        {
                            name: t('Counts'),
                            key: 'counts',
                            render: (row: ReconciliationBatch) =>
                                t(
                                    '{{matchedCount}} matched / {{differsCount}} diff / {{unmatchedCount}} unmatched / {{manualMapCount}} manual',
                                    {
                                        matchedCount: row.matched_count,
                                        differsCount: row.differs_count,
                                        unmatchedCount: row.unmatched_count,
                                        manualMapCount: row.manual_map_count,
                                    },
                                ),
                        },
                        {
                            name: t('Last export'),
                            key: 'exported_at',
                            render: (row: ReconciliationBatch) =>
                                row.exported_at ? (
                                    <>
                                        {DateHelper.parseAndFormat(row.exported_at, null, t('FORMAT_DATE_WITH_TIME'))}
                                        {row.exported_by && (
                                            <>
                                                {' ('}
                                                {perms.USER_LIST ? (
                                                    <Anchor
                                                        component={Link}
                                                        href={RouteHelper.getPath('ADMIN_USER_DETAIL', {
                                                            id: row.exported_by.id,
                                                        })}
                                                    >
                                                        {row.exported_by.name}
                                                    </Anchor>
                                                ) : (
                                                    row.exported_by.name
                                                )}
                                                {')'}
                                            </>
                                        )}
                                    </>
                                ) : (
                                    '-'
                                ),
                        },
                        {
                            name: t('Status'),
                            key: 'status',
                            width: 140,
                            render: (row: ReconciliationBatch) => (
                                <Badge size="xs" color={EnumHelper.getColor(ReconciliationBatchStatusEnum, row.status)}>
                                    {EnumHelper.getText(ReconciliationBatchStatusEnum, row.status)}
                                </Badge>
                            ),
                        },
                        {
                            name: t('Action'),
                            key: 'action',
                            render: (row: ReconciliationBatch) => (
                                <Anchor
                                    component={Link}
                                    href={RouteHelper.getPath('MANAGE_WORK_HISTORY_RECONCILIATION_DETAIL', {
                                        id: row.id,
                                    })}
                                >
                                    {t('Open')}
                                </Anchor>
                            ),
                            width: '100px',
                        },
                    ]}
                    data={data?.data || []}
                    isFetching={isPending}
                    noPermission={!perms.RECONCILIATION_WORK}
                />
            </Paper>

            <Modal opened={uploadOpened} onClose={() => setUploadOpened(false)} title={t('New reconciliation')}>
                <Select
                    label={t('Source')}
                    data={sourceOptions(t)}
                    value={source}
                    onChange={setSource}
                    allowDeselect={false}
                    mb="md"
                />
                <Group mb="xs">
                    <FileButton onChange={setFile} accept=".xlsx">
                        {(props) => (
                            <Button {...props} variant="light">
                                {t('Choose file')}
                            </Button>
                        )}
                    </FileButton>
                    <Text size="sm" c={file ? undefined : 'dimmed'}>
                        {file ? file.name : t('No file selected')}
                    </Text>
                </Group>
                <Text size="xs" c="dimmed" mb="md">
                    {t('The system will read this file and compare it with DGF attendance.')}
                </Text>
                <Button onClick={handleUpload} loading={createMutation.isPending} disabled={!source || !file}>
                    {t('Start reconciliation')}
                </Button>
            </Modal>
        </>
    );
};

export default IndexPage;
