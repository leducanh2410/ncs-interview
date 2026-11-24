import { atom } from 'jotai';
import { Role } from '@/types/models/role';
import { User } from '@/types/models/user';
import { WorkplaceSimple } from '@/types/models/workplace';

type AuthData = {
    user: User;
    permissions: string[];
    roles: Role[];
};

type TfaData = {
    key: string;
    qr?: string;
};

export type WorkplaceAccess = {
    shift: number[] | null;
    shift_check_in: number[] | null;
    work_history: number[] | null;
    work_report: number[] | null;
    check_in: number[] | null;
    workplace: number[] | null;
    fixed_schedule: number[] | null;
    fixed_schedule_change_config: number[] | null;
    fixed_schedule_change_request: number[] | null;
};

type MasterData = {
    workplaces: WorkplaceSimple[];
    roles: Role[];
    workplace_access: WorkplaceAccess;
};

export type ChatIntent =
    | { kind: 'open'; conversationId: number }
    | { kind: 'create-direct'; user: User; defaultTitle: string };

export const authAtom = atom<AuthData | null>(null);

export const chatIntentAtom = atom<ChatIntent | null>(null);

export const tfaAtom = atom<TfaData | null>(null);

export const masterAtom = atom<MasterData>({
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
