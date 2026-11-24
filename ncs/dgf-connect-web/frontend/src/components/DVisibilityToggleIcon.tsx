import React from 'react';
import { LuEye, LuEyeOff } from 'react-icons/lu';

type Props = {
    reveal: boolean;
};

const DVisibilityToggleIcon = ({ reveal }: Props) =>
    reveal ? (
        <LuEyeOff style={{ width: 'var(--psi-icon-size)', height: 'var(--psi-icon-size)' }} />
    ) : (
        <LuEye style={{ width: 'var(--psi-icon-size)', height: 'var(--psi-icon-size)' }} />
    );

export default DVisibilityToggleIcon;
