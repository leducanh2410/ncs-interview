import { describe, expect, it } from 'vitest';
import { EXAM_FORM_STEP, getExamFormStepForField, getFirstErrorStep, pickStepErrors } from './exam_form_steps';

describe('getExamFormStepForField', () => {
    it.each([
        ['title.en', EXAM_FORM_STEP.general],
        ['min_score', EXAM_FORM_STEP.general],
        ['show_result_immediately', EXAM_FORM_STEP.general],
        ['targeted_users', EXAM_FORM_STEP.general],
        ['documents', EXAM_FORM_STEP.documents],
        ['documents.0', EXAM_FORM_STEP.documents],
        ['questions', EXAM_FORM_STEP.questions],
        ['questions.1.options.0.content.ja', EXAM_FORM_STEP.questions],
    ])('maps %s to step %i', (path, step) => {
        expect(getExamFormStepForField(path)).toBe(step);
    });
});

describe('pickStepErrors', () => {
    it('keeps only the errors of the requested step', () => {
        const errors = {
            'title.en': 'required',
            'documents.0': 'invalid',
            'questions.0.content.en': 'required',
        };

        expect(pickStepErrors(errors, EXAM_FORM_STEP.general)).toEqual({ 'title.en': 'required' });
        expect(pickStepErrors(errors, EXAM_FORM_STEP.questions)).toEqual({ 'questions.0.content.en': 'required' });
    });
});

describe('getFirstErrorStep', () => {
    it('returns null when there is no error', () => {
        expect(getFirstErrorStep([])).toBeNull();
    });

    it('returns the earliest step holding an error regardless of key order', () => {
        expect(getFirstErrorStep(['questions', 'documents.1'])).toBe(EXAM_FORM_STEP.documents);
        expect(getFirstErrorStep(['questions.0.content.en', 'min_score'])).toBe(EXAM_FORM_STEP.general);
        expect(getFirstErrorStep(['questions'])).toBe(EXAM_FORM_STEP.questions);
    });
});
