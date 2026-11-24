import { Card } from '@mantine/core';

type DocumentPreviewProps = {
    content: string;
};

const DocumentPreview = ({ content }: DocumentPreviewProps) => {
    return (
        <Card shadow="sm" padding="md" radius="md" withBorder>
            <div className="prose max-w-none" dangerouslySetInnerHTML={{ __html: content }} />
        </Card>
    );
};

export default DocumentPreview;
