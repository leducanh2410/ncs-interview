import { useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { LuPencilLine, LuPlus, LuTrash2 } from 'react-icons/lu';
import Link from 'next/link';
import { useRouter } from 'next/router';
import {
    ActionIcon,
    Anchor,
    Badge,
    Breadcrumbs,
    Button,
    Center,
    Flex,
    NumberInput,
    Paper,
    Switch,
    Text,
    Title,
} from '@mantine/core';
import { notifications } from '@mantine/notifications';
import { CompanyHoliday } from '@/types/models/company_holiday';
import RouteConfig from '@/configs/route.config';
import { CompanyHolidaySourceEnum } from '@/enums/company_holiday_source.enum';
import DateHelper from '@/helpers/date.helper';
import EnumHelper from '@/helpers/enum.helper';
import DConfirmModal from '@/components/DConfirmModal';
import DDataTable from '@/components/DDataTable';
import HolidayCreateUpdateModal from '@/pages/admin/admin-leave/holidays/partials/holiday_create_update_modal';
import { usePermissions } from '@/hooks/usePermission';
import {
    useDeleteCompanyHolidayMutation,
    useGenerateCompanyHolidaysMutation,
    useGetCompanyHolidaysQuery,
    useUpdateCompanyHolidaySettingMutation,
} from '@/pages/admin/admin-leave/holidays/api';

const IndexPage = () => {
    const { t } = useTranslation();
    const router = useRouter();
    const perms = usePermissions(['ADMIN_LEAVE_MANAGE']);

    const yearParam = Array.isArray(router.query.year) ? router.query.year[0] : router.query.year;
    const year = yearParam ? Number(yearParam) : DateHelper.now().year();

    const { data, isPending } = useGetCompanyHolidaysQuery(year, perms.ADMIN_LEAVE_MANAGE);
    const generateMutation = useGenerateCompanyHolidaysMutation();
    const deleteMutation = useDeleteCompanyHolidayMutation();
    const updateSettingMutation = useUpdateCompanyHolidaySettingMutation();

    const [currentHoliday, setCurrentHoliday] = useState<CompanyHoliday | null>(null);
    const [createUpdateOpened, setCreateUpdateOpened] = useState(false);
    const [deleteId, setDeleteId] = useState<number | null>(null);
    const [yearInput, setYearInput] = useState<number | string>(year);

    // router.push() is async, so a render's `year` closure can still be stale for an instant
    // after committing a new one (e.g. blur-then-click on Generate). Handlers read this instead
    // of `year` so they always act on the just-committed value, not a pending navigation. Only
    // resynced when `year` itself actually changes, so an unrelated re-render in between can't
    // clobber a commit that hasn't reached the URL yet.
    const committedYearRef = useRef(year);

    useEffect(() => {
        committedYearRef.current = year;
        setYearInput(year);
    }, [year]);

    const commitYearInput = () => {
        const newYear = Number(yearInput);
        if (!Number.isInteger(newYear) || newYear < 2000 || newYear > 2100) {
            setYearInput(year);
            return;
        }
        if (newYear !== year) {
            committedYearRef.current = newYear;
            router.push({ query: { ...router.query, year: newYear } });
        }
    };

    const handleGenerate = async () => {
        const targetYear = committedYearRef.current;
        try {
            const result = await generateMutation.mutateAsync(targetYear);
            notifications.show({
                color: 'teal',
                message: t('Generated {{count}} holidays', { count: result.data?.inserted ?? 0 }),
            });
        } catch {
            notifications.show({ color: 'red', message: t('error|Could not generate holidays') });
        }
    };

    const handleToggleWeekend = async (checked: boolean) => {
        const targetYear = committedYearRef.current;
        try {
            await updateSettingMutation.mutateAsync({ year: targetYear, exclude_weekends: checked });
            notifications.show({ color: 'teal', message: t('Setting updated') });
        } catch {
            notifications.show({ color: 'red', message: t('error|Could not update setting') });
        }
    };

    const holidays = data?.data?.holidays ?? [];

    return (
        <>
            <Breadcrumbs>
                <Anchor component={Link} href={RouteConfig.HOME.path}>
                    {t('Home')}
                </Anchor>
                <Anchor component={Link} href={RouteConfig.ADMIN_ADMIN_LEAVE_LIST.path}>
                    {t('Leave requests')}
                </Anchor>
                <Text>{t('Company holidays')}</Text>
            </Breadcrumbs>
            <Title order={4} lh="48px" my="md">
                {t('Company holidays')}
            </Title>

            <Paper p="md">
                <Flex justify="space-between" align="center" mb="md" wrap="wrap" gap="sm">
                    <Flex align="center" gap="sm">
                        <NumberInput
                            label={t('Year')}
                            value={yearInput}
                            onChange={setYearInput}
                            onBlur={commitYearInput}
                            onKeyDown={(event) => {
                                if (event.key === 'Enter') {
                                    commitYearInput();
                                }
                            }}
                            min={2000}
                            max={2100}
                            w={140}
                        />
                        <Switch
                            mt={24}
                            checked={data?.data?.exclude_weekends ?? false}
                            onChange={(event) => handleToggleWeekend(event.currentTarget.checked)}
                            disabled={updateSettingMutation.isPending || !perms.ADMIN_LEAVE_MANAGE}
                            label={t('Exclude weekends from day counts')}
                        />
                    </Flex>
                    <Flex gap="sm">
                        <Button
                            variant="default"
                            loading={generateMutation.isPending}
                            disabled={!perms.ADMIN_LEAVE_MANAGE}
                            onClick={handleGenerate}
                        >
                            {t('Generate from Japanese holiday calendar')}
                        </Button>
                        <Button
                            leftSection={<LuPlus />}
                            disabled={!perms.ADMIN_LEAVE_MANAGE}
                            onClick={() => {
                                setCurrentHoliday(null);
                                setCreateUpdateOpened(true);
                            }}
                        >
                            {t('Add holiday')}
                        </Button>
                    </Flex>
                </Flex>

                {!isPending && holidays.length === 0 ? (
                    <Center py="xl">
                        <Text c="dimmed">{t('No holidays for {{year}} yet.', { year })}</Text>
                    </Center>
                ) : (
                    <DDataTable
                        tableKey="company-holiday"
                        columns={[
                            {
                                name: t('Date'),
                                key: 'date',
                                render: (row: CompanyHoliday) => DateHelper.parseAndFormat(row.date),
                            },
                            { name: t('Name'), key: 'name' },
                            {
                                name: t('Source'),
                                key: 'source',
                                render: (row: CompanyHoliday) => (
                                    <Badge color={EnumHelper.getColor(CompanyHolidaySourceEnum, row.source)}>
                                        {EnumHelper.getText(CompanyHolidaySourceEnum, row.source)}
                                    </Badge>
                                ),
                            },
                            {
                                name: t('Action'),
                                key: 'action',
                                render: (row: CompanyHoliday) => (
                                    <Flex>
                                        <ActionIcon
                                            variant="subtle"
                                            color="blue"
                                            disabled={!perms.ADMIN_LEAVE_MANAGE}
                                            onClick={() => {
                                                setCurrentHoliday(row);
                                                setCreateUpdateOpened(true);
                                            }}
                                        >
                                            <LuPencilLine size={16} />
                                        </ActionIcon>
                                        <ActionIcon
                                            variant="subtle"
                                            color="red"
                                            disabled={!perms.ADMIN_LEAVE_MANAGE}
                                            onClick={() => setDeleteId(row.id)}
                                        >
                                            <LuTrash2 size={16} />
                                        </ActionIcon>
                                    </Flex>
                                ),
                                width: '100px',
                            },
                        ]}
                        data={holidays}
                        isFetching={isPending}
                        noPermission={!perms.ADMIN_LEAVE_MANAGE}
                    />
                )}
            </Paper>

            <HolidayCreateUpdateModal
                holiday={currentHoliday}
                opened={createUpdateOpened}
                onClose={() => setCreateUpdateOpened(false)}
            />

            <DConfirmModal
                opened={deleteId !== null}
                title={t('Delete holiday')}
                message={t('Are you sure you want to delete this holiday? Future day counts will change.')}
                confirmButtonLabel={t('Delete')}
                confirmButtonColor="red"
                isProcessing={deleteMutation.isPending}
                onCancel={() => setDeleteId(null)}
                onConfirm={async () => {
                    if (!deleteId) return;
                    try {
                        await deleteMutation.mutateAsync(deleteId);
                        notifications.show({ color: 'teal', message: t('Successfully deleted holiday') });
                    } catch {
                        notifications.show({ color: 'red', message: t('error|Could not delete holiday') });
                    } finally {
                        setDeleteId(null);
                    }
                }}
            />
        </>
    );
};

export default IndexPage;
