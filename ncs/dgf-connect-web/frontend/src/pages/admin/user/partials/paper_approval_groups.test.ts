import { describe, expect, it } from 'vitest';
import type { UserPaperItem } from '@/types/models/user_paper';
import { groupPapersByApproval } from './paper_approval_groups';

let nextId = 1;

const makeItem = (overrides: Partial<UserPaperItem>): UserPaperItem => {
    const id = nextId++;
    return {
        id,
        user_paper_id: 1,
        type: 'foreigner_card_front',
        file_name: `file${id}.jpg`,
        file_path: `uploads/file${id}.jpg`,
        deleted_from_folder: null,
        approved_at: null,
        rejected_at: null,
        deleted_at: null,
        created_at: '2026-01-01T00:00:00.000000Z',
        folder: 'current',
        ...overrides,
    };
};

const idsOf = (items: UserPaperItem[]) => items.map((item) => item.id).sort((a, b) => a - b);

describe('groupPapersByApproval', () => {
    it('returns no group when nothing has been approved', () => {
        const items = [makeItem({ folder: null })];

        expect(groupPapersByApproval(items)).toEqual([]);
    });

    it('groups the documents approved together into one approval', () => {
        const front = makeItem({ type: 'foreigner_card_front', approved_at: '2026-03-01 10:00:00' });
        const back = makeItem({ type: 'foreigner_card_back', approved_at: '2026-03-01 10:00:00' });
        const portrait = makeItem({ type: 'portrait_image', approved_at: '2026-03-01 10:00:00' });

        const groups = groupPapersByApproval([front, back, portrait]);

        expect(groups).toHaveLength(1);
        expect(groups[0].approvedAt).toBe('2026-03-01 10:00:00');
        expect(idsOf(groups[0].items)).toEqual(idsOf([front, back, portrait]));
    });

    it('carries the documents a renewal did not replace into the newer approval', () => {
        const oldFront = makeItem({ type: 'foreigner_card_front', approved_at: '2026-03-01 10:00:00', folder: 'old' });
        const oldBack = makeItem({ type: 'foreigner_card_back', approved_at: '2026-03-01 10:00:00', folder: 'old' });
        const portrait = makeItem({ type: 'portrait_image', approved_at: '2026-03-01 10:00:00' });
        const newFront = makeItem({ type: 'foreigner_card_front', approved_at: '2026-09-01 09:00:00' });
        const newBack = makeItem({ type: 'foreigner_card_back', approved_at: '2026-09-01 09:00:00' });

        const groups = groupPapersByApproval([oldFront, oldBack, portrait, newFront, newBack]);

        expect(groups.map((group) => group.approvedAt)).toEqual(['2026-09-01 09:00:00', '2026-03-01 10:00:00']);
        expect(idsOf(groups[0].items)).toEqual(idsOf([portrait, newFront, newBack]));
        expect(idsOf(groups[1].items)).toEqual(idsOf([oldFront, oldBack, portrait]));
    });

    it('keeps every passport page approved in the same round', () => {
        const oldPage = makeItem({ type: 'passport', approved_at: '2026-03-01 10:00:00' });
        const newPage1 = makeItem({ type: 'passport', approved_at: '2026-09-01 09:00:00' });
        const newPage2 = makeItem({ type: 'passport', approved_at: '2026-09-01 09:00:00' });

        const groups = groupPapersByApproval([oldPage, newPage1, newPage2]);

        expect(idsOf(groups[0].items)).toEqual(idsOf([newPage1, newPage2]));
        expect(idsOf(groups[1].items)).toEqual(idsOf([oldPage]));
    });

    it('keeps only the newest file of a single-file type when several share one approval time', () => {
        const olderPortrait = makeItem({ type: 'portrait_image', approved_at: '2025-05-11 10:00:00' });
        const newerPortrait = makeItem({ type: 'portrait_image', approved_at: '2025-05-11 10:00:00' });
        const otherFile1 = makeItem({ type: 'other', approved_at: '2025-05-11 10:00:00' });
        const otherFile2 = makeItem({ type: 'other', approved_at: '2025-05-11 10:00:00' });

        const groups = groupPapersByApproval([olderPortrait, newerPortrait, otherFile1, otherFile2]);

        expect(idsOf(groups[0].items)).toEqual(idsOf([newerPortrait, otherFile1, otherFile2]));
    });

    it('does not make an approval of a round that sent the file back, but counts its approved documents later', () => {
        const portrait = makeItem({ type: 'portrait_image', approved_at: '2026-03-01 10:00:00' });
        const rejectedFront = makeItem({
            type: 'foreigner_card_front',
            rejected_at: '2026-03-01 10:00:01',
            folder: 'rejected',
        });
        const front = makeItem({ type: 'foreigner_card_front', approved_at: '2026-03-05 15:00:00' });

        const groups = groupPapersByApproval([portrait, rejectedFront, front]);

        expect(groups).toHaveLength(1);
        expect(groups[0].approvedAt).toBe('2026-03-05 15:00:00');
        expect(idsOf(groups[0].items)).toEqual(idsOf([portrait, front]));
    });

    it('keeps a confirmed approval whose failed portrait was rejected in the same minute', () => {
        const oldPortrait = makeItem({ type: 'portrait_image', approved_at: '2026-03-01 10:00:00' });
        const oldFront = makeItem({ type: 'foreigner_card_front', approved_at: '2026-03-01 10:00:00', folder: 'old' });
        const failedPortrait = makeItem({
            type: 'portrait_image',
            rejected_at: '2026-09-01 09:00:00',
            folder: 'rejected',
        });
        const newFront = makeItem({ type: 'foreigner_card_front', approved_at: '2026-09-01 09:00:00' });

        const groups = groupPapersByApproval([oldPortrait, oldFront, failedPortrait, newFront], '2026-09-01 09:00:01');

        expect(groups.map((group) => group.approvedAt)).toEqual(['2026-09-01 09:00:00', '2026-03-01 10:00:00']);
        expect(idsOf(groups[0].items)).toEqual(idsOf([oldPortrait, newFront]));
    });

    it('still drops a rejection round when the confirmed approval is a different round', () => {
        const portrait = makeItem({ type: 'portrait_image', approved_at: '2026-03-01 10:00:00' });
        const rejectedFront = makeItem({
            type: 'foreigner_card_front',
            rejected_at: '2026-03-01 10:00:00',
            folder: 'rejected',
        });
        const front = makeItem({ type: 'foreigner_card_front', approved_at: '2026-03-05 15:00:00' });

        const groups = groupPapersByApproval([portrait, rejectedFront, front], '2026-03-05 15:00:00');

        expect(groups.map((group) => group.approvedAt)).toEqual(['2026-03-05 15:00:00']);
    });

    it('confirms nothing when no approval round lies near the confirmed time', () => {
        const portrait = makeItem({ type: 'portrait_image', approved_at: '2026-03-01 10:00:00' });
        const rejectedFront = makeItem({
            type: 'foreigner_card_front',
            rejected_at: '2026-03-01 10:00:00',
            folder: 'rejected',
        });

        expect(groupPapersByApproval([portrait, rejectedFront], '2026-09-01 09:00:00')).toEqual([]);
    });

    it('leaves deleted documents out of every approval', () => {
        const kept = makeItem({ type: 'portrait_image', approved_at: '2026-03-01 10:00:00' });
        const deleted = makeItem({
            type: 'foreigner_card_front',
            approved_at: '2026-03-01 10:00:00',
            folder: 'deleted',
            deleted_at: '2026-04-01 10:00:00',
        });

        const groups = groupPapersByApproval([kept, deleted]);

        expect(idsOf(groups[0].items)).toEqual(idsOf([kept]));
    });
});
