import React from 'react';
import { useTranslation } from 'react-i18next';
import { Button, FileButton, Flex, Text } from '@mantine/core';
import { User } from '@/types/models/user';
import NotificationHelper from '@/helpers/notification.helper';
import DAvatar from '@/components/DAvatar';
import { useDeleteProfilePictureMutation, useUploadProfilePictureMutation } from '@/pages/my-page/api';

type Props = {
    user: User;
    onUserChanged: (user: User) => void;
};

const ProfilePicture = ({ user, onUserChanged }: Props) => {
    const { t } = useTranslation();
    const uploadMutation = useUploadProfilePictureMutation();
    const deleteMutation = useDeleteProfilePictureMutation();

    const onUpload = async (file: File | null) => {
        if (!file) {
            return;
        }

        try {
            const response = await uploadMutation.mutateAsync(file);
            if (response.data) {
                onUserChanged(response.data);
            }
            NotificationHelper.showSuccess(t('Successfully uploaded profile picture'));
        } catch (e) {
            NotificationHelper.showError(t('error|Could not upload profile picture'));
        }
    };

    const onDelete = async () => {
        try {
            const response = await deleteMutation.mutateAsync();
            if (response.data) {
                onUserChanged(response.data);
            }
            NotificationHelper.showSuccess(t('Successfully deleted profile picture'));
        } catch (e) {
            NotificationHelper.showError(t('error|Could not delete profile picture'));
        }
    };

    return (
        <Flex align="center" mb="lg" gap="md">
            <DAvatar size={128} name={user.name || ''} src={user.profile_picture} />
            <div>
                <Flex gap="4" mb="8">
                    <FileButton onChange={onUpload} accept="image/jpg,image/jpeg,image/png,image/bmp,image/heic">
                        {(props) => (
                            <Button w={120} {...props} loading={uploadMutation.isPending}>
                                {t('Upload')}
                            </Button>
                        )}
                    </FileButton>
                    <Button color="red" w={120} onClick={onDelete} loading={deleteMutation.isPending}>
                        {t('Delete')}
                    </Button>
                </Flex>
                <div>
                    <Text size="xs" c="gray">
                        {t('File extension: jpg, jpeg, png, bpm, heic')}
                    </Text>
                    <Text size="xs" c="gray">
                        {t('Maximum file size: 5MB')}
                    </Text>
                </div>
            </div>
        </Flex>
    );
};

export default ProfilePicture;
