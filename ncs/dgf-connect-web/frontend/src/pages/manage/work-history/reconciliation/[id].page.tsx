import { useEffect, useMemo, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { LuCircleHelp } from 'react-icons/lu';
import Link from 'next/link';
import { useRouter } from 'next/router';
import {
    ActionIcon,
    Alert,
    Anchor,
    Badge,
    Breadcrumbs,
    Button,
    Flex,
    Group,
    Modal,
    Paper,
    Popover,
    Radio,
    Stack,
    Text,
    TextInput,
    Title,
} from '@mantine/core';
import { TFunction } from 'i18next';
import { ReconciliationActor } from '@/types/models/reconciliation_batch';
import {
    ReconciliationRow,
    ReconciliationSummaryField,
    ReconciliationUserSummary,
} from '@/types/models/reconciliation_row';
import { User } from '@/types/models/user';
import RouteConfig from '@/configs/route.config';
import { ErrorCodeEnum } from '@/enums/error_code.enum';
import { ApiError } from '@/helpers/api.helper';
import { DATE_STORE_FORMAT_WITH_TIME } from '@/helpers/date.helper';
import DateHelper from '@/helpers/date.helper';
import NotificationHelper from '@/helpers/notification.helper';
import UserWithAvatar from '@/components/UserWithAvatar';
import { actorAvatarUser } from '@/pages/manage/work-history/reconciliation/partials/actor';
import { externalSideLabel, sourceLabel } from '@/pages/manage/work-history/reconciliation/partials/source';
import { usePermissions } from '@/hooks/usePermission';
import {
    downloadReconciliationOriginal,
    searchReconciliationUsers,
    useBulkDecideReconciliationMutation,
    useConfirmReconciliationMutation,
    useExportReconciliationMutation,
    useGetReconciliationBatchQuery,
    usePatchReconciliationRowMutation,
} from '@/pages/manage/work-history/reconciliation/api';

type FilterKey = 'all' | 'differs' | 'summary_differs' | 'matched' | 'unmatched' | 'no_attendance' | 'pending';
type FieldKey = 'check_in_time' | 'check_out_time' | 'break_minutes';

const FIELD_LABEL_WIDTH = 220;
const FIELD_VALUE_WIDTH = 240;

const formatPunch = (iso: string | null | undefined, workDate: string, t: TFunction): string => {
    if (!iso) {
        return '-';
    }
    const parsed = DateHelper.parse(iso, DATE_STORE_FORMAT_WITH_TIME);
    if (!parsed) {
        return '-';
    }
    const time = parsed.format('HH:mm');
    return parsed.format('YYYY-MM-DD') > workDate ? t('{{time}} (next day)', { time }) : time;
};

const fieldDisplay = (
    row: ReconciliationRow,
    field: FieldKey,
    t: TFunction,
): { amazon: string; dgf: string; same: boolean } => {
    const amazon =
        field === 'break_minutes'
            ? String(row.external_break_minutes)
            : formatPunch(field === 'check_in_time' ? row.external_check_in : row.external_check_out, row.work_date, t);
    const dgf =
        field === 'break_minutes'
            ? row.dgf_break_minutes === null
                ? '-'
                : String(row.dgf_break_minutes)
            : formatPunch(field === 'check_in_time' ? row.dgf_check_in : row.dgf_check_out, row.work_date, t);

    return { amazon, dgf, same: amazon === dgf };
};

const formatMinutes = (minutes: number | null | undefined): string => {
    if (minutes === null || minutes === undefined) {
        return '-';
    }
    return `${Math.floor(minutes / 60)}:${String(minutes % 60).padStart(2, '0')}`;
};

const formatActor = (
    actor: ReconciliationActor | null | undefined,
    at: string | null | undefined,
    t: TFunction,
): string => {
    if (!actor && !at) {
        return '-';
    }
    const when = at ? DateHelper.parseAndFormat(at, null, t('FORMAT_DATE_WITH_TIME')) : '';
    return [actor?.name, when].filter(Boolean).join(' · ') || '-';
};

// Mapped to an employee but not linked to a DGF check-in. On a draft batch
// Confirm links it (or re-links it); rows still waiting for the admin to pick
// between several check-ins are a different problem and stay out.
const isOrphaned = (row: ReconciliationRow): boolean =>
    Boolean(row.user) && row.check_in_id === null && row.check_in_status !== 'multiple';

// On a confirmed batch the DGF columns are served from the snapshot taken at
// Confirm, so any DGF value here means the row *was* linked to a check-in then.
// Read together with a null `check_in_id` that says the check-in was deleted
// afterwards — as opposed to a row that was confirmed without one. Whether
// either blocks the export is a different question, and only the server can
// answer it (`can_skip`).
const hasConfirmedDgfSnapshot = (row: ReconciliationRow): boolean =>
    row.dgf_check_in !== null || row.dgf_check_out !== null || row.dgf_break_minutes !== null;

const hasSummaryDiff = (row: ReconciliationRow): boolean =>
    row.compare_status !== 'not_comparable' && (row.summary_differs ?? []).length > 0;

const fieldNeedsDecision = (row: ReconciliationRow, field: FieldKey, t: TFunction): boolean => {
    const { same } = fieldDisplay(row, field, t);
    return !same && !row.decisions[field];
};

const IndexPage = () => {
    const { t } = useTranslation();
    const router = useRouter();
    const id = typeof router.query.id === 'string' ? router.query.id : undefined;
    const perms = usePermissions(['RECONCILIATION_WORK', 'USER_LIST']);
    const { data, isPending } = useGetReconciliationBatchQuery(id, perms.RECONCILIATION_WORK);
    const batch = data?.data;
    const externalLabel = externalSideLabel(batch?.source ?? '', t);
    const batchId = batch?.id ?? 0;
    const patchMutation = usePatchReconciliationRowMutation(batchId);
    const bulkMutation = useBulkDecideReconciliationMutation(batchId);
    const confirmMutation = useConfirmReconciliationMutation(batchId);
    const exportMutation = useExportReconciliationMutation(batchId);
    const [filter, setFilter] = useState<FilterKey>('all');
    const [mapRow, setMapRow] = useState<ReconciliationRow | null>(null);
    const [search, setSearch] = useState('');
    // `null` means "no search has run yet" — distinct from a search that came
    // back empty. Falling back to the row's auto-suggested candidates on an
    // empty result would present them as if they were hits for the keyword the
    // admin just typed, which is exactly how a row gets mapped to the wrong
    // person.
    const [searchResults, setSearchResults] = useState<ReconciliationUserSummary[] | null>(null);
    const [problemRowIds, setProblemRowIds] = useState<number[]>([]);
    // "Which rows are flagged" and "where should the screen jump" look like the
    // same thing but move at different times: the highlight list is edited as
    // rows get resolved, while a jump is only ever wanted when the server has
    // just reported a fresh set of problem rows. Driving the scroll off
    // `problemRowIds` meant every `patchRow()` — which drops the row it just
    // skipped and so always produces a new array — threw the admin back to the
    // first flagged row, i.e. exactly when several rows were reported at once
    // and they were working through them. `nonce` keeps two consecutive
    // reports that start at the same row distinguishable, so the second one
    // still scrolls.
    const [scrollTarget, setScrollTarget] = useState<{ rowId: number; nonce: number } | null>(null);
    const scrollNonce = useRef(0);
    const rowRefs = useRef<Record<number, HTMLDivElement | null>>({});
    const readOnly = batch?.status === 'confirmed';
    const hasLegacyPunchRows = Boolean(batch?.rows?.some((row) => row.time_basis === 'punch'));

    const rows = batch?.rows ?? [];

    const duplicateCheckInRowIds = useMemo(() => {
        const byCheckIn = new Map<number, number[]>();
        rows.forEach((row) => {
            if (row.skipped || row.check_in_id === null) return;
            byCheckIn.set(row.check_in_id, [...(byCheckIn.get(row.check_in_id) ?? []), row.id]);
        });
        return new Set(
            Array.from(byCheckIn.values())
                .filter((ids) => ids.length > 1)
                .flat(),
        );
    }, [rows]);
    const visibleRows = useMemo(() => {
        return rows.filter((row) => {
            if (filter === 'all') return true;
            if (filter === 'differs') return row.compare_status === 'differs';
            if (filter === 'summary_differs') return hasSummaryDiff(row);
            if (filter === 'matched') return row.compare_status === 'matched';
            if (filter === 'unmatched') {
                return ['unmatched', 'similar', 'ambiguous', 'duplicate_external'].includes(row.match_status);
            }
            // Any mapped row that is not linked to a DGF check-in, which covers
            // "the employee has no attendance that day", "attendance appeared
            // after the upload, so the row is not bound to it yet" and "the
            // check-in was deleted after Confirm".
            if (filter === 'no_attendance') return isOrphaned(row);
            if (filter === 'pending') return row.has_pending_edit_request;
            return true;
        });
    }, [rows, filter]);

    const incomplete = rows.some((row) => {
        if (row.skipped) return false;
        if (duplicateCheckInRowIds.has(row.id)) return true;
        if (['unmatched', 'similar', 'ambiguous', 'duplicate_external'].includes(row.match_status) && !row.user) {
            return true;
        }
        if (row.check_in_status === 'multiple') return true;
        if (row.compare_status === 'differs') {
            return (
                fieldNeedsDecision(row, 'check_in_time', t) ||
                fieldNeedsDecision(row, 'check_out_time', t) ||
                fieldNeedsDecision(row, 'break_minutes', t)
            );
        }
        return false;
    });

    // The one entry point that both highlights and scrolls: only a server
    // verdict (Confirm/Export) gets to call it, and an empty list clears both.
    const flagProblemRows = (ids: number[]) => {
        setProblemRowIds(ids);
        scrollNonce.current += 1;
        setScrollTarget(ids.length > 0 ? { rowId: ids[0], nonce: scrollNonce.current } : null);
    };

    const patchRow = async (rowId: number, payload: Record<string, unknown>) => {
        try {
            await patchMutation.mutateAsync({ rowId, data: payload });
            setProblemRowIds((ids) => {
                // On a confirmed batch the only edit is skipping one row the
                // Export error named, and the others it named are still just as
                // stale — several of them have no badge of their own (their
                // check-in is intact, only its times moved), so clearing the
                // list would leave the admin re-running Export to find them
                // again, which is the loop reporting every stale row at once
                // was meant to end. Drop the skipped row, keep the rest.
                if (readOnly) {
                    return ids.includes(rowId) ? ids.filter((id) => id !== rowId) : ids;
                }
                // A draft batch is the opposite case: a prior Confirm error's
                // highlight was accurate when the server reported it, but after
                // any edit whether the flagged rows are still unfinished can
                // only be known by trying Confirm again, so drop the whole
                // (possibly now stale) list rather than point at a fixed row.
                return ids.length === 0 ? ids : [];
            });
        } catch {
            NotificationHelper.showError(t('Could not save the row'));
        }
    };

    // Flagged rows must actually be on screen to scroll to: if the admin is on
    // a filter tab (e.g. "Matched") that hides the reported row, switch back to
    // "all" first so it renders, then scroll once refs from that render are
    // attached — setting filter and scrolling in the same synchronous handler
    // would still read the pre-switch (possibly unattached) ref.
    useEffect(() => {
        if (!scrollTarget) return;
        rowRefs.current[scrollTarget.rowId]?.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }, [scrollTarget, filter]);

    const handleConfirm = async () => {
        try {
            await confirmMutation.mutateAsync();
            flagProblemRows([]);
            NotificationHelper.showSuccess(t('Reconciliation confirmed'));
        } catch (e) {
            if (e instanceof ApiError && e.message === ErrorCodeEnum.RECONCILIATION_DECISIONS_INCOMPLETE.value) {
                setFilter('all');
                flagProblemRows((e.data?.unfinished_row_ids as number[] | undefined) ?? []);
                NotificationHelper.showError(t('Some rows still need a mapping or field decision'));
                return;
            }
            if (e instanceof ApiError && e.message === ErrorCodeEnum.RECONCILIATION_DUPLICATE_CHECK_IN.value) {
                setFilter('all');
                flagProblemRows((e.data?.row_ids as number[] | undefined) ?? []);
                NotificationHelper.showError(t('Two or more rows are mapped to the same DGF check-in'));
                return;
            }
            NotificationHelper.showError(t('Could not confirm reconciliation'));
        }
    };

    const handleExport = async () => {
        try {
            await exportMutation.mutateAsync();
            flagProblemRows([]);
            NotificationHelper.showSuccess(t('Export ready. Import it from Work history edit requests.'));
        } catch (e) {
            if (e instanceof ApiError && e.message === ErrorCodeEnum.RECONCILIATION_STALE.value) {
                // The server names every row whose DGF data moved; highlighting
                // and scrolling to them is the only way the admin can tell which
                // of a few hundred rows is blocking the export. Back to "all"
                // first, exactly like the Confirm branches: a row that lost its
                // check-in sits under "No DGF attendance", so any other active
                // tab would leave it unrendered and the scroll with nothing to
                // aim at.
                setFilter('all');
                flagProblemRows((e.data?.row_ids as number[] | undefined) ?? []);
                NotificationHelper.showError(t('DGF attendance has changed since this batch was confirmed'));
                return;
            }
            if (e instanceof ApiError && e.message === ErrorCodeEnum.RECONCILIATION_NOTHING_TO_EXPORT.value) {
                NotificationHelper.showError(
                    t('There are no accepted {{source}} differences to export', { source: externalLabel }),
                );
                return;
            }
            if (e instanceof ApiError && e.message === ErrorCodeEnum.RECONCILIATION_BATCH_NOT_CONFIRMED.value) {
                NotificationHelper.showError(t('This reconciliation batch is not confirmed yet'));
                return;
            }
            NotificationHelper.showError(t('Could not export'));
        }
    };

    const runSearch = async () => {
        if (!search.trim() || !mapRow) return;
        try {
            const result = await searchReconciliationUsers(search.trim(), mapRow.work_date);
            setSearchResults(result.data ?? []);
        } catch {
            NotificationHelper.showError(t('Could not search users'));
        }
    };

    const modalUsers = searchResults ?? mapRow?.candidates ?? [];

    const openMapModal = (row: ReconciliationRow) => {
        setMapRow(row);
        setSearch('');
        setSearchResults(null);
    };

    const closeMapModal = () => {
        setMapRow(null);
        setSearch('');
        setSearchResults(null);
    };

    const handleDownloadOriginal = async () => {
        if (!batch) return;
        try {
            await downloadReconciliationOriginal(batch.id, batch.original_filename);
        } catch {
            NotificationHelper.showError(t('Could not download the original file'));
        }
    };

    if (isPending || !batch) {
        return <Text>{t('Loading')}</Text>;
    }

    const getStatusInfo = (row: ReconciliationRow): { label: string; description: string; color: string } | null => {
        if (row.skipped) {
            return {
                label: t('Skipped'),
                description: t(
                    'This row was skipped. It does not need mapping and will not be included in the export.',
                ),
                color: 'gray',
            };
        }
        if (row.match_status === 'similar') {
            return {
                label: t('Similar name'),
                description: t(
                    'The name on the file looks similar to a DGF employee but is not an exact match. Pick the right person manually.',
                ),
                color: 'yellow',
            };
        }
        if (row.match_status === 'ambiguous') {
            return {
                label: t('Ambiguous name'),
                description: t(
                    'More than one DGF employee matches this name exactly, so no one was auto-mapped. Pick the right person manually.',
                ),
                color: 'yellow',
            };
        }
        if (row.match_status === 'unmatched') {
            return {
                label: t('No match'),
                description: t('No DGF employee matches this name. Map one manually or skip this row.'),
                color: 'red',
            };
        }
        if (row.match_status === 'duplicate_external') {
            return {
                label: t('Duplicate row'),
                description: t(
                    'Another row in the uploaded file has the same name and work date. Map or skip each row separately.',
                ),
                color: 'red',
            };
        }
        // The other half of "the batch is frozen but DGF moved on": the row the
        // last Export named as stale while its check-in is still there, i.e.
        // only its times changed. It has no badge of its own — `compare_status`
        // would send it to the `differs` branch, whose wording asks for a choice
        // the disabled radios cannot take — and the DGF column beside it is
        // served from the Confirm-time snapshot, so it shows a value that no
        // longer exists. There is no `can_skip` escape here either (the server
        // only offers it once the check-in is gone), so the only way out is
        // putting DGF back, and nothing else on the screen says so.
        if (readOnly && row.check_in_id !== null && problemRowIds.includes(row.id)) {
            return {
                label: t('DGF attendance changed after confirmation'),
                description: t(
                    'DGF attendance for this row changed after the batch was confirmed, so the DGF values shown here are the ones recorded at that time. Restore them in Work history to unblock the export.',
                ),
                color: 'red',
            };
        }
        // Every read-only row with no check-in, handled ahead of the branches
        // below: `check_in_status` is computed from live DGF data and will say
        // `none`, `single` or `multiple` here, but none of the wording those
        // branches carry applies to a confirmed batch. The link cannot be made
        // or changed any more — there is no Confirm left to run, and the
        // check-in pickers are disabled — so what the admin needs to know is
        // whether this row is merely absent from the export or holding it up.
        if (readOnly && row.user && row.check_in_id === null) {
            if (row.can_skip) {
                return {
                    label: t('DGF attendance removed after confirmation'),
                    description: t(
                        'The DGF check-in this row was confirmed against has been deleted, so it can no longer be exported. Skip it to export the remaining rows.',
                    ),
                    color: 'red',
                };
            }
            // Not only "the admin never chose Amazon": choosing Amazon on a
            // field whose DGF value already agreed with it is reachable, and
            // the export passes over that row too, so the wording has to be
            // about the outcome rather than about the decisions on screen.
            if (hasConfirmedDgfSnapshot(row)) {
                return {
                    label: t('DGF attendance removed after confirmation'),
                    description: t(
                        'The DGF check-in this row was confirmed against has been deleted. Nothing on this row would have changed a DGF value, so it was never part of the export and nothing is blocked.',
                    ),
                    color: 'orange',
                };
            }
            // No snapshot at all: the employee had no DGF attendance when the
            // batch was confirmed, so the row was frozen without ever being
            // linked. DGF attendance entered afterwards changes nothing here.
            return {
                label: t('Never linked to DGF attendance'),
                description: t(
                    'This row had no DGF check-in when the batch was confirmed, so it was never linked to one and is not part of the export.',
                ),
                color: 'orange',
            };
        }
        if (row.user && row.check_in_status === 'none') {
            return {
                label: t('No DGF attendance'),
                description: t('This employee has no DGF check-in on this work date, so this row cannot be exported.'),
                color: 'orange',
            };
        }
        if (row.check_in_status === 'multiple') {
            return {
                label: t('Multiple check-ins'),
                description: t(
                    'This employee has more than one DGF check-in on this work date. Pick the correct one below.',
                ),
                color: 'orange',
            };
        }
        // A single DGF check-in exists but the row was never bound to it: the
        // employee had no attendance on this date when the file was uploaded
        // and DGF was filled in afterwards. Without a badge the row renders
        // bare — no fields, no controls — and the admin has no way to tell it
        // apart from a finished one.
        if (row.user && row.check_in_id === null && row.check_in_status === 'single') {
            return {
                label: t('DGF attendance added after upload'),
                description: t(
                    'DGF attendance for this employee was entered after this file was uploaded. Confirm the batch to link it and compare the values.',
                ),
                color: 'orange',
            };
        }
        if (row.compare_status === 'matched') {
            return {
                label: t('Matched'),
                description: t(
                    '{{source}} and DGF already agree on paid check-in, paid check-out and break for this row. It will not be exported.',
                    { source: externalLabel },
                ),
                color: 'green',
            };
        }
        if (row.compare_status === 'differs') {
            return {
                label: t('Differs'),
                // On a confirmed batch the radios are disabled and the DGF
                // column is a snapshot, so an instruction to pick a side is an
                // instruction the admin cannot follow: state what was decided
                // instead of asking for a decision.
                description: readOnly
                    ? t(
                          'At least one field differed between {{source}} and DGF. The choice recorded when the batch was confirmed is shown below and can no longer be changed.',
                          { source: externalLabel },
                      )
                    : t(
                          'At least one field differs between {{source}} and DGF. Choose {{source}} or DGF for each differing field below.',
                          { source: externalLabel },
                      ),
                color: 'blue',
            };
        }
        return null;
    };

    // One mutation object serves the whole page, so `isPending` alone cannot say
    // *which* row is saving — and the screen this matters on is the one where
    // Export flagged several rows at once. Read the in-flight variables to spin
    // only the button that was pressed; the others stay disabled (no parallel
    // skips) but do not pretend to be saving.
    const pendingSkipRowId = patchMutation.isPending ? (patchMutation.variables?.rowId ?? null) : null;

    const fieldRow = (row: ReconciliationRow, field: FieldKey) => {
        const { amazon, dgf, same } = fieldDisplay(row, field, t);
        const decided = row.decisions[field];
        const roundingWarning = field === 'break_minutes' ? undefined : row.rounding_warnings?.[field];
        const comparesPunches = row.time_basis === 'punch';
        const label =
            field === 'check_in_time'
                ? comparesPunches
                    ? t('Check-in')
                    : t('Paid check-in')
                : field === 'check_out_time'
                  ? comparesPunches
                      ? t('Check-out')
                      : t('Paid check-out')
                  : t('Break minutes');

        return (
            <div key={field}>
                <Group gap="md" mb="xs">
                    <Text w={FIELD_LABEL_WIDTH}>{label}</Text>
                    <Text w={FIELD_VALUE_WIDTH}>
                        {externalLabel}: {amazon}
                    </Text>
                    <Text w={FIELD_VALUE_WIDTH}>
                        {t('DGF')}: {dgf}
                    </Text>
                    {same ? (
                        <Text c="dimmed">{t('Matched')}</Text>
                    ) : (
                        <Radio.Group
                            value={decided ?? ''}
                            onChange={(value) =>
                                !readOnly &&
                                patchRow(row.id, {
                                    decisions: { [field]: value },
                                })
                            }
                        >
                            <Group>
                                <Radio value="amazon" label={externalLabel} disabled={readOnly} />
                                <Radio value="dgf" label={t('DGF')} disabled={readOnly} />
                            </Group>
                        </Radio.Group>
                    )}
                </Group>
                {!same && roundingWarning && (
                    <Text size="xs" c="orange" mb="xs">
                        {t(
                            'If {{source}} is chosen, DGF will recalculate this paid time as {{time}} after import because of the shift rounding or overtime settings.',
                            { source: externalLabel, time: formatPunch(roundingWarning, row.work_date, t) },
                        )}
                    </Text>
                )}
            </div>
        );
    };

    const summaryLabels: Record<ReconciliationSummaryField, string> = {
        work_minutes: t('Total work hours'),
        work_minutes_normal: t('Regular hours'),
        work_minutes_overtime: t('Overtime hours'),
        work_minutes_night: t('Night hours'),
        work_minutes_night_overtime: t('Night overtime hours'),
    };

    const summaryRows = (row: ReconciliationRow) => {
        if (!row.external_summary || !row.dgf_summary) {
            return null;
        }
        const differs = row.summary_differs ?? [];

        return (
            <div data-testid={`reconciliation-summary-${row.id}`}>
                <Stack gap={4} mt="sm">
                    {(Object.keys(summaryLabels) as ReconciliationSummaryField[]).map((field) => {
                        const isDifferent = differs.includes(field);
                        return (
                            <Group key={field} gap="md">
                                <Text w={FIELD_LABEL_WIDTH}>{summaryLabels[field]}</Text>
                                <Text w={FIELD_VALUE_WIDTH} c={isDifferent ? 'red' : undefined}>
                                    {externalLabel}: {formatMinutes(row.external_summary?.[field])}
                                </Text>
                                <Text w={FIELD_VALUE_WIDTH} c={isDifferent ? 'red' : undefined}>
                                    {t('DGF')}: {formatMinutes(row.dgf_summary?.[field])}
                                </Text>
                                {!isDifferent && row.external_summary?.[field] != null && (
                                    <Text c="dimmed">{t('Matched')}</Text>
                                )}
                            </Group>
                        );
                    })}
                </Stack>
            </div>
        );
    };

    return (
        <>
            <Breadcrumbs>
                <Anchor component={Link} href={RouteConfig.HOME.path}>
                    {t('Home')}
                </Anchor>
                <Anchor component={Link} href={RouteConfig.MANAGE_WORK_HISTORY_RECONCILIATION.path}>
                    {t('Attendance reconciliation')}
                </Anchor>
                <Text>{batch.original_filename}</Text>
            </Breadcrumbs>
            <Flex justify="space-between" align="center" wrap="wrap" gap="sm">
                <Title order={4} lh="48px" my="md">
                    {t('Attendance reconciliation')}
                </Title>
                <Group>
                    {!readOnly && (
                        <>
                            <Button
                                variant="light"
                                onClick={() => bulkMutation.mutate('amazon', { onSuccess: () => flagProblemRows([]) })}
                            >
                                {t('Accept remaining {{source}}', { source: externalLabel })}
                            </Button>
                            <Button
                                variant="light"
                                onClick={() => bulkMutation.mutate('dgf', { onSuccess: () => flagProblemRows([]) })}
                            >
                                {t('Keep remaining DGF')}
                            </Button>
                            <Button onClick={handleConfirm} disabled={incomplete} loading={confirmMutation.isPending}>
                                {t('Confirm reconciliation')}
                            </Button>
                        </>
                    )}
                    {readOnly && (
                        <Button onClick={handleExport} loading={exportMutation.isPending}>
                            {t('Export attendance file')}
                        </Button>
                    )}
                </Group>
            </Flex>
            {hasLegacyPunchRows && (
                <Alert color="yellow" mb="md">
                    {t(
                        'This batch was confirmed before reconciliation switched to paid time. Its check-in and check-out columns compare punch times, not paid times.',
                    )}
                </Alert>
            )}
            {readOnly && (
                <Text size="sm" mb="md">
                    {t(
                        'Changing DGF attendance requires Import on Work history edit requests and the process permission for that workplace.',
                    )}
                </Text>
            )}
            {/* Audit trail for the batch (Requirement 11.3): who uploaded, confirmed and
                exported it and when, plus the stored original file — the artifact to fall
                back on whenever a reconciliation result is disputed later. */}
            <Paper p="md" withBorder mb="md">
                <Group gap="xl" mb="sm">
                    <Text size="sm">
                        {t('Source')}: {sourceLabel(batch.source, t)}
                    </Text>
                    <Text size="sm">
                        {t('File')}: {batch.original_filename}
                    </Text>
                    <Group gap={6} wrap="nowrap">
                        <Text size="sm">{t('Uploaded by')}:</Text>
                        {batch.uploaded_by ? (
                            <UserWithAvatar
                                user={actorAvatarUser(batch.uploaded_by)}
                                size="sm"
                                isLink={Boolean(perms.USER_LIST)}
                            />
                        ) : (
                            <Text size="sm">-</Text>
                        )}
                        {batch.created_at && (
                            <Text size="sm">
                                · {DateHelper.parseAndFormat(batch.created_at, null, t('FORMAT_DATE_WITH_TIME'))}
                            </Text>
                        )}
                    </Group>
                    <Text size="sm">
                        {t('Confirmed by')}: {formatActor(batch.confirmed_by, batch.confirmed_at, t)}
                    </Text>
                    <Text size="sm">
                        {t('Exported by')}: {formatActor(batch.exported_by, batch.exported_at, t)}
                    </Text>
                </Group>
                <Button size="xs" variant="light" onClick={handleDownloadOriginal}>
                    {t('Download original file')}
                </Button>
            </Paper>
            <Group mb="md">
                {(
                    [
                        ['all', t('All')],
                        ['differs', t('Differs')],
                        ['summary_differs', t('Hours breakdown differs')],
                        ['matched', t('Matched')],
                        ['unmatched', t('Unmatched')],
                        ['no_attendance', t('No DGF attendance')],
                        ['pending', t('Pending edit request')],
                    ] as [FilterKey, string][]
                ).map(([key, label]) => (
                    <Button key={key} variant={filter === key ? 'filled' : 'light'} onClick={() => setFilter(key)}>
                        {label}
                    </Button>
                ))}
            </Group>

            <Stack>
                {visibleRows.map((row) => {
                    const statusInfo = getStatusInfo(row);
                    const isDuplicateCheckIn = duplicateCheckInRowIds.has(row.id);
                    const isFlagged = problemRowIds.includes(row.id);
                    return (
                        <Paper
                            key={row.id}
                            data-testid={`reconciliation-row-${row.id}`}
                            ref={(el) => {
                                rowRefs.current[row.id] = el;
                            }}
                            p="md"
                            withBorder
                            style={isFlagged ? { borderColor: 'var(--mantine-color-red-6)' } : undefined}
                        >
                            <Group justify="space-between" mb="sm">
                                <div>
                                    <Text fw={600}>
                                        {row.external_name}
                                        {row.external_staff_no && (
                                            <Text span size="sm" c="dimmed" ml={6}>
                                                ({t('Employee ID')}: {row.external_staff_no})
                                            </Text>
                                        )}
                                    </Text>
                                    <Group gap={6} align="center" mt={2}>
                                        <Text size="sm" c="dimmed">
                                            {row.work_date}
                                        </Text>
                                        {statusInfo && (
                                            <>
                                                <Badge size="sm" color={statusInfo.color} variant="light">
                                                    {statusInfo.label}
                                                </Badge>
                                                <Popover width={260} position="top" withArrow shadow="md">
                                                    <Popover.Target>
                                                        <ActionIcon
                                                            variant="subtle"
                                                            color="gray"
                                                            size="sm"
                                                            aria-label={t('What does this mean?')}
                                                        >
                                                            <LuCircleHelp size={14} />
                                                        </ActionIcon>
                                                    </Popover.Target>
                                                    <Popover.Dropdown>
                                                        <Text size="xs">{statusInfo.description}</Text>
                                                    </Popover.Dropdown>
                                                </Popover>
                                            </>
                                        )}
                                    </Group>
                                    {row.user && (
                                        <UserWithAvatar
                                            user={row.user as unknown as User}
                                            isLink={Boolean(perms.USER_LIST)}
                                        />
                                    )}
                                </div>
                                <Group>
                                    {row.has_pending_edit_request && (
                                        <Badge color="orange">{t('Pending edit request')}</Badge>
                                    )}
                                    {hasSummaryDiff(row) && (
                                        <Badge color="yellow">{t('Hours breakdown differs')}</Badge>
                                    )}
                                    {isDuplicateCheckIn && <Badge color="red">{t('Duplicate check-in')}</Badge>}
                                    {!readOnly && (
                                        <Button size="xs" onClick={() => openMapModal(row)}>
                                            {row.user ? t('Change mapping') : t('Map user')}
                                        </Button>
                                    )}
                                    {!readOnly && (
                                        <Button
                                            size="xs"
                                            variant={row.skipped ? 'filled' : 'light'}
                                            onClick={() => patchRow(row.id, { skipped: !row.skipped })}
                                        >
                                            {row.skipped ? t('Skipped') : t('Skip')}
                                        </Button>
                                    )}
                                    {/* The one write a confirmed batch still accepts, and the
                                        server decides who gets it: a row whose DGF check-in was
                                        deleted after Confirm and whose accepted Amazon values
                                        were headed for the workbook can never be exported nor
                                        repaired, and it blocks every other row — skipping it is
                                        the only way out short of redoing the reconciliation. A
                                        row the export would have passed over anyway is not
                                        offered the button, because pressing it would only be
                                        refused. */}
                                    {row.can_skip && (
                                        <Button
                                            size="xs"
                                            color="red"
                                            variant={row.skipped ? 'filled' : 'light'}
                                            // `row.skipped` only flips once the PATCH has been
                                            // invalidated and refetched, so without the pending
                                            // guard a double click fires the same skip twice.
                                            disabled={row.skipped || patchMutation.isPending}
                                            loading={pendingSkipRowId === row.id}
                                            onClick={() => patchRow(row.id, { skipped: true })}
                                        >
                                            {row.skipped ? t('Skipped') : t('Skip and export the rest')}
                                        </Button>
                                    )}
                                </Group>
                            </Group>
                            {row.check_in_status === 'multiple' && row.check_ins_options && (
                                <Group mb="sm">
                                    {row.check_ins_options.map((option) => (
                                        <Button
                                            key={option.id}
                                            size="xs"
                                            variant={row.check_in_id === option.id ? 'filled' : 'light'}
                                            disabled={readOnly}
                                            onClick={() => patchRow(row.id, { check_in_id: option.id })}
                                        >
                                            {option.workplace_name} {option.shift_time}
                                        </Button>
                                    ))}
                                </Group>
                            )}
                            {row.compare_status !== 'not_comparable' && (
                                <>
                                    {fieldRow(row, 'check_in_time')}
                                    {fieldRow(row, 'check_out_time')}
                                    {fieldRow(row, 'break_minutes')}
                                    {summaryRows(row)}
                                </>
                            )}
                        </Paper>
                    );
                })}
            </Stack>

            <Modal opened={Boolean(mapRow)} onClose={closeMapModal} title={t('Map user')}>
                <TextInput
                    label={t('Search')}
                    value={search}
                    onChange={(e) => setSearch(e.currentTarget.value)}
                    mb="sm"
                />
                <Button mb="md" onClick={runSearch}>
                    {t('Search')}
                </Button>
                <Stack>
                    {searchResults !== null && searchResults.length === 0 && <Text>{t('No users found')}</Text>}
                    {modalUsers.map((user) =>
                        user ? (
                            <Anchor
                                key={user.id}
                                onClick={() => {
                                    if (mapRow) {
                                        patchRow(mapRow.id, { user_id: user.id });
                                        closeMapModal();
                                    }
                                }}
                            >
                                <Group gap="sm" wrap="nowrap" align="flex-start">
                                    <UserWithAvatar
                                        user={user as unknown as User}
                                        isLink={false}
                                        isShowAccount={false}
                                    />
                                    <Stack gap={2}>
                                        <Text>
                                            {user.name} ({user.name_kana}) {user.staff_no}
                                        </Text>
                                        {(user.check_ins ?? []).map((checkIn) => (
                                            <Text key={checkIn.id} size="xs" c="dimmed">
                                                {checkIn.workplace_name} {checkIn.shift_time}{' '}
                                                {formatPunch(checkIn.check_in_time, mapRow?.work_date ?? '', t)} –{' '}
                                                {formatPunch(checkIn.check_out_time, mapRow?.work_date ?? '', t)}
                                            </Text>
                                        ))}
                                    </Stack>
                                </Group>
                            </Anchor>
                        ) : null,
                    )}
                </Stack>
            </Modal>
        </>
    );
};

export default IndexPage;
