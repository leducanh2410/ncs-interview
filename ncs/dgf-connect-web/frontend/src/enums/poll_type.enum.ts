import { Enum } from '@/types/misc';

export const PollTypeEnum: Enum = Object.freeze({
    VOTE: { value: 'vote', text: 'Vote' },
    FEEDBACK: { value: 'feedback', text: 'Feedback' },
});
