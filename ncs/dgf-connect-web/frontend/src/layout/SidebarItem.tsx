import { useTranslation } from 'react-i18next';
import Link from 'next/link';
import { Anchor, Tooltip } from '@mantine/core';
import { SidebarConfigItem } from '@/configs/sidebar.config';
import classes from './layout.module.css';

type Props = {
    item: SidebarConfigItem;
    sidebarMatchPath: string;
    collapsed?: boolean;
    onClick?: () => void;
};

const SidebarItem = ({ item, sidebarMatchPath, collapsed, onClick }: Props) => {
    const { t } = useTranslation();

    const isActive = sidebarMatchPath === item.route.path;
    const label = t(`route|${item.customName || item.route.name}`);

    return (
        <Tooltip label={label} position="right" disabled={!collapsed} withArrow>
            <Anchor
                component={Link}
                href={item.route.path}
                underline="never"
                className={`${classes.sidebarItem} ${isActive ? classes.active : ''} ${collapsed ? classes.sidebarItemCollapsed : ''}`}
                onClick={onClick}
            >
                <item.icon size={16} />
                {!collapsed && label}
            </Anchor>
        </Tooltip>
    );
};

export default SidebarItem;
