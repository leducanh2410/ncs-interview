import React from 'react';
import { useTranslation } from 'react-i18next';
import { LuChevronRight, LuFileSpreadsheet } from 'react-icons/lu';
import Link from 'next/link';
import { useRouter } from 'next/router';
import { ActionIcon, Anchor, Breadcrumbs, Button, Flex, Paper, Text, Title } from '@mantine/core';
import { useAtom } from 'jotai';
import { ApiConfig } from '@/configs/api.config';
import RouteConfig from '@/configs/route.config';
import DateHelper from '@/helpers/date.helper';
import NotificationHelper from '@/helpers/notification.helper';
import RouteHelper from '@/helpers/route.helper';
import WorkplaceHelper from '@/helpers/workplace.helper';
import CommonSearchBox, { userRenderItem } from '@/components/CommonSearchBox';
import DDataTable from '@/components/DDataTable';
import UserWithAvatar from '@/components/UserWithAvatar';
import { masterAtom } from '@/pages/global.state';
import classes from '../index.module.css';
import { usePermissions } from '@/hooks/usePermission';
import { exportMonthlyReport, useGetMonthlyWorkHistoryQuery } from '@/pages/manage/work-history/api';

const IndexPage = () => {
    const { t } = useTranslation();
    const router = useRouter();
    const perms = usePermissions(['WORK_HISTORY_LIST', 'WORK_HISTORY_EXPORT']);
    const { data, isLoading } = useGetMonthlyWorkHistoryQuery(router.query, perms.WORK_HISTORY_LIST);
    const [masterData] = useAtom(masterAtom);
    const [expandedIds, setExpandedIds] = React.useState<number[]>([]);

    const accessibleWorkplaces = WorkplaceHelper.filterWorkplacesByAccess(
        masterData.workplaces,
        masterData.workplace_access,
        'work_history',
    );

    return (
        <>
            <Breadcrumbs>
                <Anchor component={Link} href={RouteConfig.HOME.path}>
                    {t('Home')}
                </Anchor>
                <Anchor component={Link} href={RouteConfig.MANAGE_WORK_HISTORY.path}>
                    {t('Work history')}
                </Anchor>
                <Text>{t('Monthly report')}</Text>
            </Breadcrumbs>
            <Flex justify="space-between" align="center">
                <Title order={4} lh="48px" my="md">
                    {t('Monthly report')}
                </Title>
                <Button
                    leftSection={<LuFileSpreadsheet />}
                    disabled={!perms.WORK_HISTORY_EXPORT || !(data?.data?.total || 0)}
                    onClick={async () => {
                        if (!data?.data?.data.length) {
                            return;
                        }
                        const month = data.data.data[0].month.replaceAll('-', '');
                        try {
                            await exportMonthlyReport(month, router.query);
                        } catch (e) {
                            NotificationHelper.showError(t('error|Could not export work history report'));
                        }
                    }}
                >
                    {t('Export excel')}
                </Button>
            </Flex>
            <CommonSearchBox
                initialValues={{
                    month: DateHelper.now().toDate(),
                    workplace_id: null,
                    user_id: null,
                }}
                elements={[
                    {
                        id: 'month',
                        type: 'date',
                        label: t('Month'),
                        name: 'month',
                        isMonth: true,
                    },
                    {
                        id: 'workplace_id',
                        type: 'select',
                        label: t('Workplace'),
                        name: 'workplace_id',
                        options: accessibleWorkplaces.map((workplace) => ({
                            value: workplace.id.toString(),
                            label: workplace.name,
                        })),
                        isSearchable: true,
                        showAll: true,
                        allValue: '0',
                    },
                    {
                        id: 'user_id',
                        type: 'select-remote',
                        label: t('User'),
                        name: 'user_id',
                        uri: ApiConfig.ADMIN_MASTER_USER_SEARCH,
                        renderItem: userRenderItem,
                    },
                ]}
            />
            <Paper p="md" mt="md">
                <DDataTable
                    columns={[
                        {
                            name: t('Employee'),
                            key: 'employee',
                            render: (data) => {
                                return (
                                    <Flex gap={8} align="center">
                                        {router.query.workplace_id ? null : (
                                            <ActionIcon variant="subtle" color="blue" onClick={() => {}}>
                                                <LuChevronRight
                                                    size={12}
                                                    className={`${classes.expandIcon} ${
                                                        expandedIds.includes(data.id) ? classes.rotated : ''
                                                    }`}
                                                />
                                            </ActionIcon>
                                        )}
                                        <UserWithAvatar user={data.user} />
                                    </Flex>
                                );
                            },
                        },
                        {
                            name: t('Work days'),
                            key: 'work_days',
                            width: 180,
                            render: (data) => {
                                if (router.query.workplace_id) {
                                    const detail = data.details.find(
                                        (d) => d.workplace.id.toString() === router.query.workplace_id,
                                    );
                                    return detail?.work_days || '-';
                                }

                                return data.work_days;
                            },
                        },
                        {
                            name: t('Total work hours'),
                            key: 'work_hours',
                            width: 180,
                            render: (data) => {
                                if (router.query.workplace_id) {
                                    const detail = data.details.find(
                                        (d) => d.workplace.id.toString() === router.query.workplace_id,
                                    );
                                    if (!detail) {
                                        return '-';
                                    }
                                    return DateHelper.formatMinuteToHour(detail.work_minutes);
                                }

                                return DateHelper.formatMinuteToHour(data.work_minutes);
                            },
                        },
                        {
                            name: t('Normal work hours'),
                            key: 'work_hours_normal',
                            width: 180,
                            render: (data) => {
                                if (router.query.workplace_id) {
                                    const detail = data.details.find(
                                        (d) => d.workplace.id.toString() === router.query.workplace_id,
                                    );
                                    if (!detail) {
                                        return '-';
                                    }
                                    return DateHelper.formatMinuteToHour(detail.work_minutes_normal);
                                }

                                return DateHelper.formatMinuteToHour(data.work_minutes_normal);
                            },
                        },
                        {
                            name: t('Work hours (up 25%)'),
                            key: 'work_hours_up_1',
                            width: 180,
                            render: (data) => {
                                if (router.query.workplace_id) {
                                    const detail = data.details.find(
                                        (d) => d.workplace.id.toString() === router.query.workplace_id,
                                    );
                                    if (!detail) {
                                        return '-';
                                    }
                                    return DateHelper.formatMinuteToHour(
                                        detail.work_minutes_night + detail.work_minutes_overtime,
                                    );
                                }

                                return DateHelper.formatMinuteToHour(
                                    data.work_minutes_night + data.work_minutes_overtime,
                                );
                            },
                        },
                        {
                            name: t('Work hours (up 50%)'),
                            key: 'work_hours_night_overtime',
                            width: 180,
                            render: (data) => {
                                if (router.query.workplace_id) {
                                    const detail = data.details.find(
                                        (d) => d.workplace.id.toString() === router.query.workplace_id,
                                    );
                                    if (!detail) {
                                        return '-';
                                    }
                                    return DateHelper.formatMinuteToHour(detail.work_minutes_night_overtime);
                                }

                                return DateHelper.formatMinuteToHour(data.work_minutes_night_overtime);
                            },
                        },
                    ]}
                    data={data?.data || []}
                    isFetching={isLoading}
                    rowExpansion={
                        router.query.workplace_id
                            ? undefined
                            : {
                                  expanded: { recordIds: expandedIds, onRecordIdsChange: setExpandedIds },
                                  content: ({ record }) => (
                                      <DDataTable
                                          noHeader
                                          withTableBorder={false}
                                          columns={[
                                              {
                                                  name: t('Workplace'),
                                                  key: 'workplace',
                                                  render: (data) => {
                                                      return (
                                                          <Anchor
                                                              component={Link}
                                                              href={RouteHelper.getPath('ADMIN_WORKPLACE_DETAIL', {
                                                                  id: data.workplace.id,
                                                              })}
                                                          >
                                                              {data.workplace.name}
                                                          </Anchor>
                                                      );
                                                  },
                                              },
                                              {
                                                  name: t('Work days'),
                                                  key: 'work_days',
                                                  width: 180,
                                              },
                                              {
                                                  name: t('Total work hours'),
                                                  key: 'work_hours',
                                                  width: 180,
                                                  render: (data) => {
                                                      return DateHelper.formatMinuteToHour(data.work_minutes);
                                                  },
                                              },
                                              {
                                                  name: t('Normal work hours'),
                                                  key: 'work_hours_normal',
                                                  width: 180,
                                                  render: (data) => {
                                                      return DateHelper.formatMinuteToHour(data.work_minutes_normal);
                                                  },
                                              },
                                              {
                                                  name: t('Work hours (up 25%)'),
                                                  key: 'work_hours_up_1',
                                                  width: 180,
                                                  render: (data) => {
                                                      return DateHelper.formatMinuteToHour(
                                                          data.work_minutes_night + data.work_minutes_overtime,
                                                      );
                                                  },
                                              },
                                              {
                                                  name: t('Work hours (up 50%)'),
                                                  key: 'work_hours_night_overtime',
                                                  width: 180,
                                                  render: (data) => {
                                                      return DateHelper.formatMinuteToHour(
                                                          data.work_minutes_night_overtime,
                                                      );
                                                  },
                                              },
                                          ]}
                                          data={record.details}
                                          isFetching={false}
                                      />
                                  ),
                              }
                    }
                />
            </Paper>
        </>
    );
};
export default IndexPage;
