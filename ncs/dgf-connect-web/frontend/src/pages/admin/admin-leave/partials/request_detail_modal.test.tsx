import React from 'react';
import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { AdminLeaveRequestDetail } from '@/types/models/admin_leave_request';
import RequestDetailModal from './request_detail_modal';

const { authAtomValue } = vi.hoisted(() => ({
    authAtomValue: { current: { user: { id: 999 } } as { user: { id: number } } | null },
}));

vi.mock('react-i18next', () => ({
    initReactI18next: { type: '3rdParty', init: () => undefined },
    useTranslation: () => ({
        t: (key: string, options?: Record<string, unknown>) => {
            if (!options) return key;
            return Object.entries(options).reduce((acc, [k, v]) => acc.replace(`{{${k}}}`, String(v)), key);
        },
    }),
}));

vi.mock('@mantine/core', () => ({
    Alert: ({ children }: any) => React.createElement('div', { 'data-testid': 'alert' }, children),
    Anchor: ({ children, href }: any) => React.createElement('a', { href }, children),
    Badge: ({ children }: any) => React.createElement('span', { 'data-testid': 'badge' }, children),
    Box: ({ children }: any) => React.createElement('div', null, children),
    Button: ({ children, onClick, loading }: any) =>
        React.createElement('button', { onClick, disabled: loading }, children),
    Flex: ({ children }: any) => React.createElement('div', null, children),
    Image: ({ onClick, alt }: any) => React.createElement('img', { onClick, alt, 'data-testid': 'thumbnail' }),
    Loader: () => React.createElement('div', { 'data-testid': 'loader' }),
    Modal: ({ children, opened, title }: any) =>
        opened ? React.createElement('div', { 'data-testid': 'modal' }, [title, children]) : null,
    SimpleGrid: ({ children }: any) => React.createElement('div', null, children),
    Text: ({ children }: any) => React.createElement('span', null, children),
}));

vi.mock('jotai', () => ({
    useAtom: () => [authAtomValue.current],
    atom: (init: any) => ({ init }),
}));

vi.mock('@/helpers/date.helper', () => ({
    default: {
        parseAndFormat: (value: string | null) => (value ? `formatted:${value}` : '-'),
    },
}));

vi.mock('@/helpers/enum.helper', () => ({
    default: {
        getText: (_e: any, value: string) => value,
        getColor: () => 'gray',
    },
}));

vi.mock('@/enums/admin_leave_type.enum', () => ({
    getAdminLeaveTypeLabel: (value: string) => `type:${value}`,
}));

vi.mock('@/components/UserWithAvatar', () => ({
    default: ({ user }: any) =>
        React.createElement('span', null, user?.staff_no ? `${user.name} (${user.staff_no})` : (user?.name ?? '')),
}));

vi.mock('@/helpers/route.helper', () => ({
    default: {
        getPath: (_routeName: string, params: Record<string, unknown>) => `/admin/user/${params.id}`,
    },
}));

vi.mock('next/link', () => ({
    default: ({ children, href }: any) => React.createElement('a', { href }, children),
}));

const makeDetail = (overrides: Partial<AdminLeaveRequestDetail> = {}): AdminLeaveRequestDetail => ({
    id: 1,
    requester_id: 20,
    requester_name: 'Alice',
    requester_staff_no: 'A-100',
    requester_profile_picture: '',
    type: 'paid_leave',
    start_date: '2026-08-06',
    end_date: '2026-08-10',
    leave_days: 5,
    reason: 'Family trip',
    status: 'pending',
    attachments_count: 0,
    has_overlap: false,
    created_at: '2026-08-01T09:00:00+09:00',
    decided_by_id: null,
    decided_by_name: null,
    decided_at: null,
    reject_reason: null,
    attachments: [],
    conflict: null,
    ...overrides,
});

describe('RequestDetailModal', () => {
    beforeEach(() => {
        authAtomValue.current = { user: { id: 999 } };
    });

    it('renders nothing when requestId is null', () => {
        const { container } = render(
            <RequestDetailModal
                requestId={null}
                detail={null}
                isLoading={false}
                onClose={vi.fn()}
                onApprove={vi.fn()}
                onReject={vi.fn()}
            />,
        );

        expect(container).toBeEmptyDOMElement();
    });

    it('shows a loader while the detail is loading', () => {
        render(
            <RequestDetailModal
                requestId={1}
                detail={undefined}
                isLoading
                onClose={vi.fn()}
                onApprove={vi.fn()}
                onReject={vi.fn()}
            />,
        );

        expect(screen.getByTestId('loader')).toBeInTheDocument();
    });

    it('renders the reason and an empty-attachment state', () => {
        render(
            <RequestDetailModal
                requestId={1}
                detail={makeDetail()}
                isLoading={false}
                onClose={vi.fn()}
                onApprove={vi.fn()}
                onReject={vi.fn()}
            />,
        );

        expect(screen.getByText('Family trip')).toBeInTheDocument();
        expect(screen.getByText('No attachments')).toBeInTheDocument();
    });

    it('renders an image thumbnail and a PDF link for attachments', () => {
        render(
            <RequestDetailModal
                requestId={1}
                detail={makeDetail({
                    attachments: [
                        {
                            id: 1,
                            original_name: 'certificate.png',
                            mime_type: 'image/png',
                            size_bytes: 100,
                            is_image: true,
                            url: 'https://example.com/certificate.png',
                        },
                        {
                            id: 2,
                            original_name: 'receipt.pdf',
                            mime_type: 'application/pdf',
                            size_bytes: 200,
                            is_image: false,
                            url: 'https://example.com/receipt.pdf',
                        },
                    ],
                })}
                isLoading={false}
                onClose={vi.fn()}
                onApprove={vi.fn()}
                onReject={vi.fn()}
            />,
        );

        expect(screen.getByTestId('thumbnail')).toBeInTheDocument();
        const link = screen.getByText('receipt.pdf');
        expect(link).toHaveAttribute('href', 'https://example.com/receipt.pdf');
    });

    it('opens a full-size preview when an image thumbnail is clicked', () => {
        render(
            <RequestDetailModal
                requestId={1}
                detail={makeDetail({
                    attachments: [
                        {
                            id: 1,
                            original_name: 'certificate.png',
                            mime_type: 'image/png',
                            size_bytes: 100,
                            is_image: true,
                            url: 'https://example.com/certificate.png',
                        },
                    ],
                })}
                isLoading={false}
                onClose={vi.fn()}
                onApprove={vi.fn()}
                onReject={vi.fn()}
            />,
        );

        expect(screen.queryAllByTestId('modal')).toHaveLength(1);

        fireEvent.click(screen.getByTestId('thumbnail'));

        // The detail modal unmounts while the preview is open — two simultaneously-opened
        // Mantine Modals stack ambiguously and leave the one underneath unclickable, so only the
        // preview modal should remain in the DOM.
        expect(screen.getAllByTestId('modal')).toHaveLength(1);
        expect(screen.getByAltText('certificate.png')).toBeInTheDocument();
        expect(screen.queryByText('Family trip')).not.toBeInTheDocument();
    });

    it('shows approve/reject actions for a pending request viewed by a different user', () => {
        render(
            <RequestDetailModal
                requestId={1}
                detail={makeDetail({ requester_id: 20 })}
                isLoading={false}
                onClose={vi.fn()}
                onApprove={vi.fn()}
                onReject={vi.fn()}
            />,
        );

        expect(screen.getByText('Approve')).toBeInTheDocument();
        expect(screen.getByText('Reject')).toBeInTheDocument();
    });

    it('calls onApprove and onReject when the actions are clicked', () => {
        const onApprove = vi.fn();
        const onReject = vi.fn();
        render(
            <RequestDetailModal
                requestId={1}
                detail={makeDetail()}
                isLoading={false}
                onClose={vi.fn()}
                onApprove={onApprove}
                onReject={onReject}
            />,
        );

        fireEvent.click(screen.getByText('Approve'));
        fireEvent.click(screen.getByText('Reject'));

        expect(onApprove).toHaveBeenCalled();
        expect(onReject).toHaveBeenCalled();
    });

    it('disables the decision actions and explains why when the viewer is the requester', () => {
        authAtomValue.current = { user: { id: 20 } };

        render(
            <RequestDetailModal
                requestId={1}
                detail={makeDetail({ requester_id: 20 })}
                isLoading={false}
                onClose={vi.fn()}
                onApprove={vi.fn()}
                onReject={vi.fn()}
            />,
        );

        expect(screen.queryByText('Approve')).not.toBeInTheDocument();
        expect(screen.queryByText('Reject')).not.toBeInTheDocument();
        expect(screen.getByText('You cannot approve or reject your own request.')).toBeInTheDocument();
    });

    it('hides the decision actions and shows the recorded decision for an already-decided request', () => {
        render(
            <RequestDetailModal
                requestId={1}
                detail={makeDetail({
                    status: 'approved',
                    decided_by_id: 55,
                    decided_by_name: 'Manager Tanaka',
                    decided_at: '2026-08-02T09:00:00+09:00',
                })}
                isLoading={false}
                onClose={vi.fn()}
                onApprove={vi.fn()}
                onReject={vi.fn()}
            />,
        );

        expect(screen.queryByText('Approve')).not.toBeInTheDocument();
        expect(screen.queryByText('Reject')).not.toBeInTheDocument();
        expect(screen.getByText('Manager Tanaka')).toBeInTheDocument();
    });

    it('shows the rejection reason for a rejected request', () => {
        render(
            <RequestDetailModal
                requestId={1}
                detail={makeDetail({
                    status: 'rejected',
                    decided_by_id: 55,
                    decided_by_name: 'Manager Tanaka',
                    decided_at: '2026-08-02T09:00:00+09:00',
                    reject_reason: 'Coverage already thin that week',
                })}
                isLoading={false}
                onClose={vi.fn()}
                onApprove={vi.fn()}
                onReject={vi.fn()}
            />,
        );

        expect(screen.getByText('Coverage already thin that week')).toBeInTheDocument();
    });

    it('names the conflicting request when an overlap exists', () => {
        render(
            <RequestDetailModal
                requestId={1}
                detail={makeDetail({
                    has_overlap: true,
                    conflict: {
                        id: 2,
                        start_date: '2026-08-06',
                        end_date: '2026-08-20',
                        status: 'approved',
                        type: 'long_leave',
                    },
                })}
                isLoading={false}
                onClose={vi.fn()}
                onApprove={vi.fn()}
                onReject={vi.fn()}
            />,
        );

        expect(screen.getByTestId('alert')).toHaveTextContent('type:long_leave');
    });
});
