import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { LuChevronDown, LuChevronRight } from 'react-icons/lu';
import Link from 'next/link';
import { Collapse, Flex, Menu } from '@mantine/core';
import SidebarItem from '@/layout/SidebarItem';
import { SidebarGroup } from '@/configs/sidebar.config';
import classes from './layout.module.css';

type Props = {
    group: SidebarGroup;
    sidebarMatchPath: string;
    collapsed?: boolean;
    onClick?: () => void;
};

const SidebarGroupItem = ({ group, sidebarMatchPath, collapsed, onClick }: Props) => {
    const { t } = useTranslation();

    const isAnyChildActive = group.items.some((item) => sidebarMatchPath === item.route.path);
    const [opened, setOpened] = useState(isAnyChildActive);

    useEffect(() => {
        if (isAnyChildActive) {
            setOpened(true);
        }
    }, [isAnyChildActive]);

    if (collapsed) {
        return (
            <Menu position="right-start" shadow="md" offset={4}>
                <Menu.Target>
                    <button
                        className={`${classes.sidebarGroup} ${isAnyChildActive ? classes.activeGroup : ''} ${classes.sidebarGroupCollapsed}`}
                    >
                        <group.icon size={16} />
                    </button>
                </Menu.Target>
                <Menu.Dropdown>
                    <Menu.Label>{t(`route|${group.group}`)}</Menu.Label>
                    {group.items.map((item) => {
                        const isActive = sidebarMatchPath === item.route.path;
                        return (
                            <Menu.Item
                                key={item.route.path}
                                component={Link}
                                href={item.route.path}
                                leftSection={<item.icon size={14} />}
                                onClick={onClick}
                                bg={isActive ? 'blue.0' : undefined}
                                c={isActive ? 'blue.6' : undefined}
                            >
                                {t(`route|${item.customName || item.route.name}`)}
                            </Menu.Item>
                        );
                    })}
                </Menu.Dropdown>
            </Menu>
        );
    }

    return (
        <>
            <button
                className={`${classes.sidebarGroup} ${isAnyChildActive ? classes.activeGroup : ''}`}
                onClick={() => setOpened((o) => !o)}
            >
                <Flex align="center" gap={12} flex={1}>
                    <group.icon size={16} />
                    {t(`route|${group.group}`)}
                </Flex>
                {opened ? <LuChevronDown size={14} /> : <LuChevronRight size={14} />}
            </button>
            <Collapse expanded={opened}>
                <Flex gap={4} direction="column" pl={12} mt={4}>
                    {group.items.map((item) => (
                        <SidebarItem
                            key={item.route.path}
                            item={item}
                            sidebarMatchPath={sidebarMatchPath}
                            onClick={onClick}
                        />
                    ))}
                </Flex>
            </Collapse>
        </>
    );
};

export default SidebarGroupItem;
