import React, { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { LuMaximize2, LuSave } from 'react-icons/lu';
import { Button, Flex, Modal, MultiSelect, SimpleGrid, TextInput } from '@mantine/core';
import { useForm } from '@mantine/form';
import { useEditor } from '@tiptap/react';
import { useAtom } from 'jotai';
import { Validator, validatorResolver } from '@/validator';
import FormHelper from '@/helpers/form.helper';
import { DUserSelectRemoteMultiple } from '@/components/DUserSelectRemote';
import { masterAtom } from '@/pages/global.state';
import DocumentUpload from '@/pages/manage/document/partials/DocumentUpload';
import TextEditor, { contentEditorExtensions } from '@/pages/manage/document/partials/TextEditor';
import { useCreateDocumentMutation } from '@/pages/manage/document/api';

type CreateDocumentModalProps = {
    isOpened: boolean;
    onClose: () => void;
    onSubmitted?: (doc: any) => void;
    withAudience?: boolean;
};

type FormValues = {
    title: string;
    workplaces: number[];
    users: string[];
    attachments: File[];
    content: string;
};

const CreateDocumentModal = ({ isOpened, onClose, onSubmitted, withAudience = true }: CreateDocumentModalProps) => {
    const { t } = useTranslation();
    const [masterData] = useAtom(masterAtom);
    const [fullscreenOpen, setFullscreenOpen] = useState(false);

    const createMutation = useCreateDocumentMutation();

    const form = useForm<FormValues>({
        initialValues: {
            title: '',
            workplaces: [],
            users: [],
            attachments: [],
            content: '',
        },
        validate: validatorResolver({
            title: Validator.string().required(),
            workplaces: Validator.array(),
            users: Validator.array(),
            attachments: Validator.array(),
            content: Validator.string(),
        }),
    });

    const editor = useEditor({
        extensions: contentEditorExtensions,
        content: '',
        autofocus: false,
        editable: true,
        immediatelyRender: false,
    });

    useEffect(() => {
        form.reset();
        if (editor) editor.commands.clearContent();
    }, [isOpened]);

    const handleFilesChange = (files: File[]) => {
        form.setFieldValue('attachments', files);
    };

    const handleSubmit = async (values: FormValues) => {
        try {
            const formData = new FormData();
            formData.append('title', values.title);
            formData.append('content', editor?.getHTML() || '');
            values.workplaces.forEach((wpId) => formData.append('workplaces[]', wpId.toString()));
            values.users.forEach((userId) => formData.append('users[]', userId.toString()));
            values.attachments.forEach((file) => {
                if (file instanceof File) {
                    formData.append('attachments[]', file);
                }
            });

            const response = await createMutation.mutateAsync(formData);
            form.reset();
            editor?.commands.clearContent();
            onSubmitted?.(response);
            onClose();
        } catch (e) {
            FormHelper.setFormError(form, e);
        }
    };

    return (
        <>
            <Modal
                opened={isOpened}
                onClose={onClose}
                title={t('Create document')}
                size="lg"
                fullScreen={fullscreenOpen}
            >
                <form onSubmit={form.onSubmit(handleSubmit)}>
                    <SimpleGrid cols={1} spacing="md">
                        <TextInput withAsterisk label={t('Document name')} {...form.getInputProps('title')} />

                        {withAudience && (
                            <>
                                <MultiSelect
                                    label={t('Workplace')}
                                    placeholder={t('Select workplace')}
                                    data={masterData.workplaces.map((wp) => ({
                                        value: wp.id.toString(),
                                        label: wp.name,
                                    }))}
                                    searchable
                                    {...form.getInputProps('workplaces')}
                                />

                                <DUserSelectRemoteMultiple
                                    label={t('User')}
                                    placeholder={t('Search user')}
                                    {...form.getInputProps('users')}
                                />
                            </>
                        )}

                        <DocumentUpload onFilesChange={handleFilesChange} />

                        {editor && <TextEditor editor={editor} />}

                        <Flex justify="start">
                            <Button variant="light" size="xs" mt="sm" onClick={() => setFullscreenOpen(true)}>
                                <LuMaximize2 /> <span style={{ marginLeft: '6px' }}>{t('Expand editor')}</span>
                            </Button>
                        </Flex>
                    </SimpleGrid>

                    <Flex justify="end" mt="lg" gap="sm">
                        <Button color="gray" onClick={onClose}>
                            {t('Cancel')}
                        </Button>
                        <Button type="submit" variant="filled" color="teal" leftSection={<LuSave />}>
                            {t('Save')}
                        </Button>
                    </Flex>
                </form>
            </Modal>
        </>
    );
};

export default CreateDocumentModal;
