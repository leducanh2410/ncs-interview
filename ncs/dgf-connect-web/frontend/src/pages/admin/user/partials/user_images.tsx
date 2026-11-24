import React from 'react';
import { useTranslation } from 'react-i18next';
import { LuChevronLeft, LuChevronRight, LuCloudUpload } from 'react-icons/lu';
import { Carousel } from '@mantine/carousel';
import { Box, FileButton, Flex, Image, Loader } from '@mantine/core';
import { notifications } from '@mantine/notifications';
import UrlHelper from '@/helpers/url.helper';
import classes from './user_images.module.css';
import { usePermissions } from '@/hooks/usePermission';
import { UserResponseData, useUploadImageMutation } from '@/pages/admin/user/api';

const UserImages = ({ id, user, isLoading }: Props) => {
    const { t } = useTranslation();
    const { mutateAsync: uploadImage } = useUploadImageMutation(id);
    const perms = usePermissions(['USER_IMAGE_LIST', 'USER_IMAGE_UPLOAD']);

    const images = user?.images || [];
    if (!images.length) {
        images.push({ id: 0, user_id: Number(id), path: '' });
    }

    if (!perms.USER_IMAGE_LIST) {
        return <Box w={200} h={250}></Box>;
    }

    return (
        <Box w={200} h={250}>
            <Loader w="100%" h="100%">
                {!isLoading && user && (
                    <Carousel
                        withIndicators
                        emblaOptions={{ loop: true }}
                        w="100%"
                        h="100%"
                        controlsOffset="4"
                        draggable={false}
                        nextControlIcon={
                            <Flex
                                align="center"
                                justify="center"
                                w={20}
                                h={20}
                                style={{ borderRadius: '50%' }}
                                bg="white"
                            >
                                <LuChevronRight size={16} color="blue.6" />
                            </Flex>
                        }
                        previousControlIcon={
                            <Flex
                                align="center"
                                justify="center"
                                w={20}
                                h={20}
                                style={{ borderRadius: '50%' }}
                                bg="white"
                            >
                                <LuChevronLeft size={16} color="blue.6" />
                            </Flex>
                        }
                    >
                        {images.map((image) => (
                            <Carousel.Slide key={image.id}>
                                <Box className={classes.wrapper}>
                                    <Image
                                        src={image.path ? UrlHelper.getStaticUrl(image.path) : null}
                                        w={200}
                                        h={250}
                                        fallbackSrc="https://placehold.co/200x250?text=No%20picture"
                                        fit="cover"
                                    />
                                    <div className={classes.overlay}>
                                        <Flex gap={12}>
                                            {perms.USER_IMAGE_UPLOAD && (
                                                <FileButton
                                                    onChange={async (file) => {
                                                        if (!file) return;
                                                        const formData = new FormData();
                                                        formData.append('file', file);
                                                        try {
                                                            await uploadImage(formData);
                                                            notifications.show({
                                                                color: 'teal',
                                                                message: t('Successfully uploaded user image'),
                                                            });
                                                        } catch (e) {
                                                            notifications.show({
                                                                color: 'red',
                                                                message: t('error|Could not upload user image'),
                                                            });
                                                        }
                                                    }}
                                                    accept="image/*"
                                                >
                                                    {(props) => (
                                                        <LuCloudUpload className={classes.icon} size={24} {...props} />
                                                    )}
                                                </FileButton>
                                            )}
                                        </Flex>
                                    </div>
                                </Box>
                            </Carousel.Slide>
                        ))}
                    </Carousel>
                )}
            </Loader>
        </Box>
    );
};

type Props = {
    id: string;
    user: UserResponseData | null | undefined;
    isLoading: boolean;
};

export default UserImages;
