import { UseFormReturnType } from '@mantine/form';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { User } from '@/types/models/user';
import { setFormErrors } from '@/pages/manage/shift/partials/update/helper';

vi.mock('@/locales/i18n', () => ({
    default: {
        t: (key: string, options?: { names?: string }) =>
            options?.names === undefined ? key : `${key} [${options.names}]`,
    },
}));

const allShiftUsers: Record<string, User> = {
    '1': { id: 1, name: 'Alice' } as User,
    '2': { id: 2, name: 'Bob' } as User,
};

let form: UseFormReturnType<any>;
let setFieldError: ReturnType<typeof vi.fn>;

beforeEach(() => {
    setFieldError = vi.fn();
    form = { setFieldError } as unknown as UseFormReturnType<any>;
});

describe('setFormErrors: keys handled without a per-user breakdown', () => {
    it('reports users who are not members of the workplace', () => {
        const result = setFormErrors('not_member_ids', '1,2', allShiftUsers, form);

        expect(result).toBeNull();
        expect(setFieldError).toHaveBeenCalledWith(
            'not_member_ids',
            'validation|User is not a member of the workplace: {{names}} [Alice, Bob]',
        );
    });

    it('reports disabled users sent as pending, which carry no position', () => {
        const result = setFormErrors('disabled_ids', '1,2', allShiftUsers, form);

        expect(result).toBeNull();
        expect(setFieldError).toHaveBeenCalledWith('disabled_ids', 'validation|User disabled: {{names}} [Alice, Bob]');
    });

    it.each(['fixed_total', 'temp_total'])('reports %s exceeding the required number', (key) => {
        const result = setFormErrors(key, '1', allShiftUsers, form);

        expect(result).toBeNull();
        expect(setFieldError).toHaveBeenCalledWith(
            key,
            'validation|Number of approved users exceeds the required total',
        );
    });

    it('falls back to the raw value when no user name can be resolved', () => {
        const result = setFormErrors('temp_approved_overlap_3', '999', allShiftUsers, form);

        expect(result).toBeNull();
        expect(setFieldError).toHaveBeenCalledWith('temp_approved_overlap_3', 'validation|999');
    });

    it('returns null for a key that matches no known error', () => {
        const result = setFormErrors('temp_approved_something_else_3', '1', allShiftUsers, form);

        expect(result).toBeNull();
        expect(setFieldError).not.toHaveBeenCalled();
    });
});

describe('setFormErrors: per-position errors', () => {
    it('extracts the position id and marks a temp error as not fixed', () => {
        const result = setFormErrors('temp_approved_overlap_7', '1', allShiftUsers, form);

        expect(result).toEqual({
            isFixed: false,
            positionId: '7',
            ids: ['1'],
            message: 'validation|User has overlapping shifts: {{names}} [Alice]',
            perUserMessage: 'validation|User has overlapping shifts',
        });
        expect(setFieldError).toHaveBeenCalledWith('temp_approved_overlap_7', result!.message);
    });

    it('marks a fixed error as fixed', () => {
        const result = setFormErrors('fixed_approved_overlap_7', '1', allShiftUsers, form);

        expect(result).toMatchObject({ isFixed: true, positionId: '7' });
    });

    it('lists every user id in the error', () => {
        const result = setFormErrors('temp_approved_max_shift_2', '1,2', allShiftUsers, form);

        expect(result).toMatchObject({
            ids: ['1', '2'],
            message: 'validation|User has reached maximum shifts: {{names}} [Alice, Bob]',
        });
    });

    it('skips ids with no matching user when building the name list', () => {
        const result = setFormErrors('temp_approved_overlap_7', '1,999', allShiftUsers, form);

        expect(result).toMatchObject({
            ids: ['1', '999'],
            message: 'validation|User has overlapping shifts: {{names}} [Alice]',
        });
    });

    it.each([
        ['expired_residence', 'validation|Residence card has expired or is about to expire'],
        ['expired_student', 'validation|Student card has expired or is about to expire'],
        ['expired_passport', 'validation|Passport has expired or is about to expire'],
        ['max_shift', 'validation|User has reached maximum shifts'],
        ['overlap', 'validation|User has overlapping shifts'],
        ['min_rest_hours', 'validation|User does not have enough rest time between shifts'],
        ['is_disabled', 'validation|User disabled'],
        ['rejected_paper', 'validation|User paper rejected'],
        ['missing_staff_no', 'validation|User missing staff no'],
    ])('maps the %s error to its message', (errorKey, perUserMessage) => {
        const result = setFormErrors(`temp_approved_${errorKey}_4`, '1', allShiftUsers, form);

        expect(result).toMatchObject({
            positionId: '4',
            perUserMessage,
            message: `${perUserMessage}: {{names}} [Alice]`,
        });
    });
});

describe('setFormErrors: max_hour', () => {
    it('appends the over-hours to each name', () => {
        const result = setFormErrors('temp_approved_max_hour_5', '1:12.50,2:8.00', allShiftUsers, form);

        expect(result).toMatchObject({
            positionId: '5',
            ids: ['1:12.50', '2:8.00'],
            message: 'validation|User has reached maximum hours: {{names}} [Alice:12.50h, Bob:8.00h]',
            perUserMessage: 'validation|User has reached maximum hours',
        });
    });
});
