import dayjs from 'dayjs';
import { UserPaperItem } from '@/types/models/user_paper';
import { UserPaperTypeEnum } from '@/enums/user_paper_type.enum';

const REJECTION_MATCH_WINDOW_MS = 60 * 1000;

const MULTI_FILE_TYPES = [UserPaperTypeEnum.PASSPORT.value, UserPaperTypeEnum.OTHER.value];

export type PaperApprovalGroup = {
    approvedAt: string;
    items: UserPaperItem[];
};

const toTime = (value: string) => dayjs(value).valueOf();

const approvedTime = (item: UserPaperItem) => toTime(item.approved_at as string);

const pickValidFiles = (sameRoundItems: UserPaperItem[]) => {
    if (MULTI_FILE_TYPES.includes(sameRoundItems[0].type)) {
        return sameRoundItems;
    }

    return [sameRoundItems.reduce((newest, item) => (item.id > newest.id ? item : newest))];
};

const isWithinMatchWindow = (a: number, b: number) => Math.abs(a - b) <= REJECTION_MATCH_WINDOW_MS;

export const groupPapersByApproval = (
    items: UserPaperItem[],
    confirmedApprovalAt: string | null = null,
): PaperApprovalGroup[] => {
    const approvedItems = items.filter((item) => item.approved_at && !item.rejected_at && item.folder !== 'deleted');
    const rejectionTimes = items.flatMap((item) => (item.rejected_at ? [toTime(item.rejected_at)] : []));
    const isRejectionRound = (time: number) =>
        rejectionTimes.some((rejectedTime) => isWithinMatchWindow(rejectedTime, time));

    const distinctApprovalTimes = [...new Set(approvedItems.map((item) => item.approved_at as string))].sort(
        (a, b) => toTime(b) - toTime(a),
    );
    const confirmedRound = confirmedApprovalAt
        ? distinctApprovalTimes.find((approvedAt) =>
              isWithinMatchWindow(toTime(approvedAt), toTime(confirmedApprovalAt)),
          )
        : undefined;

    const approvalTimes = distinctApprovalTimes.filter(
        (approvedAt) => approvedAt === confirmedRound || !isRejectionRound(toTime(approvedAt)),
    );

    return approvalTimes.map((approvedAt) => {
        const cutoff = toTime(approvedAt);
        const latestRoundByType = new Map<string, UserPaperItem[]>();

        approvedItems.forEach((item) => {
            const time = approvedTime(item);
            if (time > cutoff) return;

            const latestRound = latestRoundByType.get(item.type);
            const latestTime = latestRound ? approvedTime(latestRound[0]) : -Infinity;

            if (time > latestTime) {
                latestRoundByType.set(item.type, [item]);
            } else if (latestRound && time === latestTime) {
                latestRound.push(item);
            }
        });

        return {
            approvedAt,
            items: [...latestRoundByType.values()].flatMap(pickValidFiles),
        };
    });
};
