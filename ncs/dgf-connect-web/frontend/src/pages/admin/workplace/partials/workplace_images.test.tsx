import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import WorkplaceImages from './workplace_images';

const {
    useGetWorkplaceImagesQueryMock,
    useUploadWorkplaceImagesMutationMock,
    useDeleteWorkplaceImageMutationMock,
    usePermissionsMock,
    notificationsMock,
} = vi.hoisted(() => ({
    useGetWorkplaceImagesQueryMock: vi.fn(),
    useUploadWorkplaceImagesMutationMock: vi.fn(),
    useDeleteWorkplaceImageMutationMock: vi.fn(),
    usePermissionsMock: vi.fn(),
    notificationsMock: { show: vi.fn() },
}));

vi.mock('react-i18next', () => ({
    useTranslation: () => ({ t: (key: string) => key }),
}));

vi.mock('@mantine/notifications', () => ({
    notifications: notificationsMock,
}));

vi.mock('@mantine/carousel', () => {
    const Carousel = ({ children }: any) => React.createElement('div', { 'data-testid': 'carousel' }, children);
    Carousel.Slide = ({ children }: any) => React.createElement('div', { 'data-testid': 'slide' }, children);
    return { Carousel };
});

vi.mock('@mantine/core', () => ({
    AspectRatio: ({ children }: any) => React.createElement('div', null, children),
    FileButton: ({ children, onChange }: any) =>
        React.createElement('div', null, [
            React.createElement('input', {
                'key': 'file',
                'type': 'file',
                'multiple': true,
                'data-testid': 'file-input',
                'onChange': (e: any) => onChange?.(Array.from(e.target.files ?? [])),
            }),
            children({}),
        ]),
    Flex: ({ children }: any) => React.createElement('div', null, children),
    Image: ({ src, fallbackSrc }: any) => React.createElement('img', { src: src || fallbackSrc, alt: '' }),
    Skeleton: () => React.createElement('div', { 'data-testid': 'skeleton' }),
}));

vi.mock('@/hooks/usePermission', () => ({
    usePermissions: (...args: unknown[]) => usePermissionsMock(...args),
}));

vi.mock('@/pages/admin/workplace/api', () => ({
    useGetWorkplaceImagesQuery: (...args: unknown[]) => useGetWorkplaceImagesQueryMock(...args),
    useUploadWorkplaceImagesMutation: () => useUploadWorkplaceImagesMutationMock(),
    useDeleteWorkplaceImageMutation: () => useDeleteWorkplaceImageMutationMock(),
}));

vi.mock('@/components/DConfirmModal', () => ({
    default: ({ opened, onConfirm, onCancel }: any) => {
        if (!opened) return null;
        return React.createElement(
            'div',
            { 'data-testid': 'confirm-modal' },
            React.createElement('button', { 'onClick': onConfirm, 'data-testid': 'confirm-btn' }, 'Confirm'),
            React.createElement('button', { 'onClick': onCancel, 'data-testid': 'cancel-btn' }, 'Cancel'),
        );
    },
}));

vi.mock('react-icons/lu', () => ({
    LuChevronLeft: () => React.createElement('span', null, '<'),
    LuChevronRight: () => React.createElement('span', null, '>'),
    LuCloudUpload: (props: any) => React.createElement('span', { ...props, 'data-testid': 'upload-icon' }, 'upload'),
    LuTrash2: (props: any) => React.createElement('span', { ...props, 'data-testid': 'delete-icon' }, 'delete'),
}));

describe('WorkplaceImages', () => {
    beforeEach(() => {
        vi.clearAllMocks();

        usePermissionsMock.mockReturnValue({
            WORKPLACE_IMAGE_LIST: true,
            WORKPLACE_IMAGE_UPLOAD: true,
            WORKPLACE_IMAGE_DELETE: true,
        });
        useUploadWorkplaceImagesMutationMock.mockReturnValue({ mutateAsync: vi.fn().mockResolvedValue(undefined) });
        useDeleteWorkplaceImageMutationMock.mockReturnValue({ mutateAsync: vi.fn().mockResolvedValue(undefined) });
    });

    it('shows a skeleton while images are loading', () => {
        useGetWorkplaceImagesQueryMock.mockReturnValue({ data: undefined, isFetching: true });

        render(<WorkplaceImages workplaceId="1" />);

        expect(screen.getByTestId('skeleton')).toBeInTheDocument();
        expect(screen.queryByTestId('carousel')).not.toBeInTheDocument();
    });

    it('passes the permission flag through to the images query', () => {
        useGetWorkplaceImagesQueryMock.mockReturnValue({ data: { data: [] }, isFetching: false });
        usePermissionsMock.mockReturnValue({
            WORKPLACE_IMAGE_LIST: false,
            WORKPLACE_IMAGE_UPLOAD: true,
            WORKPLACE_IMAGE_DELETE: true,
        });

        render(<WorkplaceImages workplaceId="1" />);

        expect(useGetWorkplaceImagesQueryMock).toHaveBeenCalledWith('1', false);
    });

    it('renders a placeholder slide when there are no images', () => {
        useGetWorkplaceImagesQueryMock.mockReturnValue({ data: { data: [] }, isFetching: false });

        render(<WorkplaceImages workplaceId="1" />);

        expect(screen.getAllByTestId('slide')).toHaveLength(1);
        expect(screen.queryByTestId('delete-icon')).not.toBeInTheDocument();
    });

    it('renders a slide per image', () => {
        useGetWorkplaceImagesQueryMock.mockReturnValue({
            data: {
                data: [
                    { id: 1, workplace_id: 1, image: 'a.jpg' },
                    { id: 2, workplace_id: 1, image: 'b.jpg' },
                ],
            },
            isFetching: false,
        });

        render(<WorkplaceImages workplaceId="1" />);

        expect(screen.getAllByTestId('slide')).toHaveLength(2);
        expect(screen.getAllByTestId('delete-icon')).toHaveLength(2);
    });

    it('hides the delete icon without WORKPLACE_IMAGE_DELETE permission', () => {
        useGetWorkplaceImagesQueryMock.mockReturnValue({
            data: { data: [{ id: 1, workplace_id: 1, image: 'a.jpg' }] },
            isFetching: false,
        });
        usePermissionsMock.mockReturnValue({
            WORKPLACE_IMAGE_LIST: true,
            WORKPLACE_IMAGE_UPLOAD: true,
            WORKPLACE_IMAGE_DELETE: false,
        });

        render(<WorkplaceImages workplaceId="1" />);

        expect(screen.queryByTestId('delete-icon')).not.toBeInTheDocument();
    });

    it('hides the upload button without WORKPLACE_IMAGE_UPLOAD permission', () => {
        useGetWorkplaceImagesQueryMock.mockReturnValue({ data: { data: [] }, isFetching: false });
        usePermissionsMock.mockReturnValue({
            WORKPLACE_IMAGE_LIST: true,
            WORKPLACE_IMAGE_UPLOAD: false,
            WORKPLACE_IMAGE_DELETE: true,
        });

        render(<WorkplaceImages workplaceId="1" />);

        expect(screen.queryByTestId('file-input')).not.toBeInTheDocument();
    });

    it('uploads selected files and shows a success notification', async () => {
        const mutateAsync = vi.fn().mockResolvedValue(undefined);
        useUploadWorkplaceImagesMutationMock.mockReturnValue({ mutateAsync });
        useGetWorkplaceImagesQueryMock.mockReturnValue({ data: { data: [] }, isFetching: false });

        render(<WorkplaceImages workplaceId="1" />);

        const file = new File(['content'], 'photo.jpg', { type: 'image/jpeg' });
        fireEvent.change(screen.getByTestId('file-input'), { target: { files: [file] } });

        await waitFor(() => {
            expect(mutateAsync).toHaveBeenCalledWith(expect.any(FormData));
            expect(notificationsMock.show).toHaveBeenCalledWith(expect.objectContaining({ color: 'teal' }));
        });
    });

    it('shows an error notification when upload fails', async () => {
        useUploadWorkplaceImagesMutationMock.mockReturnValue({
            mutateAsync: vi.fn().mockRejectedValue(new Error('boom')),
        });
        useGetWorkplaceImagesQueryMock.mockReturnValue({ data: { data: [] }, isFetching: false });

        render(<WorkplaceImages workplaceId="1" />);

        const file = new File(['content'], 'photo.jpg', { type: 'image/jpeg' });
        fireEvent.change(screen.getByTestId('file-input'), { target: { files: [file] } });

        await waitFor(() => {
            expect(notificationsMock.show).toHaveBeenCalledWith(
                expect.objectContaining({ color: 'red', message: 'error|Could not upload workplace image' }),
            );
        });
    });

    it('opens a confirmation modal and deletes the image on confirm', async () => {
        const mutateAsync = vi.fn().mockResolvedValue(undefined);
        useDeleteWorkplaceImageMutationMock.mockReturnValue({ mutateAsync });
        useGetWorkplaceImagesQueryMock.mockReturnValue({
            data: { data: [{ id: 5, workplace_id: 1, image: 'a.jpg' }] },
            isFetching: false,
        });

        render(<WorkplaceImages workplaceId="1" />);

        expect(screen.queryByTestId('confirm-modal')).not.toBeInTheDocument();

        fireEvent.click(screen.getByTestId('delete-icon'));
        expect(screen.getByTestId('confirm-modal')).toBeInTheDocument();

        fireEvent.click(screen.getByTestId('confirm-btn'));

        await waitFor(() => {
            expect(mutateAsync).toHaveBeenCalledWith('5');
            expect(notificationsMock.show).toHaveBeenCalledWith(expect.objectContaining({ color: 'teal' }));
        });
    });

    it('shows an error notification when delete fails', async () => {
        useDeleteWorkplaceImageMutationMock.mockReturnValue({
            mutateAsync: vi.fn().mockRejectedValue(new Error('boom')),
        });
        useGetWorkplaceImagesQueryMock.mockReturnValue({
            data: { data: [{ id: 5, workplace_id: 1, image: 'a.jpg' }] },
            isFetching: false,
        });

        render(<WorkplaceImages workplaceId="1" />);

        fireEvent.click(screen.getByTestId('delete-icon'));
        fireEvent.click(screen.getByTestId('confirm-btn'));

        await waitFor(() => {
            expect(notificationsMock.show).toHaveBeenCalledWith(
                expect.objectContaining({ color: 'red', message: 'error|Could not delete workplace image' }),
            );
        });
    });

    it('closes the confirmation modal on cancel', () => {
        useGetWorkplaceImagesQueryMock.mockReturnValue({
            data: { data: [{ id: 5, workplace_id: 1, image: 'a.jpg' }] },
            isFetching: false,
        });

        render(<WorkplaceImages workplaceId="1" />);

        fireEvent.click(screen.getByTestId('delete-icon'));
        expect(screen.getByTestId('confirm-modal')).toBeInTheDocument();

        fireEvent.click(screen.getByTestId('cancel-btn'));
        expect(screen.queryByTestId('confirm-modal')).not.toBeInTheDocument();
    });
});
