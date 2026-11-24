import { useEffect } from 'react';
import { Input } from '@mantine/core';
import { useEditor } from '@tiptap/react';
import TextEditor, { contentEditorExtensions } from '@/pages/manage/document/partials/TextEditor';

type Props = {
    label: string;
    value: string;
    onChange: (html: string) => void;
    error?: React.ReactNode;
    withAsterisk?: boolean;
};

const AnnouncementContentEditor = ({ label, value, onChange, error, withAsterisk }: Props) => {
    const editor = useEditor({
        extensions: contentEditorExtensions,
        content: value,
        autofocus: false,
        editable: true,
        immediatelyRender: false,
        onUpdate: ({ editor }) => onChange(editor.getHTML()),
    });

    useEffect(() => {
        if (!editor || editor.getHTML() === value) {
            return;
        }
        editor.commands.setContent(value || '', { emitUpdate: false });
    }, [editor, value]);

    return (
        <Input.Wrapper label={label} error={error} withAsterisk={withAsterisk}>
            <TextEditor editor={editor} />
        </Input.Wrapper>
    );
};

export default AnnouncementContentEditor;
