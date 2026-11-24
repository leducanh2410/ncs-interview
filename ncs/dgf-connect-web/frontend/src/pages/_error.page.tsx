import { useTranslation } from 'react-i18next';
import Link from 'next/link';
import { Button, Flex, Text, Title } from '@mantine/core';

type Props = {
    statusCode: number;
};
const ErrorPage = ({ statusCode }: Props) => {
    const { t } = useTranslation();
    let text = '';
    switch (statusCode) {
        case 404:
            text = t('error|The page you are looking for was not found');
            break;
        case 403:
            text = t('error|You are not authorize to access this page');
            break;
    }
    return (
        <Flex justify="center" align="center" direction="column" gap={8} w="100%" pt="lg">
            <Title order={1}>{statusCode}</Title>
            <Text c="gray.7">{text}</Text>
            <Button component={Link} mt="lg" href="/">
                {t('Back to Home')}
            </Button>
        </Flex>
    );
};

export default ErrorPage;
