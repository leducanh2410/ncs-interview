<?php

namespace App\Actions\Admin\AdminLeave;

use App\Actions\ActionResult;
use App\Enums\AdminLeaveRequestStatus;
use App\Models\AdminLeaveRequest;
use App\Models\User;
use App\Services\AdminLeave\LeaveDayCalculator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class CreateAdminLeaveRequestAction
{
    public function __construct(
        private readonly LeaveDayCalculator $calculator,
        private readonly FindAdminLeaveRequestConflictAction $findConflict
    ) {}

    /**
     * @param array<string, mixed> $data validated payload
     * @param UploadedFile[] $files zero to three evidence files
     */
    public function __invoke(User $requester, array $data, array $files = []): ActionResult
    {
        $disk = config('filesystems.default');
        $storedPaths = [];

        try {
            $attachmentRows = $this->storeFiles($requester, $files, $disk, $storedPaths);

            $request = DB::transaction(function () use ($requester, $data, $attachmentRows) {
                $leaveDays = null;
                if (!empty($data['start_date']) && !empty($data['end_date'])) {
                    // Computed inside the transaction because the calculator may generate a
                    // missing holiday year as a side effect; a failure here must take the
                    // half-generated year down with the request.
                    $leaveDays = $this->calculator->countDays(
                        Carbon::parse($data['start_date']),
                        Carbon::parse($data['end_date'])
                    );
                }

                /** @var AdminLeaveRequest $request */
                $request = AdminLeaveRequest::query()->create([
                    'requester_id' => $requester->id,
                    'type' => $data['type'],
                    'start_date' => $data['start_date'] ?? null,
                    'end_date' => $data['end_date'] ?? null,
                    'leave_days' => $leaveDays,
                    'reason' => $data['reason'],
                    'status' => AdminLeaveRequestStatus::Pending->value,
                ]);

                if ($attachmentRows !== []) {
                    $request->attachments()->createMany($attachmentRows);
                }

                return $request;
            });
        } catch (Throwable $e) {
            // The transaction rolled back, so the attachment rows are gone; the files it was
            // going to point at must not stay behind as orphans.
            Storage::disk($disk)->delete($storedPaths);

            throw $e;
        }

        $request->load('attachments');

        return new ActionResult(
            data: AdminLeaveRequestPayload::forDetail($request, ($this->findConflict)($request)),
            status: 201
        );
    }

    /**
     * @param UploadedFile[] $files
     * @param string[] $storedPaths filled with every file written, for cleanup on failure
     * @return array<int, array<string, mixed>>
     */
    private function storeFiles(User $requester, array $files, string $disk, array &$storedPaths): array
    {
        $rows = [];
        foreach ($files as $file) {
            // The stored name is a generated UUID: a hostile original filename cannot influence
            // the path, and the real name is kept as data instead.
            $path = 'admin-leave/' . $requester->id . '/' . Str::uuid() . '.' . $file->getClientOriginalExtension();
            if (Storage::disk($disk)->put($path, $file->get()) === false) {
                throw new RuntimeException('Failed to write attachment to storage: ' . $path);
            }
            $storedPaths[] = $path;

            $rows[] = [
                'disk' => $disk,
                'path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'size_bytes' => $file->getSize(),
            ];
        }

        return $rows;
    }
}
