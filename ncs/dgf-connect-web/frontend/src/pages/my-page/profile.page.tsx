import React from 'react';
import { useTranslation } from 'react-i18next';
import { Paper, Title } from '@mantine/core';
import { useAtom } from 'jotai/index';
import { User } from '@/types/models/user';
import { authAtom } from '@/pages/global.state';
import UserPassword from '@/pages/my-page/partials/password';
import ProfilePicture from '@/pages/my-page/partials/profile_picture';
import UserInfo from '@/pages/my-page/partials/user_info';

const ProfilePage = () => {
    const { t } = useTranslation();
    const [auth, setAuth] = useAtom(authAtom);

    const user = auth?.user as User;
    const setUser = (user: User) =>
        setAuth((prev) => {
            if (!prev) return prev;
            return {
                ...prev,
                user: user,
            };
        });

    return (
        <>
            <Title order={4} lh="48px">
                {t('User profile')}
            </Title>
            <Paper mt="md" p="md">
                <ProfilePicture user={user} onUserChanged={setUser} />
                <UserInfo user={user} onUserChanged={setUser} />
                <UserPassword user={user} onUserChanged={setUser} />
            </Paper>
        </>
    );
};

export default ProfilePage;
