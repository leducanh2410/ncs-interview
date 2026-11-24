<?php

namespace Tests\Unit\Jobs;

use App\Jobs\DocumentExpiryWarningReminder;
use App\Mail\DocumentExpiryWarningEmail;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class DocumentExpiryWarningReminderTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The job probes for a single date — today + 14 — so the clock has to be frozen or a
     * test straddling midnight would build its fixtures against a different day than the
     * one the job asks for.
     */
    private const TODAY = '2026-09-16 07:00:00';

    private const EXPIRY_DATE = '2026-09-30';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse(self::TODAY));
        Mail::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * The regression this suite exists for: the passport column used to be text compared
     * against a `Y/m/d` probe, so it never matched and a passport was the one document
     * that expired without a warning.
     */
    public function testWarnsAboutPassportExpiringInFourteenDays(): void
    {
        $user = $this->userWithDocument('passport_expiry_date', self::EXPIRY_DATE);

        (new DocumentExpiryWarningReminder())->handle();

        $this->assertWarned($user);
    }

    public function testWarnsAboutStudentCardExpiringInFourteenDays(): void
    {
        $user = $this->userWithDocument('student_card_expiry_date', self::EXPIRY_DATE);

        (new DocumentExpiryWarningReminder())->handle();

        $this->assertWarned($user);
    }

    public function testWarnsAboutEveryResidenceCardSlot(): void
    {
        $users = [
            $this->userWithDocument('residence_expiry_date_1', self::EXPIRY_DATE),
            $this->userWithDocument('residence_expiry_date_2', self::EXPIRY_DATE),
            $this->userWithDocument('residence_expiry_date_3', self::EXPIRY_DATE),
        ];

        (new DocumentExpiryWarningReminder())->handle();

        foreach ($users as $user) {
            $this->assertWarned($user);
        }
    }

    /**
     * Several documents expiring on the same day are one piece of news, and the email does
     * not name the document anyway — a second copy would just read as a duplicate.
     */
    public function testSendsOneEmailWhenSeveralDocumentsExpireOnTheSameDay(): void
    {
        $user = $this->userWithDocument('passport_expiry_date', self::EXPIRY_DATE, [
            'student_card_expiry_date' => self::EXPIRY_DATE,
            'residence_expiry_date_1' => self::EXPIRY_DATE,
        ]);

        (new DocumentExpiryWarningReminder())->handle();

        Mail::assertSent(DocumentExpiryWarningEmail::class, 1);
        $this->assertWarned($user);
    }

    /**
     * The window is an exact day, not a range: the day before is too early, and the day
     * after means yesterday's run already sent the warning.
     */
    public function testIgnoresDocumentsExpiringOutsideTheExactWindow(): void
    {
        $this->userWithDocument('passport_expiry_date', '2026-09-29');
        $this->userWithDocument('passport_expiry_date', '2026-10-01');
        $this->userWithDocument('student_card_expiry_date', '2026-09-29');
        $this->userWithDocument('residence_expiry_date_1', '2026-10-01');

        (new DocumentExpiryWarningReminder())->handle();

        Mail::assertNothingSent();
    }

    public function testIgnoresUsersWithoutAnyExpiryDate(): void
    {
        $this->userWithDocument('passport_expiry_date', null);

        (new DocumentExpiryWarningReminder())->handle();

        Mail::assertNothingSent();
    }

    public function testIgnoresDisabledUsers(): void
    {
        $user = $this->userWithDocument('passport_expiry_date', self::EXPIRY_DATE);
        $user->update(['is_disabled' => true]);

        (new DocumentExpiryWarningReminder())->handle();

        Mail::assertNothingSent();
    }

    /**
     * A user is warned once per expiry date, not once ever: a renewed passport whose new
     * expiry lands on the window has to warn again years after the first reminder.
     */
    public function testWarnsAgainForARenewedDocument(): void
    {
        $user = $this->userWithDocument('passport_expiry_date', self::EXPIRY_DATE);

        (new DocumentExpiryWarningReminder())->handle();
        $this->assertWarned($user);

        $renewedExpiry = Carbon::parse(self::EXPIRY_DATE)->addYears(5);
        $user->info()->update(['passport_expiry_date' => $renewedExpiry->format('Y-m-d')]);

        Mail::fake();
        Carbon::setTestNow($renewedExpiry->copy()->subDays(14)->setTime(7, 0));

        (new DocumentExpiryWarningReminder())->handle();

        $this->assertWarned($user);
    }

    private function userWithDocument(string $column, ?string $expiryDate, array $extraInfo = []): User
    {
        $user = User::factory()->create();
        $user->info()->update(
            array_merge(
                [
                    'passport_expiry_date' => null,
                    'student_card_expiry_date' => null,
                    'residence_expiry_date_1' => null,
                    'residence_expiry_date_2' => null,
                    'residence_expiry_date_3' => null,
                    $column => $expiryDate,
                ],
                $extraInfo
            )
        );

        return $user;
    }

    private function assertWarned(User $user): void
    {
        Mail::assertSent(
            DocumentExpiryWarningEmail::class,
            fn(DocumentExpiryWarningEmail $mail) => $mail->hasTo($user->email)
        );
    }
}
