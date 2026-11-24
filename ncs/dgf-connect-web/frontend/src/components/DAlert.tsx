import { LuCircleAlert, LuCircleCheck, LuCircleX, LuInfo } from 'react-icons/lu';
import { Alert, AlertProps } from '@mantine/core';
import classes from './DAlert.module.css';

type DAlertProps = Omit<AlertProps, 'color' | 'variant' | 'className' | 'classNames'> & {
    type: 'success' | 'info' | 'warning' | 'danger';
};

const DAlert = ({ type, children, ...props }: DAlertProps) => {
    let color, IconComponent;
    switch (type) {
        case 'success':
            color = 'teal';
            IconComponent = LuCircleCheck;
            break;
        case 'info':
            color = 'blue';
            IconComponent = LuInfo;
            break;
        case 'warning':
            color = 'orange';
            IconComponent = LuCircleAlert;
            break;
        case 'danger':
            color = 'red';
            IconComponent = LuCircleX;
    }

    return (
        <Alert
            data-type={type}
            color={`${color}.1`}
            variant="filled"
            classNames={{ root: classes.root, title: classes.title, body: classes.body, icon: classes.icon }}
            icon={<IconComponent size={24} />}
            style={{ '--alert-color': 'var(--mantine-color-dark-4)' }}
            {...props}
        >
            {children}
        </Alert>
    );
};

export default DAlert;
