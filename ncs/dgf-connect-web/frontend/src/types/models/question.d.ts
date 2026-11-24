import { TranslatableText } from '@/configs/exam_language.config';

export interface Option {
    id: string | number;
    content: TranslatableText;
    is_correct: boolean;
}

export interface Question {
    id: string | number;
    content: TranslatableText;
    options: Option[];
}
