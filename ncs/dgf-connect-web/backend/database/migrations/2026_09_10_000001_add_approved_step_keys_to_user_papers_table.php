<?php

use App\Actions\User\Document\ResolveRequiredStepKeysAction;
use App\Enums\UserPaperStatus;
use App\Enums\UserPaperStepKey;
use App\Support\Residence\ResidenceSlotResolver;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * The document set the last full approval covered.
     *
     * A renewal lets that approval stand in for the documents it reviewed, so the set has
     * to be recorded at the only moment it is certain: the approval itself, when an admin
     * has just looked at the residence card the profile carries. Deriving it later from the
     * live profile does not work — the employee may write residence_status_1 through
     * POST /user/profile, and the renewal's own first step overwrites the status anyway.
     *
     * Every approval from here on fills the column in. This backfills the rows that were
     * approved before it existed, so their first renewal after deploy is not answered from
     * a fallback.
     */
    public function up(): void
    {
        Schema::table('user_papers', function (Blueprint $table) {
            $table->json('approved_step_keys')->nullable()->after('submission_started_from_approved');
        });

        $resolve = new ResolveRequiredStepKeysAction();

        // Settled rows: no renewal is open, so the residence status on the profile is still
        // the one the last approval was granted under. This is what keeps an employee
        // approved as 留学 under the old flow from being asked for a student card and a
        // 資格外活動許可 that predate the step flow and were never on file.
        DB::table('user_papers')
            ->join('users', 'users.id', '=', 'user_papers.user_id')
            ->leftJoin('user_infos', 'user_infos.user_id', '=', 'users.id')
            ->where('users.paper_status', UserPaperStatus::Approved->value)
            ->where('user_papers.submission_started_from_approved', false)
            ->select([
                'user_papers.id',
                'user_infos.residence_number_1',
                'user_infos.residence_expiry_date_1',
                'user_infos.residence_status_1',
                'user_infos.residence_number_2',
                'user_infos.residence_expiry_date_2',
                'user_infos.residence_status_2',
                'user_infos.residence_number_3',
                'user_infos.residence_expiry_date_3',
                'user_infos.residence_status_3',
            ])
            ->orderBy('user_papers.id')
            ->chunk(500, function ($rows) use ($resolve) {
                foreach ($rows as $row) {
                    $cards = [];
                    for ($slot = 1; $slot <= ResidenceSlotResolver::SLOT_COUNT; $slot++) {
                        $cards[$slot] = [
                            'number' => $row->{"residence_number_{$slot}"},
                            'expiry' => $row->{"residence_expiry_date_{$slot}"},
                            'status' => $row->{"residence_status_{$slot}"},
                        ];
                    }

                    $latestSlot = ResidenceSlotResolver::latestSlotFromCards($cards);
                    $keys = $resolve($latestSlot === null ? null : $cards[$latestSlot]['status']);

                    DB::table('user_papers')
                        ->where('id', $row->id)
                        ->update([
                            'approved_step_keys' => json_encode(
                                array_map(fn(UserPaperStepKey $key) => $key->value, $keys)
                            ),
                        ]);
                }
            });

        // Rows with a renewal already open have no trustworthy answer left: the profile may
        // already carry the card that renewal declared. They get the base set every approval
        // has always covered — safe in one direction only. It can ask an employee mid-renewal
        // for a document their old approval did include, but it can never let one through
        // that no approval ever saw. Their next approval records the real set.
        DB::table('user_papers')
            ->where('submission_started_from_approved', true)
            ->update([
                'approved_step_keys' => json_encode([
                    UserPaperStepKey::ResidenceCard->value,
                    UserPaperStepKey::Passport->value,
                    UserPaperStepKey::Portrait->value,
                ]),
            ]);
    }

    public function down(): void
    {
        Schema::table('user_papers', function (Blueprint $table) {
            $table->dropColumn('approved_step_keys');
        });
    }
};
