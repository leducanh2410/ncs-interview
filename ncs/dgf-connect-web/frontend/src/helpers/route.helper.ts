import RouteConfig, { RouteInfo, RouteName } from '@/configs/route.config';
import SidebarConfig, { SidebarConfigItem, SidebarGap, SidebarGroup } from '@/configs/sidebar.config';

const RouteHelper = {
    getRouteConfig: (path: string): RouteInfo | null => {
        for (const info of Object.values(RouteConfig)) {
            if (RouteHelper.isMatchPattern(path, info.path)) {
                return info;
            }
        }
        return null;
    },
    isMatchPattern: (path: string, pattern: string) => {
        if (pattern.indexOf('[id]') > -1) {
            const regexp = new RegExp(pattern.replace(/\[id]/g, '\\d+'));
            return regexp.test(path);
        }
        return path === pattern;
    },

    getPath(routeName: RouteName, params: Record<string, string | number>): string {
        let path = RouteConfig[routeName].path;
        for (const key of Object.keys(params)) {
            path = path.replace(`[${key}]`, params[key].toString());
        }
        return path;
    },

    // Sidebar
    isSidebarGap: (item: SidebarConfigItem | SidebarGap | SidebarGroup): item is SidebarGap => {
        return (item as SidebarGap).gap !== undefined;
    },
    isSidebarGroup: (item: SidebarConfigItem | SidebarGap | SidebarGroup): item is SidebarGroup => {
        return (item as SidebarGroup).group !== undefined;
    },
    getBestMatch: (path: string) => {
        const allItems: SidebarConfigItem[] = [];
        for (const item of SidebarConfig) {
            if (RouteHelper.isSidebarGap(item)) continue;
            if (RouteHelper.isSidebarGroup(item)) {
                allItems.push(...item.items);
            } else {
                allItems.push(item);
            }
        }

        const result = {
            index: 999,
            match: '/',
        };
        for (const item of allItems) {
            if (item.route.path === '/') {
                continue;
            }
            if (RouteHelper.isMatchPattern(path, item.route.path)) {
                return item.route.path;
            }

            const parts = [item.route.path, ...item.route.path.split('/').slice(2)];
            for (const part of parts) {
                const index = path.indexOf(part);
                if (index === -1 || index > result.index) {
                    continue;
                }
                if (index < result.index || part.length > result.match.length) {
                    result.match = item.route.path;
                    result.index = index;
                }
            }
        }

        if (result.match === '/' && path !== result.match) {
            return '';
        }

        return result.match;
    },
};

export default RouteHelper;
