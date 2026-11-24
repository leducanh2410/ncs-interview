import { ReactNode, Ref } from 'react';
import Link from 'next/link';
import { Anchor, AvatarProps, Box, Flex, Text, Tooltip } from '@mantine/core';
import { User } from '@/types/models/user.d';
import RouteHelper from '@/helpers/route.helper';
import DAvatar from '@/components/DAvatar';

// profile_picture is widened to nullable here since some callers (e.g. admin-leave list/detail)
// only have a partial user projection from the API where the avatar may not be set yet.
type AvatarUser = Pick<User, 'id' | 'name' | 'staff_no'> & { profile_picture: string | null };

type Props = AvatarProps & {
    user: AvatarUser;
    isLink?: boolean;
    isAvatarLink?: boolean;
    isShowName?: boolean;
    isShowAccount?: boolean;
    isShowStaffNo?: boolean;
    isShowTooltip?: boolean;
    badge?: ReactNode;
    customLink?: string;
    ref?: Ref<HTMLDivElement>;
};

const AvatarComponent = ({
    user,
    isLink = true,
    isAvatarLink = false,
    isShowName = false,
    isShowAccount = true,
    isShowStaffNo = true,
    isShowTooltip = true,
    badge,
    customLink,
    ref,
    ...rest
}: Props) => {
    let avatar = <DAvatar size="sm" name={user.name} src={user.profile_picture} mr={2} {...rest} />;
    if (badge) {
        avatar = (
            <Box pos="relative">
                {avatar}
                <Box pos="absolute" bottom={-2} right={-2}>
                    {badge}
                </Box>
            </Box>
        );
    }

    const href = customLink || RouteHelper.getPath('ADMIN_USER_DETAIL', { id: user.id });
    if (isLink && isAvatarLink) {
        avatar = (
            <Anchor component={Link} href={href} aria-label={user.name} style={{ display: 'flex' }}>
                {avatar}
            </Anchor>
        );
    }

    let name = user.name;
    if (isShowStaffNo && user.staff_no) {
        name += ` (${user.staff_no})`;
    }

    return (
        <Flex ref={ref} align="center" gap="4">
            {avatar}
            {isShowAccount ? (
                isLink ? (
                    <Anchor component={Link} href={href}>
                        {name}
                    </Anchor>
                ) : (
                    <Text>{name}</Text>
                )
            ) : null}
        </Flex>
    );
};

const UserWithAvatar = ({ isShowAccount = true, ...rest }: Props) => {
    const component = <AvatarComponent isShowAccount={isShowAccount} {...rest} />;
    if (isShowAccount || !rest.isShowTooltip) return component;
    return <Tooltip label={rest.user.name}>{component}</Tooltip>;
};

export default UserWithAvatar;
