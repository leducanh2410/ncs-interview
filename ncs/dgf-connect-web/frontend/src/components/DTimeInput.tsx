import React from 'react';
import { InputBase, TextInputProps } from '@mantine/core';
import { InputMask } from '@react-input/mask';

type Props = TextInputProps & { withSecond?: boolean };

const DTimeInput = ({ placeholder, withSecond = false, ...props }: Props) => {
    if (typeof placeholder === 'undefined') {
        placeholder = withSecond ? 'HH:mm:ss' : 'HH:mm';
    }

    return (
        <InputBase
            placeholder={placeholder}
            component={InputMask}
            mask={withSecond ? 'Hh:Mm:Ss' : 'Hh:Mm'}
            replacement={{ H: /[0-4]/, h: /\d/, M: /[0-5]/, m: /\d/, S: /[0-5]/, s: /\d/ }}
            {...props}
        />
    );
};

export default DTimeInput;
