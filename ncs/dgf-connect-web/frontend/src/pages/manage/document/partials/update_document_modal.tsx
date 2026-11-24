import React, { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { LuMaximize2, LuMinimize2, LuSave, LuTrash2 } from 'react-icons/lu';
import { ActionIcon, Anchor, Button, Flex, List, Modal, MultiSelect, SimpleGrid, Text, TextInput } from '@mantine/core';
import { useForm } from '@mantine/form';
import { useEditor } from '@tiptap/react';
import { useAtom } from 'jotai';
import { Validator, validatorResolver } from '@/validator';
import { Document } from '@/types/models/document';
import FormHelper from '@/helpers/form.helper';
import NotificationHelper from '@/helpers/notification.helper';
import UrlHelper from '@/helpers/url.helper';
import DConfirmModal from '@/components/DConfirmModal';
import { DUserSelectRemoteMultiple, renderUserItem } from '@/components/DUserSelectRemote';
import { masterAtom } from '@/pages/global.state';
import DocumentUpload from './DocumentUpload';
import TextEditor, { contentEditorExtensions } from './TextEditor';
import { useUpdateDocumentMutation } from '@/pages/manage/document/api';

type UpdateDocumentModalProps = {
    isOpened: boolean;
    onClose: () => void;
    onSubmitted?: (doc: any) => void;
    document: Document;
};

type FormValues = {
    title: string;
    workplaces: string[];
    users: string[];
    attachments: File[];
    content: string;
    removedAttachmentIds: number[];
};

const UpdateDocumentModal = ({ isOpened, onClose, onSubmitted, document }: UpdateDocumentModalProps) => {
    const { t } = useTranslation();
    const [masterData] = useAtom(masterAtom);
    const [attachmentToRemove, setAttachmentToRemove] = useState<number | null>(null);
    const [isRemovingAttachment, setIsRemovingAttachment] = useState(false);
    const [attachmentsPreview, setAttachmentsPreview] = useState<Document['attachments']>([]);
    const [fullscreenOpen, setFullscreenOpen] = useState(false);

    const updateMutation = useUpdateDocumentMutation(document.id.toString());

    const form = useForm<FormValues>({
        initialValues: {
            title: '',
            workplaces: [],
            users: [],
            attachments: [],
            content: '',
            removedAttachmentIds: [],
        },
        validate: validatorResolver({
            title: Validator.string().required(),
            workplaces: Validator.array(),
            users: Validator.array(),
            attachments: Validator.array(),
            content: Validator.string(),
            removedAttachmentIds: Validator.array(),
        }),
    });

    const editor = useEditor({
        extensions: contentEditorExtensions,
        content: '',
        immediatelyRender: false,
    });

    useEffect(() => {
        if (isOpened && document && editor) {
            form.setValues({
                title: document.title || '',
                workplaces: document.workplaces.map((w) => w.id.toString()),
                users: document.users.map((u) => u.id.toString()),
                attachments: [],
                content: document.content || '',
                removedAttachmentIds: [],
            });
            editor.commands.setContent(document.content || '');
            setAttachmentsPreview(document.attachments || []);
        }
    }, [isOpened, document, editor]);

    const handleFilesChange = (files: File[]) => {
        form.setFieldValue('attachments', files);
    };

    const handleSubmit = async (values: FormValues) => {
        try {
            const formData = new FormData();
            formData.append('title', values.title);
            formData.append('id', document.id.toString());
            formData.append('content', editor?.getHTML() || '');

            values.workplaces.forEach((wpId) => formData.append('workplaces[]', wpId.toString()));
            values.users.forEach((userId) => formData.append('users[]', userId.toString()));
            values.attachments.forEach((file) => {
                if (file instanceof File) {
                    formData.append('attachments[]', file);
                }
            });

            values.removedAttachmentIds.forEach((id) => formData.append('remove_attachments[]', id.toString()));

            const response = await updateMutation.mutateAsync(formData);

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
                title={t('Update document')}
                size="xl"
                fullScreen={fullscreenOpen}
            >
                <form onSubmit={form.onSubmit(handleSubmit)}>
                    <SimpleGrid cols={1} spacing="md">
                        <TextInput withAsterisk label={t('Document name')} {...form.getInputProps('title')} />

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
                            initialOptions={document.users.map(renderUserItem)}
                            {...form.getInputProps('users')}
                        />

                        {attachmentsPreview.length > 0 && (
                            <div>
                                <Text>{t('Existing attachments')}:</Text>
                                <List spacing="xs" size="sm" center>
                                    {attachmentsPreview.map((file) => (
                                        <List.Item
                                            key={file.id}
                                            icon={
                                                <ActionIcon
                                                    variant="subtle"
                                                    color="red"
                                                    onClick={() => setAttachmentToRemove(file.id)}
                                                >
                                                    <LuTrash2 size={16} />
                                                </ActionIcon>
                                            }
                                        >
                                            <Anchor href={UrlHelper.getStaticUrl(file.url)}>{file.filename}</Anchor>
                                        </List.Item>
                                    ))}
                                </List>
                                <ul style={{ marginTop: 4, paddingRight: 36 }}></ul>
                            </div>
                        )}

                        <DocumentUpload onFilesChange={handleFilesChange} />

                        {editor && <TextEditor editor={editor} />}

                        <Flex justify="start">
                            <Button
                                variant="light"
                                size="xs"
                                mt="sm"
                                onClick={() => setFullscreenOpen(!fullscreenOpen)}
                                leftSection={fullscreenOpen ? <LuMinimize2 /> : <LuMaximize2 />}
                            >
                                {fullscreenOpen ? t('Collapse') : t('Expand')}
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

            <DConfirmModal
                opened={attachmentToRemove !== null}
                title={t('Remove attachment')}
                message={t('Are you sure you want to remove this file from document?')}
                onCancel={() => setAttachmentToRemove(null)}
                onConfirm={async () => {
                    if (!attachmentToRemove) return;

                    setIsRemovingAttachment(true);

                    try {
                        setAttachmentsPreview((prev) => prev.filter((att) => att.id !== attachmentToRemove));
                        const nextRemovedIds = [...form.values.removedAttachmentIds, attachmentToRemove];
                        form.setFieldValue('removedAttachmentIds', nextRemovedIds);
                        form.setFieldValue('content', editor?.getHTML() || '');

                        await handleSubmit({
                            ...form.values,
                            removedAttachmentIds: nextRemovedIds,
                            content: editor?.getHTML() || '',
                        });
                    } catch (error) {
                        NotificationHelper.showError(t('Failed to update document'));
                    } finally {
                        setIsRemovingAttachment(false);
                        setAttachmentToRemove(null);
                    }
                }}
                confirmButtonLabel={t('Remove')}
                confirmButtonColor="red"
                isProcessing={isRemovingAttachment}
            />
        </>
    );
};

export default UpdateDocumentModal;
