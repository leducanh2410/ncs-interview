<?php

namespace App\Actions\Admin\User;

use App\Enums\UserPaperStatus;
use App\Jobs\EnforceRequiredDocumentsJob;
use App\Models\User;
use Illuminate\Support\Collection;

class DispatchRequiredDocumentsEnforcementAction
{
    public const CHUNK_SIZE = 200;

    public function __construct(
        private readonly ResolveMissingRequiredDocumentsAction $resolveMissingRequiredDocuments,
        private readonly ResolveMissingStudentCardExpiryAction $resolveMissingStudentCardExpiry,
        private readonly ResolveWebAdminAccessAction $resolveWebAdminAccess
    ) {}

    public function __invoke(): int
    {
        $queuedUsers = 0;

        $this->eachChunkOfUsersOwingDocuments(function (array $userIds) use (&$queuedUsers) {
            EnforceRequiredDocumentsJob::dispatch($userIds);
            $queuedUsers += count($userIds);
        });

        return $queuedUsers;
    }

    /**
     * @param callable(int[]): void $callback
     */
    public function eachChunkOfUsersOwingDocuments(callable $callback): void
    {
        User::query()
            ->where('paper_status', UserPaperStatus::Approved->value)
            ->whereHas('paper')
            ->with(['info', 'paper'])
            ->chunkById(self::CHUNK_SIZE, function (Collection $users) use ($callback) {
                $userIds = $this->idsOfUsersOwingDocuments($users);
                if ($userIds !== []) {
                    $callback($userIds);
                }
            });
    }

    /**
     * @param Collection<int, User> $users
     * @return int[]
     */
    private function idsOfUsersOwingDocuments(Collection $users): array
    {
        $missingByUser = $this->resolveMissingRequiredDocuments->forUsers($users);
        $webAdmins = $this->resolveWebAdminAccess->forUsers($users);

        return $users
            ->reject(fn(User $user) => isset($webAdmins[$user->id]))
            ->filter(
                fn(User $user) => isset($missingByUser[$user->id]) ||
                    ($this->resolveMissingStudentCardExpiry)($user, [])
            )
            ->pluck('id')
            ->values()
            ->all();
    }
}
