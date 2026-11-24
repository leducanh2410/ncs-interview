import { describe, expect, it } from 'vitest';
import TranslateHelper from '@/helpers/translate.helper';
import { TranslateItem } from '@/pages/translate.api';

const item = (key: string, length: number): TranslateItem => ({ key, text: 'a'.repeat(length) });

describe('TranslateHelper.chunkItems', () => {
    it('returns no chunks for an empty batch', () => {
        expect(TranslateHelper.chunkItems([])).toEqual([]);
    });

    it('keeps a small batch in a single chunk', () => {
        const items = [item('title', 10), item('content', 20)];
        expect(TranslateHelper.chunkItems(items)).toEqual([items]);
    });

    it('splits by max item count while preserving order', () => {
        const items = Array.from({ length: 5 }, (_, i) => item(`q${i}`, 5));
        const chunks = TranslateHelper.chunkItems(items, { maxItems: 2, maxChars: 15000 });

        expect(chunks).toHaveLength(3);
        expect(chunks.flat().map((entry) => entry.key)).toEqual(['q0', 'q1', 'q2', 'q3', 'q4']);
    });

    it('splits by character budget', () => {
        const items = [item('a', 60), item('b', 60), item('c', 60)];
        const chunks = TranslateHelper.chunkItems(items, { maxItems: 40, maxChars: 100 });

        expect(chunks.map((chunk) => chunk.map((entry) => entry.key))).toEqual([['a'], ['b'], ['c']]);
    });

    it('gives an oversized item its own chunk instead of dropping it', () => {
        const items = [item('a', 10), item('big', 500), item('b', 10)];
        const chunks = TranslateHelper.chunkItems(items, { maxItems: 40, maxChars: 100 });

        expect(chunks).toEqual([[items[0]], [items[1]], [items[2]]]);
    });
});
