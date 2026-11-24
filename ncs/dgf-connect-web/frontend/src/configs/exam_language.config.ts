export const EXAM_CONTENT_LANGUAGES = ['vi', 'en', 'ja', 'ne', 'si'] as const;

export type ExamContentLanguage = (typeof EXAM_CONTENT_LANGUAGES)[number];

export type TranslatableText = Partial<Record<ExamContentLanguage, string>>;

export const emptyTranslatableText = (): TranslatableText =>
    Object.fromEntries(EXAM_CONTENT_LANGUAGES.map((lang) => [lang, ''])) as TranslatableText;

/** Always selected, cannot be unchecked - see ExamForm's language picker. */
export const REQUIRED_EXAM_LANGUAGE: ExamContentLanguage = 'en';

export const OPTIONAL_EXAM_LANGUAGES = EXAM_CONTENT_LANGUAGES.filter((lang) => lang !== REQUIRED_EXAM_LANGUAGE);
