import { useTranslation } from 'react-i18next';
import { LuSearch } from 'react-icons/lu';
import { ActionIcon, Tooltip } from '@mantine/core';
import NotificationHelper from '@/helpers/notification.helper';
import { useResolvePostalCodeMutation } from '@/pages/admin/user/api';

type Props = {
    address: string;
    prefecture?: string;
    disabled?: boolean;
    onResolved: (postalCode: string) => void;
};

/**
 * Derives the postal code from the address through Japan Post data. Only an
 * unambiguous match fills the field; anything else is reported so the admin
 * types it by hand.
 */
const PostalCodeFromAddressButton = ({ address, prefecture, disabled = false, onResolved }: Props) => {
    const { t } = useTranslation();
    const resolveMutation = useResolvePostalCodeMutation();
    const label = t('Find postal code from address');

    const onClick = async () => {
        try {
            const response = await resolveMutation.mutateAsync({ address, prefecture });
            const postalCode = response.data?.postal_code;
            if (postalCode) {
                onResolved(postalCode);
            } else {
                NotificationHelper.showInfo(t('The postal code could not be determined from the address'));
            }
        } catch (e) {
            NotificationHelper.showError(t('error|Could not look up the postal code'));
        }
    };

    return (
        <Tooltip label={label} withArrow>
            <ActionIcon
                variant="subtle"
                size="sm"
                aria-label={label}
                onClick={onClick}
                loading={resolveMutation.isPending}
                disabled={disabled || address.trim() === ''}
            >
                <LuSearch size={14} />
            </ActionIcon>
        </Tooltip>
    );
};

export default PostalCodeFromAddressButton;
