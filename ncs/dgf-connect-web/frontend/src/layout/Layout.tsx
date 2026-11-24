import React, { ReactNode, useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { LuBell, LuChevronsLeft, LuChevronsRight, LuLogOut, LuMessageCircle, LuSettings, LuUser } from 'react-icons/lu';
import Link from 'next/link';
import { usePathname } from 'next/navigation';
import { useRouter } from 'next/router';
import { ActionIcon, AppShell, Box, Burger, Flex, Image, Loader, Menu, ScrollArea, Text, Tooltip } from '@mantine/core';
import { useDisclosure, useMediaQuery } from '@mantine/hooks';
import { notifications } from '@mantine/notifications';
import { useAtom, useAtomValue } from 'jotai';
import i18n from '@/locales/i18n';
import SidebarGroupItem from '@/layout/SidebarGroupItem';
import SidebarItem from '@/layout/SidebarItem';
import RouteConfig from '@/configs/route.config';
import SidebarConfig from '@/configs/sidebar.config';
import { ErrorCodeEnum } from '@/enums/error_code.enum';
import { PermissionNameEnum } from '@/enums/permission_name.enum';
import { disconnectChatEcho } from '@/helpers/chat_echo.helper';
import RoleHelper from '@/helpers/role.helper';
import RouteHelper from '@/helpers/route.helper';
import AdminChatPortal from '@/components/AdminChatPortal';
import DAvatar from '@/components/DAvatar';
import Error403 from '@/pages/403.page';
import { authAtom, chatIntentAtom, masterAtom, tfaAtom } from '@/pages/global.state';
import { useGetMasterData } from '@/pages/master.api';
import classes from './layout.module.css';
import { authCheck, useLogoutMutation } from '@/pages/auth/api';

type Props = {
    children: ReactNode;
};

const Layout = ({ children }: Props) => {
    const { t } = useTranslation();
    const [opened, { toggle, close }] = useDisclosure();
    const [chatOpened, { open: openChat, close: closeChat }] = useDisclosure(false);
    const [sidebarCollapsed, setSidebarCollapsed] = useState(false);
    const isDesktop = useMediaQuery('(min-width: 48em)');
    const effectiveCollapsed = sidebarCollapsed && !!isDesktop;
    const pathname = usePathname();
    const { push } = useRouter();
    const [auth, setAuth] = useAtom(authAtom);
    const [_, setTfa] = useAtom(tfaAtom);
    const [__, setMasterData] = useAtom(masterAtom);
    const chatIntent = useAtomValue(chatIntentAtom);
    const logoutMutation = useLogoutMutation();
    const [isLoading, setIsLoading] = useState<boolean>(true);
    const { data: masterData, isLoading: isLoadingMasterData } = useGetMasterData(auth !== null);

    useEffect(() => {
        const stored = localStorage.getItem('sidebar_collapsed');
        if (stored === 'true') {
            setSidebarCollapsed(true);
        }
    }, []);

    useEffect(() => {
        if (!masterData || !masterData.data) {
            return;
        }

        const data = masterData.data;
        setMasterData(data);
    }, [masterData]);

    useEffect(() => {
        if (chatIntent) {
            openChat();
        }
    }, [chatIntent, openChat]);

    const sidebarMatchPath = RouteHelper.getBestMatch(pathname ?? '');

    const toggleSidebarCollapse = () => {
        const newValue = !sidebarCollapsed;
        setSidebarCollapsed(newValue);
        localStorage.setItem('sidebar_collapsed', String(newValue));
    };

    useEffect(() => {
        const controller = new AbortController();
        authCheck({ abortSignal: controller.signal })
            .then((response) => {
                if (response.data) {
                    setAuth({
                        user: response.data.user,
                        permissions: response.data.permissions,
                        roles: response.data.roles,
                    });
                    if (response.data.user.settings && response.data.user.settings.lang) {
                        i18n.changeLanguage(response.data.user.settings.lang).catch((e) => console.error(e));
                    }
                }
            })
            .catch((e) => {
                if (e.message === ErrorCodeEnum.AUTH_INVALID_TOKEN.value) {
                    localStorage.removeItem('access_token');
                    disconnectChatEcho();
                    notifications.show({
                        color: 'red',
                        message: t('error|Your session has expired, please login again'),
                    });
                }
            })
            .finally(() => {
                if (!controller.signal.aborted) {
                    setIsLoading(false);
                }
            });

        return () => {
            controller.abort();
        };
    }, []);

    if (isLoading || isLoadingMasterData) {
        return (
            <Flex h="100vh" w="100vw" align="center" justify="center">
                <Loader />
            </Flex>
        );
    }

    const routeConfig = RouteHelper.getRouteConfig(pathname);
    if (routeConfig?.isPublic) {
        return children;
    }

    if (!auth) {
        push(RouteConfig.AUTH_LOGIN.path).catch((e) => console.error(e));
        return;
    }

    if (routeConfig && !RoleHelper.canAccessRoute(auth, routeConfig)) {
        return <Error403 />;
    }

    const canViewChat = RoleHelper.hasAnyPermission(auth.permissions, [PermissionNameEnum.CHAT_VIEW.value]);

    const onLogout = async () => {
        try {
            await logoutMutation.mutateAsync();
            localStorage.removeItem('access_token');
            disconnectChatEcho();
            setAuth(null);
            setTfa(null);
            setMasterData({
                workplaces: [],
                roles: [],
                workplace_access: {
                    shift: null,
                    shift_check_in: null,
                    work_history: null,
                    work_report: null,
                    check_in: null,
                    workplace: null,
                    fixed_schedule: null,
                    fixed_schedule_change_config: null,
                    fixed_schedule_change_request: null,
                },
            });
        } catch (e) {
            console.error(e);
        }
    };

    return (
        <AppShell
            header={{ height: 48 }}
            navbar={{
                width: effectiveCollapsed ? 68 : 264,
                breakpoint: 'sm',
                collapsed: { mobile: !opened },
            }}
            padding="lg"
            withBorder={false}
        >
            <AppShell.Header className={classes.header}>
                <Flex align="center" justify="space-between" h="100%" p="md">
                    <Flex gap="xs" align="center" justify="center">
                        <Burger opened={opened} onClick={toggle} hiddenFrom="sm" size="sm" />
                        <Image src="/logo.png" w={28} h={28} fit="contain" />
                        <Text size="xl" fw="700" c="gray.8">
                            DGF Connect
                        </Text>
                    </Flex>
                    <Flex align="center" gap="md">
                        <Text truncate="end" visibleFrom="sm">
                            👋&nbsp;&nbsp;{t('Welcome')} {auth.user.name}
                        </Text>
                        {canViewChat && (
                            <Tooltip label={t('Open chat')} withArrow>
                                <ActionIcon
                                    variant="subtle"
                                    color="gray"
                                    onClick={openChat}
                                    aria-label={t('Open chat')}
                                >
                                    <LuMessageCircle size={18} />
                                </ActionIcon>
                            </Tooltip>
                        )}
                        <LuBell size={16} className={classes.icon} />
                        <Menu shadow="md" width={200}>
                            <Menu.Target>
                                <DAvatar
                                    size="sm"
                                    name={auth.user.name}
                                    src={auth.user.profile_picture}
                                    style={{ cursor: 'pointer' }}
                                />
                            </Menu.Target>
                            <Menu.Dropdown>
                                <Menu.Item
                                    leftSection={<LuUser />}
                                    component={Link}
                                    href={RouteConfig.MY_PAGE_PROFILE.path}
                                >
                                    {t('Profile')}
                                </Menu.Item>
                                <Menu.Item
                                    leftSection={<LuSettings />}
                                    component={Link}
                                    href={RouteConfig.MY_PAGE_SETTINGS.path}
                                >
                                    {t('Settings')}
                                </Menu.Item>
                                <Menu.Divider />
                                <Menu.Item leftSection={<LuLogOut />} onClick={onLogout}>
                                    {t('Logout')}
                                </Menu.Item>
                            </Menu.Dropdown>
                        </Menu>
                    </Flex>
                </Flex>
            </AppShell.Header>
            <AppShell.Navbar p={effectiveCollapsed ? 'xs' : 'md'} bg="white" c="dark.4" className={classes.navbar}>
                <ScrollArea h="100%" flex={1}>
                    <Flex gap={4} mt={8} direction="column" align={effectiveCollapsed ? 'center' : undefined}>
                        {SidebarConfig.map((item) => {
                            if (RouteHelper.isSidebarGap(item)) {
                                return <Box key={item.key} h={item.gap} />;
                            }
                            if (RouteHelper.isSidebarGroup(item)) {
                                const permittedItems = item.items.filter((child) =>
                                    RoleHelper.canAccessRoute(auth, child.route),
                                );
                                if (permittedItems.length === 0) return null;
                                return (
                                    <SidebarGroupItem
                                        key={item.group}
                                        group={{ ...item, items: permittedItems }}
                                        sidebarMatchPath={sidebarMatchPath}
                                        collapsed={effectiveCollapsed}
                                        onClick={close}
                                    />
                                );
                            }
                            if (!RoleHelper.canAccessRoute(auth, item.route)) {
                                return null;
                            }
                            return (
                                <SidebarItem
                                    key={item.route.path}
                                    item={item}
                                    sidebarMatchPath={sidebarMatchPath}
                                    collapsed={effectiveCollapsed}
                                    onClick={close}
                                />
                            );
                        })}
                    </Flex>
                </ScrollArea>
                <Tooltip label={t(sidebarCollapsed ? 'Expand' : 'Collapse')} position="right" withArrow>
                    <button
                        className={classes.collapseToggle}
                        onClick={toggleSidebarCollapse}
                        aria-label={t(sidebarCollapsed ? 'Expand' : 'Collapse')}
                    >
                        {sidebarCollapsed ? <LuChevronsRight size={16} /> : <LuChevronsLeft size={16} />}
                    </button>
                </Tooltip>
            </AppShell.Navbar>
            <AppShell.Main bg="#f4f6f7" pr="md" pb="md" className={classes.main}>
                {children}
                <AdminChatPortal opened={chatOpened} onClose={closeChat} />
            </AppShell.Main>
        </AppShell>
    );
};

export default Layout;
