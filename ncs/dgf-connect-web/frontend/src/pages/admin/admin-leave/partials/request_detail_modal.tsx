import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import Link from 'next/link';
import { Alert, Anchor, Badge, Box, Button, Flex, Image, Loader, Modal, SimpleGrid, Text } from '@mantine/core';
import { useAtom } from 'jotai';
import { AdminLeaveRequestAttachment, AdminLeaveRequestDetail } from '@/types/models/admin_leave_request';
import { AdminLeaveRequestStatusEnum } from '@/enums/admin_leave_request_status.enum';
import { getAdminLeaveTypeLabel } from '@/enums/admin_leave_type.enum';
import DateHelper from '@/helpers/date.helper';
import EnumHelper from '@/helpers/enum.helper';
import RouteHelper from '@/helpers/route.helper';
import UserWithAvatar from '@/components/UserWithAvatar';
import { authAtom } from '@/pages/global.state';

const RequestDetailModal = ({ requestId, detail, isLoading, onClose, onApprove, onReject, isProcessing }: Props) => {
    const { t } = useTranslation();
    const [auth] = useAtom(authAtom);
    const [previewAttachment, setPreviewAttachment] = useState<AdminLeaveRequestAttachment | null>(null);

    if (requestId === null) return null;

    const isSelf = !!detail && auth?.user.id === detail.requester_id;
    const isPending = detail?.status === AdminLeaveRequestStatusEnum.PENDING.value;

    return (
        <>
            {
                // Hidden (not just visually covered) while the attachment preview is open — two
                // simultaneously-opened Mantine Modals stack ambiguously, leaving the one
                // underneath unclickable. previewAttachment state stays local to this component,
                // so this modal reappears once the preview closes.
                previewAttachment === null && (
                    <Modal opened onClose={onClose} title={t('Leave request detail')} size="lg">
                        {isLoading || !detail ? (
                            <Flex justify="center" py="xl">
                                <Loader />
                            </Flex>
                        ) : (
                            <>
                                <Flex justify="space-between" align="center" mb="md">
                                    <Flex align="center" gap={4}>
                                        <Text>{t('Requester')}:</Text>
                                        <UserWithAvatar
                                            user={{
                                                id: detail.requester_id,
                                                name: detail.requester_name,
                                                staff_no: detail.requester_staff_no,
                                                profile_picture: detail.requester_profile_picture,
                                            }}
                                        />
                                    </Flex>
                                    <Badge color={EnumHelper.getColor(AdminLeaveRequestStatusEnum, detail.status)}>
                                        {EnumHelper.getText(AdminLeaveRequestStatusEnum, detail.status)}
                                    </Badge>
                                </Flex>

                                <SimpleGrid cols={2} mb="md">
                                    <Text>
                                        {t('Type')}: <b>{getAdminLeaveTypeLabel(detail.type)}</b>
                                    </Text>
                                    <Text>
                                        {t('Dates')}:{' '}
                                        <b>
                                            {detail.start_date
                                                ? `${DateHelper.parseAndFormat(detail.start_date)} ~ ${DateHelper.parseAndFormat(detail.end_date)}`
                                                : '-'}
                                        </b>
                                    </Text>
                                    <Text>
                                        {t('Days')}: <b>{detail.leave_days ?? '-'}</b>
                                    </Text>
                                    <Text>
                                        {t('Submitted at')}:{' '}
                                        <b>
                                            {DateHelper.parseAndFormat(
                                                detail.created_at,
                                                null,
                                                t('FORMAT_DATE_WITH_TIME'),
                                            )}
                                        </b>
                                    </Text>
                                </SimpleGrid>

                                <Box mb="md">
                                    <Text fw={600} mb={4}>
                                        {t('Reason')}
                                    </Text>
                                    <Text style={{ whiteSpace: 'pre-wrap' }}>{detail.reason}</Text>
                                </Box>

                                {detail.has_overlap && detail.conflict && (
                                    <Alert color="orange" mb="md">
                                        {t(
                                            'Overlaps another {{status}} request ({{type}}) from {{start}} to {{end}}.',
                                            {
                                                status: EnumHelper.getText(
                                                    AdminLeaveRequestStatusEnum,
                                                    detail.conflict.status,
                                                ),
                                                type: getAdminLeaveTypeLabel(detail.conflict.type),
                                                start: DateHelper.parseAndFormat(detail.conflict.start_date),
                                                end: DateHelper.parseAndFormat(detail.conflict.end_date),
                                            },
                                        )}
                                    </Alert>
                                )}

                                <Box mb="md">
                                    <Text fw={600} mb={4}>
                                        {t('Attachments')}
                                    </Text>
                                    {detail.attachments.length === 0 ? (
                                        <Text c="dimmed">{t('No attachments')}</Text>
                                    ) : (
                                        <Flex gap="sm" wrap="wrap">
                                            {detail.attachments.map((attachment) =>
                                                attachment.is_image ? (
                                                    <Image
                                                        key={attachment.id}
                                                        src={attachment.url}
                                                        alt={attachment.original_name}
                                                        h={80}
                                                        w={80}
                                                        fit="cover"
                                                        radius="sm"
                                                        style={{ cursor: 'pointer' }}
                                                        onClick={() => setPreviewAttachment(attachment)}
                                                    />
                                                ) : (
                                                    <Anchor
                                                        key={attachment.id}
                                                        href={attachment.url}
                                                        target="_blank"
                                                        rel="noopener noreferrer"
                                                    >
                                                        {attachment.original_name}
                                                    </Anchor>
                                                ),
                                            )}
                                        </Flex>
                                    )}
                                </Box>

                                {!isPending && (
                                    <Box mb="md">
                                        <Text fw={600} mb={4}>
                                            {t('Decision')}
                                        </Text>
                                        <Text>
                                            {t('Decided by')}:{' '}
                                            {detail.decided_by_id ? (
                                                <Anchor
                                                    component={Link}
                                                    href={RouteHelper.getPath('ADMIN_USER_DETAIL', {
                                                        id: detail.decided_by_id,
                                                    })}
                                                >
                                                    <b>{detail.decided_by_name}</b>
                                                </Anchor>
                                            ) : (
                                                <b>-</b>
                                            )}
                                        </Text>
                                        <Text>
                                            {t('Decided at')}:{' '}
                                            <b>
                                                {detail.decided_at
                                                    ? DateHelper.parseAndFormat(
                                                          detail.decided_at,
                                                          null,
                                                          t('FORMAT_DATE_WITH_TIME'),
                                                      )
                                                    : '-'}
                                            </b>
                                        </Text>
                                        {detail.status === AdminLeaveRequestStatusEnum.REJECTED.value && (
                                            <Text>
                                                {t('Reject reason')}: <b>{detail.reject_reason}</b>
                                            </Text>
                                        )}
                                    </Box>
                                )}

                                {isPending && isSelf && (
                                    <Alert color="yellow" mb="md">
                                        {t('You cannot approve or reject your own request.')}
                                    </Alert>
                                )}

                                {isPending && !isSelf && (
                                    <Flex justify="flex-end" gap="xs">
                                        <Button color="red" variant="outline" onClick={onReject} loading={isProcessing}>
                                            {t('Reject')}
                                        </Button>
                                        <Button color="teal" onClick={onApprove} loading={isProcessing}>
                                            {t('Approve')}
                                        </Button>
                                    </Flex>
                                )}
                            </>
                        )}
                    </Modal>
                )
            }

            <Modal
                opened={previewAttachment !== null}
                onClose={() => setPreviewAttachment(null)}
                title={previewAttachment?.original_name}
                size="xl"
            >
                {previewAttachment && (
                    <img
                        src={previewAttachment.url}
                        alt={previewAttachment.original_name}
                        style={{
                            maxWidth: '100%',
                            maxHeight: '80vh',
                            objectFit: 'contain',
                            display: 'block',
                            margin: '0 auto',
                        }}
                    />
                )}
            </Modal>
        </>
    );
};

type Props = {
    requestId: number | null;
    detail: AdminLeaveRequestDetail | null | undefined;
    isLoading: boolean;
    onClose: () => void;
    onApprove: () => void;
    onReject: () => void;
    isProcessing?: boolean;
};

export default RequestDetailModal;
