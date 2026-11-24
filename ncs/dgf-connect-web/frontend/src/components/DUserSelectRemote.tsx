import { ComponentProps } from 'react';
import { ComboboxItem } from '@mantine/core';
import { User } from '@/types/models/user';
import { ApiConfig } from '@/configs/api.config';
import DSelectRemote from '@/components/DSelectRemote';
import DSelectRemoteMultiple from '@/components/DSelectRemoteMultiple';

const renderUserItem = (user: User): ComboboxItem => ({
    value: user.id.toString(),
    label: `${user.name} (${user.staff_no || '-'})`,
});

type DUserSelectRemoteProps = Omit<ComponentProps<typeof DSelectRemote<User>>, 'uri' | 'renderItem'>;

const DUserSelectRemote = (props: DUserSelectRemoteProps) => {
    return <DSelectRemote<User> uri={ApiConfig.ADMIN_MASTER_USER_SEARCH} renderItem={renderUserItem} {...props} />;
};

type DUserSelectRemoteMultipleProps = Omit<ComponentProps<typeof DSelectRemoteMultiple<User>>, 'uri' | 'renderItem'>;

const DUserSelectRemoteMultiple = (props: DUserSelectRemoteMultipleProps) => {
    return (
        <DSelectRemoteMultiple<User> uri={ApiConfig.ADMIN_MASTER_USER_SEARCH} renderItem={renderUserItem} {...props} />
    );
};

export { renderUserItem };
export { DUserSelectRemoteMultiple };
export default DUserSelectRemote;
