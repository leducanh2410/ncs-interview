import React from 'react';
import { useTranslation } from 'react-i18next';
import { LuFileText } from 'react-icons/lu';
import Link from 'next/link';
import { useRouter } from 'next/router';
import { Anchor, Breadcrumbs, Flex, Loader, Paper, Text, Title } from '@mantine/core';
import RouteConfig from '@/configs/route.config';
import ExamForm from '@/pages/manage/exam/partials/exam_form';
import { useGetExamQuery } from '@/pages/manage/exam/api';

const IndexPage = () => {
    const { t } = useTranslation();
    const router = useRouter();
    const examId = router.query.id as string;
    const { data: examData, isPending } = useGetExamQuery(examId);

    if (isPending) {
        return (
            <Paper p="xl" mt="xl" radius="md" withBorder>
                <Loader />
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
                <Text>{t('Edit exam')}</Text>
            </Breadcrumbs>

            <Flex justify="space-between" align="center" my="md">
                <Title order={4} lh="48px">
                    <LuFileText size={20} style={{ marginRight: 8 }} />
                    {t('Edit exam')}
                </Title>
            </Flex>

            <ExamForm examData={examData.data} />
        </>
    );
};

export default IndexPage;
