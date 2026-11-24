import React from 'react';
import { useTranslation } from 'react-i18next';
import { LuFileText } from 'react-icons/lu';
import Link from 'next/link';
import { useRouter } from 'next/router';
import { Anchor, Breadcrumbs, Button, Flex, Loader, Paper, Stack, Text, Title } from '@mantine/core';
import RouteConfig from '@/configs/route.config';
import ExamForm from '@/pages/manage/exam/partials/exam_form';
import { useGetExamQuery } from '@/pages/manage/exam/api';

const CreateExamPage = () => {
    const { t } = useTranslation();
    const { query } = useRouter();
    const copyFrom = query.copyFrom as string | undefined;
    const { data: sourceExamData, isPending, isError, refetch } = useGetExamQuery(copyFrom ?? '', !!copyFrom);
    const pageTitle = copyFrom ? t('Copy exam') : t('Create exam');

    if (copyFrom && isPending) {
        return (
            <Paper p="xl" mt="xl" radius="md" withBorder>
                <Loader />
            </Paper>
        );
    }

    // The source exam failed to load (deleted, no permission, network error) —
    // rendering ExamForm below would fall back to a blank create form under
    // the "Copy exam" title, hiding that the source is actually inaccessible
    // and risking the admin saving unrelated content in its place.
    if (copyFrom && (isError || !sourceExamData?.data)) {
        return (
            <Paper p="xl" mt="xl" radius="md" withBorder>
                <Stack align="center" gap="sm">
                    <Text c="red">{t('Failed to load the exam to copy. It may have been deleted.')}</Text>
                    <Flex gap="sm">
                        <Button variant="default" onClick={() => refetch()}>
                            {t('Retry')}
                        </Button>
                        <Button component={Link} href={RouteConfig.MANAGE_EXAM.path}>
                            {t('Back to exam list')}
                        </Button>
                    </Flex>
                </Stack>
            </Paper>
        );
    }

    return (
        <>
            <Breadcrumbs>
                <Anchor component={Link} href={RouteConfig.HOME.path}>
                    {t('Home')}
                </Anchor>
                <Anchor component={Link} href={RouteConfig.MANAGE_EXAM.path}>
                    {t('Exam management')}
                </Anchor>
                <Text>{pageTitle}</Text>
            </Breadcrumbs>

            <Flex justify="space-between" align="center" my="md">
                <Title order={4} lh="48px">
                    <LuFileText size={20} style={{ marginRight: 8 }} />
                    {pageTitle}
                </Title>
            </Flex>

            <ExamForm examData={copyFrom ? sourceExamData?.data : undefined} isCopy={!!copyFrom} />
        </>
    );
};

export default CreateExamPage;
