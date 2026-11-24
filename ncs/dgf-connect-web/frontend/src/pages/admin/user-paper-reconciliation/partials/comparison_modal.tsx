import React from 'react';
import { useTranslation } from 'react-i18next';
import { Alert, Anchor, Badge, Divider, Group, Image, Loader, Modal, Stack, Text, Title } from '@mantine/core';
import DateHelper from '@/helpers/date.helper';
import UrlHelper from '@/helpers/url.helper';
import FieldComparisonTable from '@/pages/admin/user-paper-reconciliation/partials/field_comparison_table';
import VerdictBadge from '@/pages/admin/user-paper-reconciliation/partials/verdict_badge';
import { useGetPaperReconciliationDetailQuery } from '@/pages/admin/user-paper-reconciliation/api';
import { getOcrWarningLabel } from '@/utils/ocr_warning';

type Props = {
    userId: number | null;
    onClose: () => void;
};

const ScanPane = ({ path, caption, emptyText }: { path?: string; caption: string; emptyText: string }) => {
    return (
        <Stack gap="xs" style={{ flex: 1 }}>
            <Text fw={500}>{caption}</Text>
            {path ? (
                <Anchor href={UrlHelper.getStaticUrl(path)} target="_blank" rel="noopener noreferrer">
                    <Image src={UrlHelper.getStaticUrl(path)} alt={caption} fit="contain" mah={360} />
                </Anchor>
            ) : (
                <Text c="dimmed">{emptyText}</Text>
            )}
        </Stack>
    );
};

const ComparisonModal = ({ userId, onClose }: Props) => {
    const { t } = useTranslation();
    const { data, isPending } = useGetPaperReconciliationDetailQuery(userId);
    const detail = data?.data ?? null;

    const fullName = detail ? `${detail.user.last_name ?? ''} ${detail.user.first_name ?? ''}`.trim() : '';

    // `history` is every submitted scan, the one shown above included. Listing that one
    // again under "previous" makes a first submission look like it already has a past.
    const previousScans = (detail?.history ?? []).filter((entry) => entry.id !== detail?.admin_scan?.id);

    return (
        <Modal opened={userId !== null} onClose={onClose} size="90%" title={t('Document reconciliation')}>
            {isPending || !detail ? (
                <Group justify="center" p="xl">
                    <Loader />
                </Group>
            ) : (
                <Stack gap="md">
                    <Group justify="space-between">
                        <Title order={5}>
                            {fullName} {detail.user.staff_no ? `(${detail.user.staff_no})` : ''}
                        </Title>
                        <VerdictBadge verdict={detail.verdict} mismatchCount={detail.mismatch_count} />
                    </Group>

                    {detail.admin_scan === null && (
                        <Alert color="yellow">
                            {t('No admin has scanned this employee yet. Scanning is done from the admin app.')}
                        </Alert>
                    )}

                    {/*
                     * Shown in addition to the badge, never instead of it: the verdict can
                     * be "mismatched" while the profile still holds no card, and a reviewer
                     * needs both facts.
                     */}
                    {detail.employee_residence_missing && (
                        <Alert color="gray">
                            {t(
                                "This employee's profile holds no residence card yet, so the card fields cannot be compared. The remaining fields still can. Their own document submission is where that gets fixed.",
                            )}
                        </Alert>
                    )}

                    {detail.admin_scan?.confirmed_without_ocr && (
                        <Alert color="orange">
                            {t('The admin side was typed by hand: the scan could not be read automatically.')}
                        </Alert>
                    )}

                    {detail.admin_warnings.length > 0 && (
                        <Alert color="orange" title={t('Warnings from the scan')}>
                            <Group gap="xs">
                                {detail.admin_warnings.map((warning) => (
                                    <Badge key={warning} color="orange" variant="light">
                                        {getOcrWarningLabel(warning)}
                                    </Badge>
                                ))}
                            </Group>
                        </Alert>
                    )}

                    <Group align="flex-start" gap="lg" wrap="nowrap">
                        <ScanPane
                            path={detail.employee_scan?.file_path}
                            caption={t("Employee's own scan")}
                            emptyText={t(
                                'The employee has not submitted a scanned copy. The field comparison below still stands: it runs against their profile.',
                            )}
                        />
                        <ScanPane
                            path={detail.admin_scan?.file_path}
                            caption={t('Scanned by an admin')}
                            emptyText={t('No admin scan on file')}
                        />
                    </Group>

                    <FieldComparisonTable fields={detail.fields} />

                    {previousScans.length > 0 && (
                        <>
                            <Divider label={t('Previous admin scans')} labelPosition="left" />
                            <Stack gap="xs">
                                {previousScans.map((entry) => (
                                    <Group key={entry.id} justify="space-between">
                                        <Group gap="sm">
                                            <Anchor
                                                href={UrlHelper.getStaticUrl(entry.file_path)}
                                                target="_blank"
                                                rel="noopener noreferrer"
                                            >
                                                {t('View image')}
                                            </Anchor>
                                            <Text size="sm">{entry.submitted_by?.name ?? t('Deleted admin')}</Text>
                                            <Text size="sm" c="dimmed">
                                                {DateHelper.parseAndFormat(
                                                    entry.submitted_at,
                                                    null,
                                                    t('FORMAT_DATE_WITH_TIME'),
                                                )}
                                            </Text>
                                        </Group>
                                        <VerdictBadge verdict={entry.verdict} mismatchCount={entry.mismatch_count} />
                                    </Group>
                                ))}
                            </Stack>
                        </>
                    )}
                </Stack>
            )}
        </Modal>
    );
};

export default ComparisonModal;
