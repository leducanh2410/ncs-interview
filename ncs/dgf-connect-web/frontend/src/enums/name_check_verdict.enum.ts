export type NameCheckVerdict = 'MATCH' | 'PARTIAL' | 'MISMATCH';

export const NameCheckVerdictEnum: { [key in NameCheckVerdict]: { value: string; text: string } } = Object.freeze({
    MATCH: { value: 'match', text: 'Name matches' },
    PARTIAL: { value: 'partial', text: 'Name partially matches' },
    MISMATCH: { value: 'mismatch', text: 'Name does not match' },
});

export const getNameCheckVerdictColor = (verdict: string | null | undefined): string => {
    switch (verdict) {
        case NameCheckVerdictEnum.MATCH.value:
            return 'green';
        case NameCheckVerdictEnum.PARTIAL.value:
            return 'yellow';
        case NameCheckVerdictEnum.MISMATCH.value:
            return 'red';
        default:
            return 'gray';
    }
};
