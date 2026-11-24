export const EXAM_FORM_STEP = {
    general: 0,
    documents: 1,
    questions: 2,
} as const;

export type ExamFormStep = (typeof EXAM_FORM_STEP)[keyof typeof EXAM_FORM_STEP];

export const EXAM_FORM_LAST_STEP: ExamFormStep = EXAM_FORM_STEP.questions;

const rootFieldOf = (path: string): string => path.split('.')[0];

export function getExamFormStepForField(path: string): ExamFormStep {
    const root = rootFieldOf(path);
    if (root === 'documents') {
        return EXAM_FORM_STEP.documents;
    }
    if (root === 'questions') {
        return EXAM_FORM_STEP.questions;
    }
    return EXAM_FORM_STEP.general;
}

export function pickStepErrors<T>(errors: Record<string, T>, step: ExamFormStep): Record<string, T> {
    return Object.fromEntries(Object.entries(errors).filter(([path]) => getExamFormStepForField(path) === step));
}

export function getFirstErrorStep(errorPaths: string[]): ExamFormStep | null {
    if (errorPaths.length === 0) {
        return null;
    }
    return Math.min(...errorPaths.map(getExamFormStepForField)) as ExamFormStep;
}
