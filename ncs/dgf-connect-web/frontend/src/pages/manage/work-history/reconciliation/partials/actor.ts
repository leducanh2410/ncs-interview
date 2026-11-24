import { ReconciliationActor } from '@/types/models/reconciliation_batch';

export const actorAvatarUser = (actor: ReconciliationActor) => ({
    id: actor.id,
    name: actor.name,
    staff_no: actor.staff_no ?? null,
    profile_picture: actor.profile_picture ?? null,
});
