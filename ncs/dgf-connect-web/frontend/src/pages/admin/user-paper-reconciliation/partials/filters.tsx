import React, { useEffect, useMemo, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useRouter } from 'next/router';
import { Button, Group, Paper, Select, TextInput } from '@mantine/core';
import { DatePickerInput } from '@mantine/dates';
import { PaperReconciliationVerdictEnum } from '@/enums/paper_reconciliation_verdict.enum';
import DateHelper, { DATE_STORE_FORMAT } from '@/helpers/date.helper';
import { useGetReconciliationFilterOptionsQuery } from '@/pages/admin/user-paper-reconciliation/api';

/** Statuses the backend refuses without something to narrow the candidate set. */
const NEEDS_NARROWING = ['matched', 'mismatched', 'not_comparable'];

const asString = (value: unknown): string | undefined => (typeof value === 'string' && value ? value : undefined);

/**
 * Filter state lives in the query string, so a filtered view is linkable and a refresh
 * keeps the shift the admin is working.
 */
const ReconciliationFilters = () => {
    const { t } = useTranslation();
    const router = useRouter();

    const workplaceId = asString(router.query.workplace_id);
    const date = asString(router.query.date);
    const workplaceShiftId = asString(router.query.workplace_shift_id);
    const shiftUserStatus = asString(router.query.shift_user_status);
    const status = asString(router.query.status);
    const keyword = asString(router.query.keyword) ?? '';

    // The page opens on today's roster: an admin reconciling documents is working the
    // people on shift today. Once the admin clears the date it stays cleared — this fires
    // once, on the first render of a URL that carries no date at all.
    const defaultedDate = useRef(false);
    useEffect(() => {
        if (!router.isReady || defaultedDate.current) return;
        defaultedDate.current = true;
        if (router.query.date !== undefined) return;

        router.replace(
            {
                pathname: router.pathname,
                query: { ...router.query, date: DateHelper.format(DateHelper.now(), DATE_STORE_FORMAT) },
            },
            undefined,
            { shallow: true },
        );
    }, [router.isReady]);

    // Both selects are served by the reconciliation endpoint itself: an admin holding
    // only the reconciliation permission is refused by the workplace administration list,
    // which used to leave them with an empty workplace select and no way to pick a shift.
    const { data: filterOptions, isFetching: isLoadingShifts } = useGetReconciliationFilterOptionsQuery(
        workplaceId,
        date,
    );

    const workplaceOptions = useMemo(
        () => (filterOptions?.data?.workplaces ?? []).map((w) => ({ value: String(w.id), label: w.name })),
        [filterOptions],
    );

    const shiftOptions = useMemo(
        () =>
            (filterOptions?.data?.workplace_shifts ?? []).map((s) => ({
                value: String(s.id),
                label: s.label,
            })),
        [filterOptions],
    );

    const hasNarrowing = Boolean(workplaceId || date || keyword);

    // The box holds what the admin is typing, which only becomes a filter on Enter — but
    // Clear filters and the browser's back button both change the keyword underneath it,
    // and a box still showing the old word reads as a list still filtered by it.
    const [searchDraft, setSearchDraft] = useState(keyword);
    useEffect(() => setSearchDraft(keyword), [keyword]);

    const apply = (changes: Record<string, string | undefined>) => {
        const next: Record<string, string> = {};

        Object.entries({ ...router.query, ...changes }).forEach(([key, value]) => {
            if (typeof value === 'string' && value !== '') next[key] = value;
        });

        // A shift belongs to one workplace on one day: changing either would leave a
        // stale option selected that the backend rejects.
        if ('workplace_id' in changes || 'date' in changes) delete next.workplace_shift_id;

        // A roster status without a date, and a verdict status without anything to narrow
        // it, are both 422s. Drop them here rather than letting the admin discover that.
        if (!next.date) delete next.shift_user_status;
        if (NEEDS_NARROWING.includes(next.status) && !(next.workplace_id || next.date || next.keyword)) {
            delete next.status;
        }

        delete next.page;
        router.push({ pathname: router.pathname, query: next });
    };

    return (
        <Paper p="md">
            <Group align="flex-end" gap="sm">
                <Select
                    label={t('Workplace')}
                    placeholder={t('All workplaces')}
                    data={workplaceOptions}
                    value={workplaceId ?? null}
                    onChange={(value) => apply({ workplace_id: value ?? undefined })}
                    clearable
                    searchable
                />
                <DatePickerInput
                    label={t('Date')}
                    placeholder={t('Any day')}
                    valueFormat={t('FORMAT_DATE')}
                    value={date ?? null}
                    onChange={(value) => apply({ date: value ?? undefined })}
                    clearable
                />
                <Select
                    label={t('Shift')}
                    placeholder={workplaceId && date ? t('All shifts') : t('Pick a workplace and a date first')}
                    data={shiftOptions}
                    value={workplaceShiftId ?? null}
                    onChange={(value) => apply({ workplace_shift_id: value ?? undefined })}
                    disabled={!workplaceId || !date || isLoadingShifts}
                    clearable
                />
                <Select
                    label={t('Roster status')}
                    placeholder={date ? t('Approved and pending') : t('Pick a date first')}
                    data={[
                        { value: 'approved', label: t('Approved') },
                        { value: 'pending', label: t('Pending') },
                    ]}
                    value={shiftUserStatus ?? null}
                    onChange={(value) => apply({ shift_user_status: value ?? undefined })}
                    disabled={!date}
                    clearable
                />
                <Select
                    label={t('Reconciliation status')}
                    placeholder={t('Any status')}
                    data={[
                        { value: 'uploaded', label: t('Has an admin scan') },
                        ...Object.values(PaperReconciliationVerdictEnum).map((verdict) => ({
                            value: verdict.value,
                            label: t(verdict.text),
                            disabled: NEEDS_NARROWING.includes(verdict.value) && !hasNarrowing,
                        })),
                    ]}
                    value={status ?? null}
                    onChange={(value) => apply({ status: value ?? undefined })}
                    description={hasNarrowing ? undefined : t('Verdict filters need a workplace, a date, or a keyword')}
                    clearable
                />
                <TextInput
                    label={t('Search')}
                    placeholder={t('Name or employee ID')}
                    value={searchDraft}
                    onChange={(event) => setSearchDraft(event.currentTarget.value)}
                    onKeyDown={(event) => {
                        if (event.key === 'Enter') apply({ keyword: event.currentTarget.value || undefined });
                    }}
                />
                <Button
                    variant="light"
                    // Back to the default view, not an empty one: today is the default,
                    // not a filter the admin happened to set.
                    onClick={() =>
                        router.push({
                            pathname: router.pathname,
                            query: { date: DateHelper.format(DateHelper.now(), DATE_STORE_FORMAT) },
                        })
                    }
                >
                    {t('Clear filters')}
                </Button>
            </Group>
        </Paper>
    );
};

export default ReconciliationFilters;
