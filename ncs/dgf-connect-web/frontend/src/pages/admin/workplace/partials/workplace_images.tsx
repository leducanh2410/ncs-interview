import React, { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { LuChevronLeft, LuChevronRight, LuCloudUpload, LuTrash2 } from 'react-icons/lu';
import { Carousel } from '@mantine/carousel';
import { AspectRatio, FileButton, Flex, Image, Skeleton } from '@mantine/core';
import { notifications } from '@mantine/notifications';
import UrlHelper from '@/helpers/url.helper';
import DConfirmModal from '@/components/DConfirmModal';
import classes from './workplace_images.module.css';
import { usePermissions } from '@/hooks/usePermission';
import {
    useDeleteWorkplaceImageMutation,
    useGetWorkplaceImagesQuery,
    useUploadWorkplaceImagesMutation,
} from '@/pages/admin/workplace/api';

const WorkplaceImages = ({ workplaceId }: Props) => {
    const { t } = useTranslation();
    const perms = usePermissions(['WORKPLACE_IMAGE_LIST', 'WORKPLACE_IMAGE_UPLOAD', 'WORKPLACE_IMAGE_DELETE']);
    const { data, isFetching } = useGetWorkplaceImagesQuery(workplaceId, perms.WORKPLACE_IMAGE_LIST);
    const uploadMutation = useUploadWorkplaceImagesMutation(workplaceId);
    const deleteMutation = useDeleteWorkplaceImageMutation(workplaceId);
    const [deleteImageId, setDeleteImageId] = useState<number | null>(null);

    if (isFetching) {
        return (
            <AspectRatio ratio={16 / 9} w={240}>
                <Skeleton height="100%" />
            </AspectRatio>
        );
    }

    const onDeleteImage = async (id: number) => {
        try {
            await deleteMutation.mutateAsync(id.toString());
            notifications.show({
                color: 'teal',
                message: t('Successfully deleted workplace image'),
            });
        } catch (e) {
            notifications.show({
                color: 'red',
                message: t('error|Could not delete workplace image'),
            });
        }
    };

    const images = data?.data || [];
    if (!images.length) {
        images.push({ id: 0, workplace_id: Number(workplaceId), image: '' });
    }

    return (
        <>
            <Carousel
                withIndicators
                w={240}
                emblaOptions={{ loop: true }}
                controlsOffset="4"
                draggable={false}
                nextControlIcon={
                    <Flex align="center" justify="center" w={20} h={20} style={{ borderRadius: '50%' }} bg="white">
                        <LuChevronRight size={16} color="blue.6" />
                    </Flex>
                }
                previousControlIcon={
                    <Flex align="center" justify="center" w={20} h={20} style={{ borderRadius: '50%' }} bg="white">
                        <LuChevronLeft size={16} color="blue.6" />
                    </Flex>
                }
            >
                {images.map((item) => {
                    return (
                        <Carousel.Slide key={item.id}>
                            <AspectRatio ratio={16 / 9} w={240} className={classes.wrapper}>
                                <Image
                                    src={UrlHelper.getStaticUrl(item.image)}
                                    fallbackSrc="https://placehold.co/600x400?text=No%20picture"
                                />
                                <div className={classes.overlay}>
                                    <Flex gap={12}>
                                        {item.id !== 0 && perms.WORKPLACE_IMAGE_DELETE && (
                                            <LuTrash2
                                                className={classes.icon}
                                                size={24}
                                                onClick={() => setDeleteImageId(item.id)}
                                            />
                                        )}
                                        {perms.WORKPLACE_IMAGE_UPLOAD && (
                                            <FileButton
                                                onChange={async (files) => {
                                                    if (!files) return;
                                                    const formData = new FormData();
                                                    for (const file of files) {
                                                        formData.append('images[]', file);
                                                    }
                                                    try {
                                                        await uploadMutation.mutateAsync(formData);
                                                        notifications.show({
                                                            color: 'teal',
                                                            message: t('Successfully uploaded workplace image'),
                                                        });
                                                    } catch (e) {
                                                        notifications.show({
                                                            color: 'red',
                                                            message: t('error|Could not upload workplace image'),
                                                        });
                                                    }
                                                }}
                                                accept="image/*"
                                                multiple
                                            >
                                                {(props) => (
                                                    <LuCloudUpload className={classes.icon} size={24} {...props} />
                                                )}
                                            </FileButton>
                                        )}
                                    </Flex>
                                </div>
                            </AspectRatio>
                        </Carousel.Slide>
                    );
                })}
            </Carousel>
            <DConfirmModal
                opened={deleteImageId !== null}
                title={t('Delete image')}
                message={t('Are you sure you want to delete this image?')}
                confirmButtonLabel={t('Delete')}
                confirmButtonColor="red"
                onCancel={() => {
                    setDeleteImageId(null);
                }}
                onConfirm={async () => {
                    await onDeleteImage(deleteImageId!);
                    setDeleteImageId(null);
                }}
            />
        </>
    );
};

type Props = {
    workplaceId: string;
};

export default WorkplaceImages;
