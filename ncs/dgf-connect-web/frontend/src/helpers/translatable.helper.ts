import { TranslatableText } from '@/configs/exam_language.config';

const TranslatableHelper = {
    /**
     * Resolve a translatable field to a single string for display, falling
     * back to English then Vietnamese - mirrors the backend's fallback order.
     */
    resolve(value: TranslatableText | string | null | undefined, lang: string): string {
        if (!value) return '';
        if (typeof value === 'string') return value;
        return value[lang as keyof TranslatableText] || value.en || value.vi || '';
    },
};

export default TranslatableHelper;
