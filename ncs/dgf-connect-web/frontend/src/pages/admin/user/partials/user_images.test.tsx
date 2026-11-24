import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import UserImages from './user_images';

const { uploadImageMock, usePermissionsMock, notificationsShowMock, getStaticUrlMock } = vi.hoisted(() => ({
    uploadImageMock: vi.fn(),
    usePermissionsMock: vi.fn(),
    notificationsShowMock: vi.fn(),
    getStaticUrlMock: vi.fn(),
}));

vi.mock('react-i18next', () => ({
    useTranslation: () => ({ t: (key: string) => key }),
}));

vi.mock('@mantine/carousel', () => ({
    Carousel: Object.assign(
        ({ children }: any) => React.createElement('div', { 'data-testid': 'carousel' }, children),
        { Slide: ({ children }: any) => React.createElement('div', null, children) },
    ),
}));

vi.mock('@mantine/core', () => ({
    Box: ({ children }: any) => React.createElement('div', null, children),
    Flex: ({ children }: any) => React.createElement('div', null, children),
    Loader: ({ children }: any) => React.createElement('div', null, children),
    Image: ({ src, fallbackSrc }: any) =>
        React.createElement('img', { 'src': src ?? fallbackSrc, 'alt': 'user', 'data-fallback': src ? 'no' : 'yes' }),
    FileButton: ({ children, onChange, accept }: any) =>
        React.createElement(
            'label',
            null,
            React.createElement('input', {
                'type': 'file',
                'data-testid': 'file-input',
                accept,
                'onChange': (e: any) => onChange(e.target.files?.[0] ?? null),
            }),
            children({}),
        ),
}));

vi.mock('@mantine/notifications', () => ({
    notifications: { show: (...args: unknown[]) => notificationsShowMock(...args) },
}));

vi.mock('@/helpers/url.helper', () => ({
    default: { getStaticUrl: (...args: unknown[]) => getStaticUrlMock(...args) },
}));

vi.mock('@/hooks/usePermission', () => ({
    usePermissions: (...args: unknown[]) => usePermissionsMock(...args),
}));

vi.mock('@/pages/admin/user/api', () => ({
    useUploadImageMutation: () => ({ mutateAsync: uploadImageMock }),
}));

vi.mock('react-icons/lu', () => ({
    LuChevronLeft: () => React.createElement('span', null, '<'),
    LuChevronRight: () => React.createElement('span', null, '>'),
    LuCloudUpload: (props: any) => React.createElement('span', { ...props, 'data-testid': 'upload-icon' }, '⬆'),
    LuTrash2: ({ onClick }: any) =>
        React.createElement('button', { 'type': 'button', onClick, 'data-testid': 'delete-icon' }, '🗑'),
}));

vi.mock('./user_images.module.css', () => ({ default: {} }));

const allPermissions = { USER_IMAGE_LIST: true, USER_IMAGE_UPLOAD: true, USER_IMAGE_DELETE: true };

const buildUser = (images: Array<{ id: number; user_id: number; path: string }>) => ({ images }) as any;

describe('UserImages', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        usePermissionsMock.mockReturnValue(allPermissions);
        getStaticUrlMock.mockImplementation((path: string) => `https://cdn.test/${path}`);
    });

    it('renders nothing but an empty box without USER_IMAGE_LIST permission', () => {
        usePermissionsMock.mockReturnValue({ ...allPermissions, USER_IMAGE_LIST: false });

        render(<UserImages id="42" user={buildUser([{ id: 1, user_id: 42, path: 'a.png' }])} isLoading={false} />);

        expect(screen.queryByTestId('carousel')).not.toBeInTheDocument();
    });

    it('does not render the carousel while loading', () => {
        render(<UserImages id="42" user={buildUser([])} isLoading={true} />);

        expect(screen.queryByTestId('carousel')).not.toBeInTheDocument();
    });

    it('renders one slide per image using the static url', () => {
        render(
            <UserImages
                id="42"
                user={buildUser([
                    { id: 1, user_id: 42, path: 'a.png' },
                    { id: 2, user_id: 42, path: 'b.png' },
                ])}
                isLoading={false}
            />,
        );

        const images = screen.getAllByAltText('user');
        expect(images).toHaveLength(2);
        expect(images[0]).toHaveAttribute('src', 'https://cdn.test/a.png');
        expect(images[1]).toHaveAttribute('src', 'https://cdn.test/b.png');
    });

    it('renders a placeholder slide when the user has no images', () => {
        render(<UserImages id="42" user={buildUser([])} isLoading={false} />);

        const image = screen.getByAltText('user');
        expect(image).toHaveAttribute('data-fallback', 'yes');
        expect(getStaticUrlMock).not.toHaveBeenCalled();
    });

    it('never renders a delete button, even with USER_IMAGE_DELETE permission', () => {
        render(
            <UserImages
                id="42"
                user={buildUser([
                    { id: 1, user_id: 42, path: 'a.png' },
                    { id: 2, user_id: 42, path: 'b.png' },
                ])}
                isLoading={false}
            />,
        );

        expect(screen.queryByTestId('delete-icon')).not.toBeInTheDocument();
    });

    it('hides the upload button without USER_IMAGE_UPLOAD permission', () => {
        usePermissionsMock.mockReturnValue({ ...allPermissions, USER_IMAGE_UPLOAD: false });

        render(<UserImages id="42" user={buildUser([{ id: 1, user_id: 42, path: 'a.png' }])} isLoading={false} />);

        expect(screen.queryByTestId('file-input')).not.toBeInTheDocument();
    });

    it('uploads the selected file as form data and shows a success notification', async () => {
        const user = userEvent.setup();
        uploadImageMock.mockResolvedValue({});
        render(<UserImages id="42" user={buildUser([{ id: 1, user_id: 42, path: 'a.png' }])} isLoading={false} />);

        const file = new File(['x'], 'photo.png', { type: 'image/png' });
        await user.upload(screen.getByTestId('file-input'), file);

        await waitFor(() => expect(uploadImageMock).toHaveBeenCalledTimes(1));
        const formData = uploadImageMock.mock.calls[0][0] as FormData;
        expect(formData.get('file')).toBe(file);
        expect(notificationsShowMock).toHaveBeenCalledWith(
            expect.objectContaining({ color: 'teal', message: 'Successfully uploaded user image' }),
        );
    });

    it('shows an error notification when uploading fails', async () => {
        const user = userEvent.setup();
        uploadImageMock.mockRejectedValue(new Error('boom'));
        render(<UserImages id="42" user={buildUser([{ id: 1, user_id: 42, path: 'a.png' }])} isLoading={false} />);

        await user.upload(screen.getByTestId('file-input'), new File(['x'], 'photo.png', { type: 'image/png' }));

        await waitFor(() =>
            expect(notificationsShowMock).toHaveBeenCalledWith(
                expect.objectContaining({ color: 'red', message: 'error|Could not upload user image' }),
            ),
        );
    });
});
