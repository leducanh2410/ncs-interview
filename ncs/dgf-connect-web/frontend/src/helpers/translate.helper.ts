import { TranslateItem } from '@/pages/translate.api';

type ChunkOptions = {
    maxItems?: number;
    maxChars?: number;
};

const TranslateHelper = {
    /**
     * Split a translate batch into chunks that stay inside the backend request limits
     * (60 items / 20k chars per call). Order is preserved. An item longer than maxChars
     * still gets its own chunk; the backend enforces the per-item ceiling.
     */
    chunkItems(items: TranslateItem[], { maxItems = 40, maxChars = 15000 }: ChunkOptions = {}): TranslateItem[][] {
        const chunks: TranslateItem[][] = [];
        let current: TranslateItem[] = [];
        let currentChars = 0;

        for (const item of items) {
            const itemChars = item.text.length;
            if (current.length > 0 && (current.length >= maxItems || currentChars + itemChars > maxChars)) {
                chunks.push(current);
                current = [];
                currentChars = 0;
            }
            current.push(item);
            currentChars += itemChars;
        }
        if (current.length > 0) {
            chunks.push(current);
        }

        return chunks;
    },
};

export default TranslateHelper;
