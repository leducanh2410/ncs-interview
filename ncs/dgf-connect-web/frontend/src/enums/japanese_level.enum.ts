export type JapaneseLevel = 'N1' | 'N2' | 'N3' | 'N4' | 'N5' | 'NONE';

export const JapaneseLevelEnum: { [key in JapaneseLevel]: { value: string; text: string } } = Object.freeze({
    N1: { value: 'n1', text: 'N1' },
    N2: { value: 'n2', text: 'N2' },
    N3: { value: 'n3', text: 'N3' },
    N4: { value: 'n4', text: 'N4' },
    N5: { value: 'n5', text: 'N5' },
    NONE: { value: 'none', text: 'None' },
});
