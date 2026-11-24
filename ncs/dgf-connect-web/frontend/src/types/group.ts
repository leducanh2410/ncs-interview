import { PaginatedData } from '@/types/api';
import { WorkplaceSimple } from '@/types/models/workplace';

export type GroupKind = 'workplace_current' | 'workplace_seniority' | 'worktime' | 'manual' | 'rule_based';

export interface GroupDisplayName {
    en: string;
    ja: string;
    vi: string;
}

export type SourceTier = 'newcomer' | 'regular' | 'veteran' | 'morning' | 'afternoon' | 'night';

export type PredicateClauseField = 'prefecture' | 'japanese_level' | 'gender' | 'nationality';

export interface PredicateClause {
    field: PredicateClauseField;
    values: string[];
}

export interface GroupSourceWorkplace {
    id: number;
    name: string;
}

export interface Group {
    id: number;
    kind: GroupKind;
    name: string | null;
    display_name: GroupDisplayName;
    source_workplace_id: number | null;
    source_workplace: GroupSourceWorkplace | null;
    source_prefecture: string | null;
    source_tier: SourceTier | null;
    predicate: PredicateClause[] | null;
    member_count: number;
}

export interface GroupMember {
    id: number;
    name: string;
    staff_no: string | null;
    current_workplaces: WorkplaceSimple[];
}

export interface GroupDetail extends Group {
    members: PaginatedData<GroupMember>;
}

export type CreateManualGroupPayload = {
    kind: 'manual';
    name: string;
};

export type CreateRuleBasedGroupPayload = {
    kind: 'rule_based';
    name: string;
    predicate: PredicateClause[];
};

export type CreateGroupPayload = CreateManualGroupPayload | CreateRuleBasedGroupPayload;

export interface UpdateGroupPayload {
    name?: string;
    predicate?: PredicateClause[];
}
