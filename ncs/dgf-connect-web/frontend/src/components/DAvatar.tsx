import { Ref } from 'react';
import { Avatar, AvatarProps } from '@mantine/core';
import UrlHelper from '@/helpers/url.helper';

type DAvatarProps = AvatarProps & {
    name: string;
    ref?: Ref<HTMLDivElement>;
};

const COLORS = [
    'red',
    'pink',
    'grape',
    'violet',
    'indigo',
    'blue',
    'cyan',
    'teal',
    'green',
    'lime',
    'yellow',
    'orange',
];

const DAvatar = ({ name, src, color, ref, ...rest }: DAvatarProps) => {
    if (src) {
        src = UrlHelper.getStaticUrl(src);
    }

    const words = name.split(/\s+/);
    const letter = words[words.length - 1].charAt(0).toUpperCase();

    if (!color) {
        color = COLORS[letter.charCodeAt(0) % COLORS.length];
    }

    return (
        <Avatar ref={ref} src={src} color={color} {...rest}>
            {letter}
        </Avatar>
    );
};

export default DAvatar;
