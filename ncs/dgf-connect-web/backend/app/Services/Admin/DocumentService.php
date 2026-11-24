<?php

namespace App\Services\Admin;

use App\Enums\AttachmentType;
use App\Models\Document;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class DocumentService
{
    public const ATTACHMENT_PATH = 'document/';

    public function listDocuments(array $conditions): LengthAwarePaginator
    {
        return Document::getPaginateFiltered($conditions, [], ['id' => 'asc'], $conditions['page_size'] ?? null);
    }

    public function searchDocuments(array $conditions): Collection
    {
        $query = Document::getFilteredQuery($conditions, null, ['id' => 'asc'])->select(['id', 'title']);

        if (isset($conditions['query'])) {
            $query->limit(20);
        }

        return $query->get();
    }

    public function show(Document $document): Document
    {
        $document->load(['users', 'workplaces', 'attachments']);

        return $document;
    }

    public function create(array $params): Document
    {
        return DB::transaction(function () use ($params) {
            $document = Document::query()->create([
                'title' => $params['title'],
                'content' => $params['content'],
            ]);
            if (isset($params['users'])) {
                $document->users()->sync($params['users']);
            }
            if (isset($params['workplaces'])) {
                $document->workplaces()->sync($params['workplaces']);
            }
            if (isset($params['attachments'])) {
                $this->handleFilesUpload($document, $params['attachments']);
            }
            if (isset($params['links'])) {
                $this->handleLinkAttach($document, $params['links']);
            }

            return $document;
        });
    }

    public function update(Document $document, array $params): Document
    {
        return DB::transaction(function () use ($document, $params) {
            $updateData = array_filter(
                [
                    'title' => $params['title'] ?? null,
                    'content' => $params['content'] ?? null,
                ],
                function ($value) {
                    return null !== $value;
                }
            );
            $document->update($updateData);

            if (isset($params['users'])) {
                $document->users()->sync($params['users']);
            }
            if (isset($params['workplaces'])) {
                $document->workplaces()->sync($params['workplaces']);
            }
            if (isset($params['attachments'])) {
                $this->handleFilesUpload($document, $params['attachments']);
            }
            if (isset($params['links'])) {
                $this->handleLinkAttach($document, $params['links']);
            }
            if (isset($params['remove_attachments'])) {
                foreach ($params['remove_attachments'] as $attachmentId) {
                    $attachment = $document->attachments()->find($attachmentId);
                    if ($attachment && $attachment->type === AttachmentType::File->value) {
                        Storage::delete($attachment->url);
                    }
                }
                $document->attachments()->whereIn('id', $params['remove_attachments'])->delete();
            }
            if (isset($params['remove_users'])) {
                $document->users()->detach($params['remove_users']);
            }
            if (isset($params['remove_workplaces'])) {
                $document->workplaces()->detach($params['remove_workplaces']);
            }

            return $document;
        });
    }

    public function delete(Document $document): ?bool
    {
        Storage::deleteDirectory(self::ATTACHMENT_PATH . $document->id);

        return $document->delete();
    }

    public function listAccessibleDocuments(User $user, int $pageSize = 10): LengthAwarePaginator
    {
        return Document::with(['attachments'])
            ->whereHas('users', fn($q) => $q->where('users.id', $user->id))
            ->orderByDesc('created_at')
            ->paginate($pageSize);
    }

    public function getAccessibleDocument(User $user, int $documentId): ?Document
    {
        return Document::with(['attachments'])
            ->where('id', $documentId)
            ->whereHas('users', fn($q) => $q->where('users.id', $user->id))
            ->first();
    }

    private function handleFilesUpload(Document $document, array $attachments): void
    {
        try {
            $uploadedPaths = collect($attachments)->map(function ($attachment) use ($document) {
                $path = $attachment->store(self::ATTACHMENT_PATH . $document->id);

                return [
                    'document_id' => 1,
                    'filename' => $attachment->getClientOriginalName(),
                    'url' => $path,
                    'type' => AttachmentType::File->value,
                ];
            });
            $document->attachments()->createMany($uploadedPaths);
        } catch (\Exception $exception) {
            Log::error('Error storing document file upload', [$exception->getMessage()]);
        }
    }

    private function handleLinkAttach(Document $document, array $links): void
    {
        $links = collect($links)->map(function ($link) {
            return [
                'document_id' => 1,
                'url' => $link,
                'type' => AttachmentType::Link->value,
            ];
        });
        $document->attachments()->createMany($links);
    }
}
