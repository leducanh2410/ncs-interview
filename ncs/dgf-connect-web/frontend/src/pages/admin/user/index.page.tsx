import React, { useCallback, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { LuCheck, LuFileText, LuPlus, LuX } from 'react-icons/lu';
import Link from 'next/link';
import { useRouter } from 'next/router';
import { Alert, Anchor, Badge, Breadcrumbs, Button, Flex, Paper, Text, Title, Tooltip } from '@mantine/core';
import { useDisclosure } from '@mantine/hooks';
import { useAtom } from 'jotai';
import { User } from '@/types/models/user';
import { UserInfo } from '@/types/models/user_info';
import { WorkplaceSimple } from '@/types/models/workplace';
import RouteConfig from '@/configs/route.config';
import { UserPaperStatusEnum } from '@/enums/user_paper_status.enum';
import EnumHelper from '@/helpers/enum.helper';
import WorkplaceHelper from '@/helpers/workplace.helper';
import CommonSearchBox from '@/components/CommonSearchBox';
import DDataTable from '@/components/DDataTable';
import UserWithAvatar from '@/components/UserWithAvatar';
import { buildUserExportFilters } from '@/pages/admin/user/build_export_filters';
import CreateManagerModal from '@/pages/admin/user/partials/create_manager_modal';
import ExportCsvModal from '@/pages/admin/user/partials/export_csv_modal';
import ExportPdfModal from '@/pages/admin/user/partials/export_pdf_modal';
import UserCustomConfigsCell from '@/pages/admin/user/partials/user_custom_configs_cell';
import { masterAtom } from '@/pages/global.state';
import { usePermissions } from '@/hooks/usePermission';
import { useGetGroupsQuery } from '@/pages/admin/group/api';
import { useGetUsersQuery } from '@/pages/admin/user/api';
import { getGroupDisplayName } from '@/utils/group_display_name';

type UserRow = User & { info: UserInfo | null; workplaces: WorkplaceSimple[]; roleNames: string };

const AdminUserIndexPage = () => {
    const { t } = useTranslation();
    const router = useRouter();
    const perms = usePermissions([
        'USER_LIST',
        'USER_CREATE',
        'USER_CREATE_EMPLOYEE',
        'PDF_TEMPLATE_EXPORT',
        'USER_EXPORT_CSV',
        'GROUP_VIEW',
        'USER_CONFIG_LIST',
    ]);
    const { data, isPending } = useGetUsersQuery(router.query, perms.USER_LIST);
    const { data: groupsData } = useGetGroupsQuery({ per_page: '500' }, perms.GROUP_VIEW);
    const [masterData] = useAtom(masterAtom);
    const [isShowCreate, { open: openCreate, close: closeCreate }] = useDisclosure(false);
    const [isShowExport, { open: openExport, close: closeExport }] = useDisclosure(false);
    const [isShowCsvExport, { open: openCsvExport, close: closeCsvExport }] = useDisclosure(false);

    const [selectedRecords, setSelectedRecords] = useState<UserRow[]>([]);
    const [selectAllAcrossPages, setSelectAllAcrossPages] = useState(false);

    const selectedUserIds = useMemo(() => selectedRecords.map((user) => user.id), [selectedRecords]);

    const totalUsers = data?.data?.total || 0;
    const currentPageUsers = data?.data?.data || [];
    const allSelectedOnPage = currentPageUsers.length > 0 && selectedRecords.length === currentPageUsers.length;

    const handleSelectedRecordsChange = useCallback(
        (records: UserRow[]) => {
            setSelectedRecords(records);
            if (selectAllAcrossPages && records.length !== currentPageUsers.length) {
                setSelectAllAcrossPages(false);
            }
        },
        [selectAllAcrossPages, currentPageUsers.length],
    );

    const handleSelectAllAcrossPages = useCallback(() => {
        setSelectAllAcrossPages(true);
    }, []);

    const handleClearSelection = useCallback(() => {
        setSelectedRecords([]);
        setSelectAllAcrossPages(false);
    }, []);

    const filters = useMemo(() => buildUserExportFilters(router.query), [router.query]);
    const isShowCustomConfigs = perms.USER_CONFIG_LIST && router.query.custom_config === '1';

    const groupOptions = useMemo(() => {
        const items = groupsData?.data?.data || [];
        return items.map((group) => ({
            value: group.id.toString(),
            label: getGroupDisplayName(group) || `#${group.id}`,
        }));
    }, [groupsData]);

    return (
        <>
            <Breadcrumbs>
                <Anchor component={Link} href={RouteConfig.HOME.path}>
                    {t('Home')}
                </Anchor>
                <Text>{t('Employee list')}</Text>
            </Breadcrumbs>
            <Flex justify="space-between" align="center">
                <Title order={4} lh="48px" my="md">
                    {t('Employee list')}
                </Title>
                <Flex gap="sm">
                    {perms.PDF_TEMPLATE_EXPORT && (selectedRecords.length > 0 || selectAllAcrossPages) && (
                        <Button leftSection={<LuFileText />} onClick={openExport} variant="light" color="teal">
                            {selectAllAcrossPages
                                ? t('Export PDF ({{count}} users)', { count: totalUsers })
                                : t('Export PDF ({{count}} users)', { count: selectedRecords.length })}
                        </Button>
                    )}
                    {perms.USER_EXPORT_CSV && (selectedRecords.length > 0 || selectAllAcrossPages) && (
                        <Button leftSection={<LuFileText />} onClick={openCsvExport} variant="light" color="teal">
                            {selectAllAcrossPages
                                ? t('Export CSV ({{count}} users)', { count: totalUsers })
                                : t('Export CSV ({{count}} users)', { count: selectedRecords.length })}
                        </Button>
                    )}
                    {perms.USER_CREATE_EMPLOYEE && (
                        <Button
                            component={Link}
                            href={RouteConfig.ADMIN_USER_CREATE.path}
                            leftSection={<LuPlus />}
                            variant="light"
                        >
                            {t('Create employee')}
                        </Button>
                    )}
                    <Button leftSection={<LuPlus />} onClick={openCreate} disabled={!perms.USER_CREATE}>
                        {t('Create manager')}
                    </Button>
                </Flex>
            </Flex>
            <CommonSearchBox
                initialValues={{
                    staff_no: '',
                    staff_no_empty: false,
                    name: '',
                    email: '',
                    paper_status: 'all',
                    workplace_id: '0',
                    is_disabled: '0',
                    role: 'all',
                    face_registered: 'all',
                    info_search_field: '',
                    info_search_value: '',
                    ...(perms.USER_CONFIG_LIST ? { custom_config: 'all' } : {}),
                    ...(perms.GROUP_VIEW ? { groups: [] } : {}),
                }}
                elements={[
                    {
                        id: 'staff_no',
                        type: 'input-text',
                        label: t('Employee ID'),
                        name: 'staff_no',
                        searchEmpty: {
                            name: 'staff_no_empty',
                            label: t('Not yet assigned an Employee ID'),
                        },
                    },
                    {
                        id: 'name',
                        type: 'input-text',
                        label: t('Name'),
                        name: 'name',
                    },
                    {
                        id: 'email',
                        type: 'input-text',
                        label: t('Email'),
                        name: 'email',
                    },
                    {
                        id: 'workplace_id',
                        type: 'select',
                        label: t('Workplace'),
                        name: 'workplace_id',
                        options: WorkplaceHelper.toSelectOptions(masterData.workplaces, t),
                        isClearable: false,
                        showAll: true,
                        allValue: '0',
                        searchEmpty: {
                            name: 'workplace_none',
                            label: t('Not yet assigned a workplace'),
                        },
                    },
                    {
                        id: 'paper_status',
                        type: 'select',
                        label: t('Paper status'),
                        name: 'paper_status',
                        options: EnumHelper.toSelectArray(UserPaperStatusEnum),
                        isClearable: false,
                        showAll: true,
                        allValue: 'all',
                    },
                    {
                        id: 'is_disabled',
                        type: 'select',
                        label: t('Active status'),
                        name: 'is_disabled',
                        options: [
                            { value: '0', label: t('Active') },
                            { value: '1', label: t('Disabled') },
                        ],
                        isClearable: false,
                        showAll: true,
                        allValue: 'all',
                    },
                    {
                        id: 'role',
                        type: 'select',
                        label: t('Role'),
                        name: 'role',
                        options: masterData.roles.map((role) => ({
                            value: role.name,
                            label: role.display_name || role.name,
                        })),
                        isClearable: false,
                        showAll: true,
                        allValue: 'all',
                    },
                    {
                        id: 'face_registered',
                        type: 'select',
                        label: t('Face registered'),
                        name: 'face_registered',
                        options: [
                            { value: 'all', label: t('All') },
                            { value: '1', label: t('Registered') },
                            { value: '0', label: t('Not registered') },
                        ],
                        isClearable: false,
                        showAll: false,
                    },
                    {
                        id: 'info_search_field',
                        type: 'select',
                        label: t('Field'),
                        name: 'info_search_field',
                        options: [
                            { value: 'residence_number', label: t('Residence card number') },
                            { value: 'passport_number', label: t('Passport number') },
                            { value: 'phone', label: t('Phone') },
                            { value: 'emergency_contact_phone', label: t('Emergency contact phone') },
                            { value: 'postal_code', label: t('Postal code') },
                            { value: 'prefecture', label: t('Prefecture') },
                            { value: 'address', label: t('Address') },
                            { value: 'registered_location', label: t('Registered location') },
                        ],
                        isClearable: true,
                    },
                    {
                        id: 'info_search_value',
                        type: 'input-text',
                        label: t('Value'),
                        name: 'info_search_value',
                    },
                    ...(perms.USER_CONFIG_LIST
                        ? [
                              {
                                  id: 'custom_config',
                                  type: 'select' as const,
                                  label: t('Configuration'),
                                  name: 'custom_config',
                                  options: [
                                      { value: 'all', label: t('All') },
                                      { value: '1', label: t('Different from default') },
                                  ],
                                  isClearable: false,
                                  showAll: false,
                              },
                          ]
                        : []),
                    ...(perms.GROUP_VIEW
                        ? [
                              {
                                  id: 'groups',
                                  type: 'select' as const,
                                  label: t('Groups'),
                                  name: 'groups',
                                  options: groupOptions,
                                  isMulti: true,
                                  isClearable: true,
                                  isSearchable: true,
                              },
                          ]
                        : []),
                ]}
            />
            {allSelectedOnPage && !selectAllAcrossPages && totalUsers > currentPageUsers.length && (
                <Alert color="blue" mt="md" mb="sm">
                    <Flex justify="space-between" align="center">
                        <Text>
                            {t('All {{count}} users on this page are selected.', { count: selectedRecords.length })}
                        </Text>
                        <Button variant="subtle" size="xs" onClick={handleSelectAllAcrossPages}>
                            {t('Select all {{count}} users matching filters', { count: totalUsers })}
                        </Button>
                    </Flex>
                </Alert>
            )}
            {selectAllAcrossPages && (
                <Alert color="blue" mt="md" mb="sm">
                    <Flex justify="space-between" align="center">
                        <Text>{t('All {{count}} users matching filters are selected.', { count: totalUsers })}</Text>
                        <Button variant="subtle" size="xs" onClick={handleClearSelection}>
                            {t('Clear selection')}
                        </Button>
                    </Flex>
                </Alert>
            )}
            <Paper p="md" mt="md">
                <DDataTable
                    tableKey={'admin-user-list'}
                    columns={[
                        { name: 'ID', key: 'id', align: 'center' },
                        {
                            name: t('Staff number'),
                            key: 'staff_no',
                            render: (data) => data.staff_no || '-',
                        },
                        {
                            name: t('Name'),
                            key: 'account',
                            // Holds the avatar, the name and up to three warning badges
                            width: 300,
                            // The badges wrap under the name rather than competing with it
                            // for the column's width, which truncated them to a coloured stub
                            render: (data) => (
                                <Flex align="flex-start" gap={8} wrap="wrap">
                                    <UserWithAvatar user={data} isShowStaffNo={false} />
                                    <Flex align="center" gap={4} wrap="wrap">
                                        {data.is_test && (
                                            <Badge color="orange" variant="light" size="sm">
                                                {t('TEST')}
                                            </Badge>
                                        )}
                                        {data.has_document_mismatch && (
                                            <Tooltip label={t('Documents do not match')}>
                                                <Badge color="red" variant="light" size="sm">
                                                    {t('Mismatch')}
                                                </Badge>
                                            </Tooltip>
                                        )}
                                        {data.has_missing_required_documents && (
                                            <Tooltip label={t('Missing documents for this residence status')}>
                                                <Badge color="yellow" variant="light" size="sm">
                                                    {t('Missing documents')}
                                                </Badge>
                                            </Tooltip>
                                        )}
                                        {/* Their file is complete, so the badge above stays
                                            quiet — but nothing can tell whether they are
                                            still enrolled, and they are working today */}
                                        {data.has_missing_student_card_expiry && (
                                            <Tooltip label={t('Student card expiry date is missing')}>
                                                <Badge color="red" variant="light" size="sm">
                                                    {t('No card expiry')}
                                                </Badge>
                                            </Tooltip>
                                        )}
                                    </Flex>
                                </Flex>
                            ),
                        },
                        { name: t('Email'), key: 'email' },
                        {
                            name: t('Phone'),
                            key: 'phone',
                            // Narrow enough to leave the name column room, wide enough that
                            // neither the header nor a hyphenated number breaks onto two lines
                            width: 130,
                            render: (data) => (
                                <Text size="sm" style={{ whiteSpace: 'nowrap' }}>
                                    {data.info ? data.info.phone : '-'}
                                </Text>
                            ),
                            toggleable: true,
                        },
                        {
                            name: t('Workplace'),
                            key: 'workplaces',
                            render: (data) =>
                                data.workplaces.length ? data.workplaces.map((w) => w.name).join(', ') : '-',
                            toggleable: true,
                        },
                        {
                            name: t('Role'),
                            key: 'roleNames',
                            toggleable: true,
                        },
                        {
                            // Short label on purpose: the badge under it says "registered" or
                            // "not registered" in full, so the header only has to name the
                            // subject — and the full wording is on the filter above the table
                            name: t('Face'),
                            key: 'face_registered',
                            // Sized to the badge, which is longer than the header
                            width: 165,
                            render: (data) =>
                                data.face_registered ? (
                                    <Badge color="green" leftSection={<LuCheck size={14} />}>
                                        {t('Registered')}
                                    </Badge>
                                ) : (
                                    <Badge color="gray" leftSection={<LuX size={14} />}>
                                        {t('Not registered')}
                                    </Badge>
                                ),
                            toggleable: true,
                        },
                        ...(isShowCustomConfigs
                            ? [
                                  {
                                      name: t('Configuration'),
                                      key: 'custom_configs',
                                      width: 200,
                                      render: (data: UserRow) => (
                                          <UserCustomConfigsCell configs={data.custom_configs} />
                                      ),
                                      toggleable: true,
                                  },
                              ]
                            : []),
                        {
                            name: t('Status'),
                            key: 'is_disabled',
                            width: 130,
                            render: (data) =>
                                data.is_disabled ? (
                                    <Badge color="gray">{t('Disabled')}</Badge>
                                ) : (
                                    <Badge color="green">{t('Active')}</Badge>
                                ),
                            toggleable: true,
                        },
                    ]}
                    data={data?.data || []}
                    isFetching={isPending}
                    selectedRecords={selectedRecords}
                    onSelectedRecordsChange={handleSelectedRecordsChange}
                    noPermission={!perms.USER_LIST}
                />
            </Paper>
            <CreateManagerModal isOpened={isShowCreate} onClose={closeCreate} />
            <ExportPdfModal
                isOpened={isShowExport}
                onClose={closeExport}
                selectedUserIds={selectedUserIds}
                selectAll={selectAllAcrossPages}
                totalUsers={totalUsers}
                filters={filters}
            />
            <ExportCsvModal
                isOpened={isShowCsvExport}
                onClose={closeCsvExport}
                selectedUserIds={selectedUserIds}
                selectAll={selectAllAcrossPages}
                totalUsers={totalUsers}
                filters={filters}
            />
        </>
    );
};

export default AdminUserIndexPage;
