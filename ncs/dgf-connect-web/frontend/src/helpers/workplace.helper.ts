import type { TFunction } from 'i18next';
import { WorkplaceSimple } from '@/types/models/workplace';
import { WorkplaceAccess } from '@/pages/global.state';

type PermissionGroup = keyof WorkplaceAccess;

/** Minimal shape needed to label/sort a workplace in select controls. */
type WorkplaceLike = { id: number; name: string; is_test?: boolean };

type SelectOption = { value: string; label: string };

class WorkplaceHelper {
    /**
     * Filter workplaces based on user's workplace_access for a specific permission group.
     *
     * @param workplaces - All available workplaces
     * @param workplaceAccess - User's workplace access permissions
     * @param permissionGroup - The permission group to check (shift, shift_check_in, work_history, work_report, check_in, workplace)
     * @returns Filtered workplaces based on access. Returns all if null, empty array if no access, or filtered array if specific workplaces.
     */
    static filterWorkplacesByAccess(
        workplaces: WorkplaceSimple[],
        workplaceAccess: WorkplaceAccess,
        permissionGroup: PermissionGroup,
    ): WorkplaceSimple[] {
        const accessibleIds = workplaceAccess[permissionGroup];

        // null means global access - return all workplaces
        if (accessibleIds === null) {
            return workplaces;
        }

        // empty array means no access - return empty array
        if (accessibleIds.length === 0) {
            return [];
        }

        // filter workplaces by accessible IDs
        return workplaces.filter((workplace) => accessibleIds.includes(workplace.id));
    }

    /**
     * Render a workplace label, prefixing test workplaces with a [Test] marker
     * so they are easy to spot in admin select controls.
     */
    static formatLabel(workplace: WorkplaceLike, t: TFunction): string {
        return workplace.is_test ? t('[Test] {{name}}', { name: workplace.name }) : workplace.name;
    }

    /**
     * Build Mantine select options from workplaces. Test workplaces are
     * labelled with a [Test] prefix; ordering is preserved from the input.
     */
    static toSelectOptions(workplaces: WorkplaceLike[], t: TFunction): SelectOption[] {
        return workplaces.map((workplace) => ({
            value: workplace.id.toString(),
            label: WorkplaceHelper.formatLabel(workplace, t),
        }));
    }
}

export default WorkplaceHelper;
