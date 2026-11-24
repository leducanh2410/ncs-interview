<?php

namespace App\Services\Reconciliation;

use App\Enums\ReconciliationMatchMethod;
use App\Enums\ReconciliationMatchStatus;
use App\Models\User;
use Illuminate\Support\Collection;

class ReconciliationNameMatcher
{
    private const MAX_CANDIDATES = 10;

    /** @var array<int, array{first: string, last: string, name: string, short: string, reversed: string}> */
    private array $normalizedCache = [];

    /**
     * @param Collection<int, User> $users
     * @param array<int, true> $priorityUserIds users with a Check_In on the work date
     * @return array{
     *     status: ReconciliationMatchStatus,
     *     method: ?ReconciliationMatchMethod,
     *     user: ?User,
     *     candidate_ids: list<int>
     * }
     */
    public function match(string $externalName, Collection $users, array $priorityUserIds = []): array
    {
        $tokens = ReconciliationNameNormalizer::tokens($externalName);
        if ($tokens === []) {
            return $this->result(ReconciliationMatchStatus::Unmatched);
        }

        // Exact uniqueness is global (Requirement 5.2/5.3): a similar hit in the
        // on-duty pool must not hide a unique exact hit in the rest of the
        // directory. Similar matching is the expensive pass, so it still runs
        // on-duty first and only scans the remaining directory while the
        // candidate cap has room left.
        $exact = [];
        $method = null;
        foreach ($users as $user) {
            $hit = $this->exactHit($tokens, $user);
            if ($hit === null) {
                continue;
            }
            $exact[] = $user;
            $method = $hit;
        }
        if (count($exact) === 1) {
            return $this->result(ReconciliationMatchStatus::Exact, $method, $exact[0]);
        }
        if (count($exact) > 1) {
            return $this->result(
                ReconciliationMatchStatus::Ambiguous,
                null,
                null,
                $this->rankIds($exact, $priorityUserIds)
            );
        }

        $priorityUsers =
            $priorityUserIds === [] ? $users : $users->filter(fn(User $user) => isset($priorityUserIds[$user->id]));
        $similar = $this->similarIn($tokens, $priorityUsers);
        // Being on duty only *ranks* a candidate first (rankIds() sorts, then
        // caps at MAX_CANDIDATES) — it must not filter the rest of the
        // directory out. isSimilar() is loose enough that a single weak
        // on-duty hit would otherwise hide every other similar name, including
        // the right person when they have no Check_In that day at all
        // (Requirement 5.4/5.5, and the no_attendance flow of 6.4). The rest of
        // the directory is only scanned while the cap still has room, since
        // beyond that the extra names could never be shown anyway.
        if (count($similar) < self::MAX_CANDIDATES && $priorityUserIds !== []) {
            $rest = $users->filter(fn(User $user) => !isset($priorityUserIds[$user->id]));
            $similar = array_merge($similar, $this->similarIn($tokens, $rest));
        }
        if ($similar === []) {
            return $this->result(ReconciliationMatchStatus::Unmatched);
        }

        return $this->result(
            ReconciliationMatchStatus::Similar,
            null,
            null,
            $this->rankIds($similar, $priorityUserIds)
        );
    }

    /**
     * @param list<string> $tokens
     * @param Collection<int, User> $users
     * @return list<User>
     */
    private function similarIn(array $tokens, Collection $users): array
    {
        $similar = [];
        foreach ($users as $user) {
            if ($this->isSimilar($tokens, $user)) {
                $similar[] = $user;
            }
        }

        return $similar;
    }

    /**
     * @return array{first: string, last: string, name: string, short: string, reversed: string}
     */
    private function normalized(User $user): array
    {
        return $this->normalizedCache[$user->id] ??= [
            'first' => ReconciliationNameNormalizer::compact($user->first_name_kana),
            'last' => ReconciliationNameNormalizer::compact($user->last_name_kana),
            'name' => ReconciliationNameNormalizer::compact($user->name_kana),
            'short' => ReconciliationNameNormalizer::compact($user->short_name),
            'reversed' => ReconciliationNameNormalizer::compact($user->first_name_kana . $user->last_name_kana),
        ];
    }

    /**
     * @param list<string> $tokens
     */
    private function exactHit(array $tokens, User $user): ?ReconciliationMatchMethod
    {
        $n = $this->normalized($user);
        $firstKana = $n['first'];
        $lastKana = $n['last'];
        $nameKana = $n['name'];
        $shortName = $n['short'];
        $joined = implode('', $tokens);

        if (count($tokens) === 1 && ($joined === $firstKana || $joined === $lastKana)) {
            return ReconciliationMatchMethod::ExactKana;
        }

        $reversedKana = $n['reversed'];
        if ($joined === $nameKana || $joined === $reversedKana) {
            return ReconciliationMatchMethod::ExactKana;
        }
        if ($shortName !== '' && $joined === $shortName) {
            return ReconciliationMatchMethod::ExactShort;
        }

        return null;
    }

    /**
     * @param list<string> $tokens
     */
    private function isSimilar(array $tokens, User $user): bool
    {
        $n = $this->normalized($user);
        $nameKana = $n['name'];
        $shortName = $n['short'];
        $haystacks = array_values(array_filter([$nameKana, $shortName]));
        if ($haystacks === []) {
            return false;
        }

        $allTokensInside = true;
        foreach ($tokens as $token) {
            $inAny = false;
            foreach ($haystacks as $haystack) {
                if ($token !== '' && str_contains($haystack, $token)) {
                    $inAny = true;
                    break;
                }
            }
            if (!$inAny) {
                $allTokensInside = false;
                break;
            }
        }
        if ($allTokensInside) {
            return true;
        }

        $joined = implode('', $tokens);
        foreach ($haystacks as $haystack) {
            if ($haystack !== '' && (str_contains($joined, $haystack) || str_contains($haystack, $joined))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<User> $users
     * @param array<int, true> $priorityUserIds
     * @return list<int>
     */
    private function rankIds(array $users, array $priorityUserIds): array
    {
        usort($users, function (User $a, User $b) use ($priorityUserIds) {
            $aPri = isset($priorityUserIds[$a->id]) ? 0 : 1;
            $bPri = isset($priorityUserIds[$b->id]) ? 0 : 1;

            return $aPri <=> $bPri;
        });

        $ids = array_map(fn(User $user) => $user->id, $users);

        return array_slice($ids, 0, self::MAX_CANDIDATES);
    }

    /**
     * @param list<int> $candidateIds
     * @return array{
     *     status: ReconciliationMatchStatus,
     *     method: ?ReconciliationMatchMethod,
     *     user: ?User,
     *     candidate_ids: list<int>
     * }
     */
    private function result(
        ReconciliationMatchStatus $status,
        ?ReconciliationMatchMethod $method = null,
        ?User $user = null,
        array $candidateIds = []
    ): array {
        return [
            'status' => $status,
            'method' => $method,
            'user' => $user,
            'candidate_ids' => $candidateIds,
        ];
    }
}
