export type InstructionType = 'LINK' | 'VIDEO' | 'IMAGE';

export const InstructionTypeEnum: { [key in InstructionType]: { value: string; text: string } } = Object.freeze({
    LINK: { value: 'link', text: 'Link' },
    VIDEO: { value: 'video', text: 'Video' },
    IMAGE: { value: 'image', text: 'Image' },
});
