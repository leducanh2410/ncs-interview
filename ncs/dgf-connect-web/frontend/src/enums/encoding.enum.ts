export type Encoding = 'UTF_8' | 'SHIFT_JIS';

export const EncodingEnum: { [key in Encoding]: { value: string; text: string } } = Object.freeze({
    UTF_8: { value: 'utf-8', text: 'UTF-8' },
    SHIFT_JIS: { value: 'shift-jis', text: 'Shift-JIS' },
});
