import { notifications } from '@mantine/notifications';

const NotificationHelper = {
    showSuccess: (message: string, autoClose: number | false = 5000) => {
        notifications.show({
            message,
            color: 'teal',
            autoClose,
        });
    },
    showError: (message: string, color = 'red', autoClose: number | false = 10000) => {
        notifications.show({
            message,
            color,
            autoClose,
        });
    },
    showInfo: (message: string, autoClose: number | false = 7000) => {
        notifications.show({
            message,
            color: 'yellow',
            autoClose,
        });
    },
};

export default NotificationHelper;
