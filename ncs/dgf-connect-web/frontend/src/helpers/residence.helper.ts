export type ResidenceCard = {
    number: string | null;
    date: string | null;
    status: string | null;
};

export type ResidenceInfoLike = {
    residence_number_1?: string | null;
    residence_expiry_date_1?: string | null;
    residence_status_1?: string | null;
    residence_number_2?: string | null;
    residence_expiry_date_2?: string | null;
    residence_status_2?: string | null;
    residence_number_3?: string | null;
    residence_expiry_date_3?: string | null;
    residence_status_3?: string | null;
};

// Returns the most relevant residence card: the one with the latest expiry date, falling back to
// the first card that holds any data. Keeps everything that reads residence info (detail display,
// study-abroad validation, OCR comparison) in sync with the expiry alert, which the backend derives
// from the latest of the three expiry dates.
export const getLatestResidenceCard = (cards: ResidenceCard[]): ResidenceCard | null => {
    let latest: ResidenceCard | null = null;
    let latestTime: number | null = null;

    for (const card of cards) {
        if (!card.date) continue;
        const time = new Date(card.date).getTime();
        if (Number.isNaN(time)) continue;
        if (latestTime === null || time >= latestTime) {
            latestTime = time;
            latest = card;
        }
    }

    if (latest === null) {
        latest = cards.find((card) => card.number || card.status) ?? cards[0] ?? null;
    }

    return latest;
};

export const getLatestResidenceCardFromInfo = (info: ResidenceInfoLike | null | undefined): ResidenceCard | null =>
    getLatestResidenceCard(getResidenceCardsFromInfo(info));

const RESIDENCE_NUMBER_WHITESPACE = /[ \t\n\r 　]/g;

export const normalizeResidenceNumber = (number: string | null | undefined): string | null => {
    if (!number) return null;
    const normalized = number.replace(RESIDENCE_NUMBER_WHITESPACE, '').toUpperCase();
    return normalized === '' ? null : normalized;
};

export const getResidenceCardsFromInfo = (info: ResidenceInfoLike | null | undefined): ResidenceCard[] => [
    {
        number: info?.residence_number_1 ?? null,
        date: info?.residence_expiry_date_1 ?? null,
        status: info?.residence_status_1 ?? null,
    },
    {
        number: info?.residence_number_2 ?? null,
        date: info?.residence_expiry_date_2 ?? null,
        status: info?.residence_status_2 ?? null,
    },
    {
        number: info?.residence_number_3 ?? null,
        date: info?.residence_expiry_date_3 ?? null,
        status: info?.residence_status_3 ?? null,
    },
];

export const getResidenceCardForNumber = (
    info: ResidenceInfoLike | null | undefined,
    number: string | null | undefined,
): ResidenceCard | null => {
    const needle = normalizeResidenceNumber(number);
    const cards = getResidenceCardsFromInfo(info);
    const holding = needle === null ? [] : cards.filter((card) => normalizeResidenceNumber(card.number) === needle);

    return getLatestResidenceCard(holding.length > 0 ? holding : cards);
};
