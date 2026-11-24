import React from 'react';
import { render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import DetailPage from '@/pages/admin/workplace/[id].page';

const {
    useGetWorkplaceDetailQueryMock,
    workplaceInfoSpy,
    workplacePositionSpy,
    workplaceShiftSpy,
    workplaceInstructionSpy,
    workplaceUserSpy,
    routerMock,
} = vi.hoisted(() => ({
    useGetWorkplaceDetailQueryMock: vi.fn(),
    workplaceInfoSpy: vi.fn(),
    workplacePositionSpy: vi.fn(),
    workplaceShiftSpy: vi.fn(),
    workplaceInstructionSpy: vi.fn(),
    workplaceUserSpy: vi.fn(),
    routerMock: { query: { id: '42' }, push: vi.fn() },
}));

vi.mock('next/link', () => ({
    default: ({ children, href }: { children: React.ReactNode; href: string }) =>
        React.createElement('a', { href }, children),
}));

vi.mock('next/router', () => ({
    useRouter: () => routerMock,
}));

vi.mock('react-i18next', () => ({
    initReactI18next: { type: '3rdParty', init: () => undefined },
    useTranslation: () => ({ t: (key: string) => key }),
}));

vi.mock('@mantine/core', () => ({
    Anchor: ({ children, href }: any) => React.createElement('a', { href }, children),
    Breadcrumbs: ({ children }: any) => React.createElement('nav', null, children),
    Flex: ({ children }: any) => React.createElement('div', null, children),
    Text: ({ children }: any) => React.createElement('span', null, children),
    Title: ({ children }: any) => React.createElement('h1', null, children),
}));

vi.mock('@/pages/admin/workplace/api', () => ({
    useGetWorkplaceDetailQuery: (...args: unknown[]) => useGetWorkplaceDetailQueryMock(...args),
}));

vi.mock('@/pages/admin/workplace/partials/workplace_info', () => ({
    default: (props: any) => {
        workplaceInfoSpy(props);
        return React.createElement('div', { 'data-testid': 'workplace-info' });
    },
}));

vi.mock('@/pages/admin/workplace/partials/workplace_position', () => ({
    default: (props: any) => {
        workplacePositionSpy(props);
        return React.createElement('div', { 'data-testid': 'workplace-positions' });
    },
}));

vi.mock('@/pages/admin/workplace/partials/workplace_shift', () => ({
    default: (props: any) => {
        workplaceShiftSpy(props);
        return React.createElement('div', { 'data-testid': 'workplace-shifts' });
    },
}));

vi.mock('@/pages/admin/workplace/partials/workplace_instruction', () => ({
    default: (props: any) => {
        workplaceInstructionSpy(props);
        return React.createElement('div', { 'data-testid': 'workplace-instructions' });
    },
}));

vi.mock('@/pages/admin/workplace/partials/workplace_user', () => ({
    default: (props: any) => {
        workplaceUserSpy(props);
        return React.createElement('div', { 'data-testid': 'workplace-users' });
    },
}));

const makeWorkplace = (overrides = {}) => ({
    id: 42,
    name: 'Osaka Branch',
    address: '1-1 Namba',
    coordinate: '34.66, 135.50',
    latitude: '34.66',
    longitude: '135.50',
    salary_per_hour: null,
    min_rest_hours: null,
    is_test: false,
    is_yamato: false,
    is_self_register_disabled: false,
    images: [],
    positions: [],
    shifts: [],
    instructions: [],
    ...overrides,
});

describe('Workplace detail page', () => {
    beforeEach(() => {
        vi.clearAllMocks();

        useGetWorkplaceDetailQueryMock.mockReturnValue({
            data: { data: makeWorkplace() },
            isFetching: false,
        });
    });

    it('renders the page title and breadcrumb', () => {
        render(<DetailPage />);

        expect(screen.getByRole('heading', { name: 'Workplace Detail' })).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Home' })).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Manage workplaces' })).toHaveAttribute('href', '/admin/workplace');
        expect(screen.getByText('Workplace Detail', { selector: 'span' })).toBeInTheDocument();
    });

    it('fetches the workplace using the id from the router', () => {
        render(<DetailPage />);

        expect(useGetWorkplaceDetailQueryMock).toHaveBeenCalledWith('42');
    });

    it('passes workplace data and fetching state to WorkplaceInfo', () => {
        const workplace = makeWorkplace({ name: 'Osaka Branch' });
        useGetWorkplaceDetailQueryMock.mockReturnValue({ data: { data: workplace }, isFetching: false });

        render(<DetailPage />);

        expect(workplaceInfoSpy).toHaveBeenCalledWith(
            expect.objectContaining({
                id: '42',
                workplace: workplace,
                isFetching: false,
            }),
        );
    });

    it('passes isFetching=true to WorkplaceInfo while loading', () => {
        useGetWorkplaceDetailQueryMock.mockReturnValue({ data: undefined, isFetching: true });

        render(<DetailPage />);

        expect(workplaceInfoSpy).toHaveBeenCalledWith(
            expect.objectContaining({ isFetching: true, workplace: undefined }),
        );
    });

    it('passes workplaceId to all sub-panels', () => {
        render(<DetailPage />);

        expect(workplacePositionSpy).toHaveBeenCalledWith(expect.objectContaining({ workplaceId: '42' }));
        expect(workplaceShiftSpy).toHaveBeenCalledWith(expect.objectContaining({ workplaceId: '42' }));
        expect(workplaceInstructionSpy).toHaveBeenCalledWith(expect.objectContaining({ workplaceId: '42' }));
        expect(workplaceUserSpy).toHaveBeenCalledWith(expect.objectContaining({ workplaceId: '42' }));
    });

    it('renders all section placeholders', () => {
        render(<DetailPage />);

        expect(screen.getByTestId('workplace-info')).toBeInTheDocument();
        expect(screen.getByTestId('workplace-positions')).toBeInTheDocument();
        expect(screen.getByTestId('workplace-shifts')).toBeInTheDocument();
        expect(screen.getByTestId('workplace-instructions')).toBeInTheDocument();
        expect(screen.getByTestId('workplace-users')).toBeInTheDocument();
    });
});
