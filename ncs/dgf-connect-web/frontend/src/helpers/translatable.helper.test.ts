import { describe, expect, it } from 'vitest';
import TranslatableHelper from './translatable.helper';

describe('TranslatableHelper.resolve', () => {
    it('returns the value for the requested language when present', () => {
        expect(TranslatableHelper.resolve({ vi: 'Xin chao', en: 'Hello', ja: 'Konnichiwa' }, 'vi')).toBe('Xin chao');
    });

    it('falls back to English when the requested language is missing', () => {
        expect(TranslatableHelper.resolve({ en: 'Hello', ja: 'Konnichiwa' }, 'ne')).toBe('Hello');
    });

    it('falls back to Vietnamese when neither the requested language nor English is present', () => {
        expect(TranslatableHelper.resolve({ vi: 'Xin chao', ja: 'Konnichiwa' }, 'si')).toBe('Xin chao');
    });

    it('returns an empty string when nothing matches', () => {
        expect(TranslatableHelper.resolve({}, 'vi')).toBe('');
    });

    it('returns an empty string for null or undefined input', () => {
        expect(TranslatableHelper.resolve(null, 'vi')).toBe('');
        expect(TranslatableHelper.resolve(undefined, 'vi')).toBe('');
    });

    it('passes through a plain string unchanged', () => {
        expect(TranslatableHelper.resolve('Plain title', 'vi')).toBe('Plain title');
    });
});
