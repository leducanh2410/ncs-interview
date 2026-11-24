import React, { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { LuFileText, LuPencil } from 'react-icons/lu';
import Link from 'next/link';
import { useRouter } from 'next/router';
import {
    Anchor,
    Box,
    Breadcrumbs,
    Button,
    Card,
    Checkbox,
    Divider,
    Flex,
    Loader,
    Paper,
    Tabs,
    Text,
    Title,
} from '@mantine/core';
import RouteConfig from '@/configs/route.config';
import RouteHelper from '@/helpers/route.helper';
import TranslatableHelper from '@/helpers/translatable.helper';
import CollapsibleNameGrid from '@/pages/manage/exam/partials/CollapsibleNameGrid';
import ExamStatistics from '@/pages/manage/exam/partials/ExamStatistics';
import InfoExam from '@/pages/manage/exam/partials/InfoExam';
import { usePermissions } from '@/hooks/usePermission';
import { ExamDocument, ExamQuestion, useGetExamQuery } from '@/pages/manage/exam/api';

const IndexPage = () => {
    const { t, i18n } = useTranslation();
    const { push, query } = useRouter();
    const id = query.id as string;

    const { data: examData, isPending } = useGetExamQuery(id);
    const exam = examData?.data;
    const perms = usePermissions(['EXAM_EDIT', 'EXAM_RESULT_LIST']);
    const [activeTab, setActiveTab] = useState<string | null>('information');

    if (isPending || !exam) return <Loader />;

    const canViewStatistics = perms.EXAM_RESULT_LIST && exam.type !== 'external_form_prep';

    return (
        <>
            <Breadcrumbs mb="md">
                <Anchor component={Link} href={RouteConfig.HOME.path}>
                    {t('Home')}
                </Anchor>
                <Anchor component={Link} href={RouteConfig.MANAGE_EXAM.path}>
                    {t('Exam management')}
                </Anchor>
                <Text>{t('Exam detail')}</Text>
            </Breadcrumbs>

            <Flex justify="space-between" align="center">
                <Title order={4} mb="sm">
                    <LuFileText size={20} style={{ marginRight: 8 }} />
                    {t('Exam detail')}: {TranslatableHelper.resolve(exam.title, i18n.language)}
                </Title>
                <Button
                    leftSection={<LuPencil />}
                    onClick={() => push(RouteHelper.getPath('MANAGE_EXAM_UPDATE', { id }))}
                    disabled={!perms.EXAM_EDIT}
                >
                    {t('Edit exam')}
                </Button>
            </Flex>

            <Tabs value={activeTab} onChange={setActiveTab} keepMounted={false} mt={12}>
                <Tabs.List>
                    <Tabs.Tab value="information">{t('Information')}</Tabs.Tab>
                    {canViewStatistics && <Tabs.Tab value="statistics">{t('Statistics')}</Tabs.Tab>}
                </Tabs.List>

                <Tabs.Panel value="information">
                    <Paper withBorder p="md" mt={12} radius="md">
                        <InfoExam exam={exam} />
                    </Paper>

                    <Divider my="lg" />

                    <CollapsibleNameGrid
                        title={t('Documents')}
                        emptyText={t('No document')}
                        items={exam.documents.map((doc: ExamDocument) => ({ id: doc.id, name: doc.title }))}
                    />

                    <Box mt="lg">
                        <CollapsibleNameGrid
                            title={t('Workplaces')}
                            emptyText={t('No workplace')}
                            items={exam.workplaces}
                        />
                    </Box>

                    <Divider my="lg" />

                    <Title order={5} mb="sm">
                        {t('Exam content')}
                    </Title>
                    {exam.questions.length === 0 ? (
                        <Text c="dimmed">{t('No question')}</Text>
                    ) : (
                        <Flex direction="column" gap="md">
                            {exam.questions.map((q: ExamQuestion) => (
                                <Card key={q.id} withBorder radius="md" shadow="xs" p="md">
                                    <Text fw={600} mb="sm">
                                        {t('Question')} {q.no}: {TranslatableHelper.resolve(q.content, i18n.language)}
                                    </Text>

                                    {q.options.map((opt) => (
                                        <Checkbox
                                            key={opt.id}
                                            label={TranslatableHelper.resolve(opt.content, i18n.language)}
                                            checked={opt.is_correct}
                                            disabled
                                            readOnly
                                            mb={4}
                                        />
                                    ))}
                                </Card>
                            ))}
                        </Flex>
                    )}
                </Tabs.Panel>

                {canViewStatistics && (
                    <Tabs.Panel value="statistics" pt="md">
                        <ExamStatistics examId={id} isRecurring={!!exam?.is_recurring} />
                    </Tabs.Panel>
                )}
            </Tabs>
        </>
    );
};

export default IndexPage;
