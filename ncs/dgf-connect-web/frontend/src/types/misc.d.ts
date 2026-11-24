import React from 'react';

export type Enum = { [key: string]: { value: string; text: string; color?: string } };

export interface IconProps extends React.ComponentPropsWithoutRef<'svg'> {
    size?: number | string;
}
