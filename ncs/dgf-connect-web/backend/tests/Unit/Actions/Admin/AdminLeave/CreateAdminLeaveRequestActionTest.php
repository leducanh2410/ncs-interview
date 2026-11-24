<?php

namespace Tests\Unit\Actions\Admin\AdminLeave;

use App\Actions\Admin\AdminLeave\CreateAdminLeaveRequestAction;
use App\Contracts\HolidayProviderContract;
use App\Enums\AdminLeaveRequestStatus;
use App\Enums\AdminLeaveType;
use App\Enums\CompanyHolidaySource;
use App\Models\AdminLeaveRequest;
use App\Models\AdminLeaveRequestAttachment;
use App\Models\CompanyHoliday;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Support\FakeHolidayProvider;
use Tests\TestCase;

class CreateAdminLeaveRequestActionTest extends TestCase
{
    use RefreshDatabase;

    private User $requester;

    private string $disk;

    protected function setUp(): void
    {
        parent::setUp();

        $this->requester = User::factory()->create();
        $this->disk = config('filesystems.default');
        Storage::fake($this->disk);

        $this->app->bind(
            HolidayProviderContract::class,
            fn() => new FakeHolidayProvider([2027 => ['2027-01-01' => '元日']])
        );
    }

    public function testPersistsAPendingRequestWithTheComputedDayCount(): void
    {
        $this->markYearGenerated(2027);
        CompanyHoliday::query()->create([
            'date' => '2027-09-08',
            'name' => '社内休業日',
            'source' => CompanyHolidaySource::Manual->value,
        ]);

        $request = $this->create([
            'type' => AdminLeaveType::PaidLeave->value,
            'start_date' => '2027-09-06',
            'end_date' => '2027-09-10',
            'reason' => 'Family trip',
        ]);

        $this->assertSame(AdminLeaveRequestStatus::Pending, $request->status);
        $this->assertSame($this->requester->id, $request->requester_id);
        // Five dates minus the one company holiday inside them.
        $this->assertSame(4, $request->leave_days);
        $this->assertNotNull($request->created_at);
        $this->assertNull($request->decided_by_id);
        $this->assertNull($request->decided_at);
    }

    public function testPersistsANullDayCountForADatelessExpenseClaim(): void
    {
        $request = $this->create([
            'type' => AdminLeaveType::ExpenseClaim->value,
            'reason' => 'Taxi fare 4,300 JPY',
        ]);

        $this->assertNull($request->leave_days);
        $this->assertNull($request->start_date);
        $this->assertNull($request->end_date);
        $this->assertSame(AdminLeaveRequestStatus::Pending, $request->status);
    }

    public function testStoresAttachmentsWithTheirMetadata(): void
    {
        $this->markYearGenerated(2027);

        $request = $this->create(
            [
                'type' => AdminLeaveType::PaidLeave->value,
                'start_date' => '2027-09-06',
                'end_date' => '2027-09-06',
                'reason' => 'Medical',
            ],
            [
                UploadedFile::fake()->image('certificate.png'),
                UploadedFile::fake()->create('receipt.pdf', 12, 'application/pdf'),
            ]
        );

        $attachments = $request->attachments;
        $this->assertCount(2, $attachments);
        $this->assertSame(['certificate.png', 'receipt.pdf'], $attachments->pluck('original_name')->all());
        foreach ($attachments as $attachment) {
            $this->assertSame($this->disk, $attachment->disk);
            Storage::disk($this->disk)->assertExists($attachment->path);
            $this->assertGreaterThan(0, $attachment->size_bytes);
            // The stored path must not carry the uploaded name through.
            $this->assertStringNotContainsString($attachment->original_name, $attachment->path);
            $this->assertStringStartsWith('admin-leave/' . $this->requester->id . '/', $attachment->path);
        }
        $this->assertTrue($attachments->firstWhere('original_name', 'certificate.png')->is_image);
        $this->assertFalse($attachments->firstWhere('original_name', 'receipt.pdf')->is_image);
    }

    public function testDeletesEveryWrittenFileWhenTheTransactionFails(): void
    {
        $this->markYearGenerated(2027);

        // Fail on the attachment insert, i.e. after the files are on disk and after the request
        // row exists — the exact window where a rollback leaves orphaned files behind if the
        // action does not clean up.
        AdminLeaveRequestAttachment::creating(function () {
            throw new RuntimeException('attachment insert failed');
        });

        $thrown = false;
        try {
            $this->create(
                [
                    'type' => AdminLeaveType::PaidLeave->value,
                    'start_date' => '2027-09-06',
                    'end_date' => '2027-09-06',
                    'reason' => 'Medical',
                ],
                [UploadedFile::fake()->image('a.png'), UploadedFile::fake()->image('b.png')]
            );
        } catch (\Throwable) {
            $thrown = true;
        } finally {
            AdminLeaveRequestAttachment::flushEventListeners();
        }

        $this->assertTrue($thrown, 'The action was expected to rethrow the persistence failure.');
        $this->assertSame(0, AdminLeaveRequest::query()->count());
        $this->assertSame(0, AdminLeaveRequestAttachment::query()->count());
        $this->assertSame([], Storage::disk($this->disk)->allFiles('admin-leave/' . $this->requester->id));
    }

    public function testDeletesWrittenFilesWhenTheDayCalculationFails(): void
    {
        // Holiday generation running inside the transaction means a calendar problem can abort a
        // request creation. The files written before the transaction must still be cleaned up.
        $this->app->bind(
            HolidayProviderContract::class,
            fn() => new class implements HolidayProviderContract {
                public function holidaysForYear(int $year): array
                {
                    throw new RuntimeException('holiday source unavailable');
                }
            }
        );

        $thrown = false;
        try {
            $this->create(
                [
                    'type' => AdminLeaveType::PaidLeave->value,
                    'start_date' => '2027-09-06',
                    'end_date' => '2027-09-10',
                    'reason' => 'Family trip',
                ],
                [UploadedFile::fake()->image('a.png')]
            );
        } catch (\Throwable) {
            $thrown = true;
        }

        $this->assertTrue($thrown);
        $this->assertSame(0, AdminLeaveRequest::query()->count());
        $this->assertSame(0, CompanyHoliday::query()->count());
        $this->assertSame([], Storage::disk($this->disk)->allFiles('admin-leave/' . $this->requester->id));
    }

    public function testDeletesAlreadyWrittenFilesWhenAStorageWriteSilentlyFails(): void
    {
        $this->markYearGenerated(2027);

        // Every disk is configured with 'throw' => false, so a full/broken storage backend makes
        // put() return false instead of throwing. The second file's write is the one that fails;
        // the first must not be left behind as an orphan pointing nowhere.
        Storage::set(
            $this->disk,
            new class (Storage::disk($this->disk)) {
                private int $writes = 0;

                public function __construct(private readonly mixed $inner) {}

                public function put(...$arguments): bool
                {
                    $this->writes++;

                    return $this->writes >= 2 ? false : $this->inner->put(...$arguments);
                }

                public function __call(string $name, array $arguments): mixed
                {
                    return $this->inner->{$name}(...$arguments);
                }
            }
        );

        $thrown = false;
        try {
            $this->create(
                [
                    'type' => AdminLeaveType::PaidLeave->value,
                    'start_date' => '2027-09-06',
                    'end_date' => '2027-09-06',
                    'reason' => 'Medical',
                ],
                [UploadedFile::fake()->image('a.png'), UploadedFile::fake()->image('b.png')]
            );
        } catch (\Throwable) {
            $thrown = true;
        }

        $this->assertTrue($thrown, 'A silent storage write failure was expected to abort the request.');
        $this->assertSame(0, AdminLeaveRequest::query()->count());
        $this->assertSame(0, AdminLeaveRequestAttachment::query()->count());
        $this->assertSame([], Storage::disk($this->disk)->allFiles('admin-leave/' . $this->requester->id));
    }

    public function testAcceptsARequestOverlappingAnExistingPendingOne(): void
    {
        $this->markYearGenerated(2027);
        $first = $this->create([
            'type' => AdminLeaveType::LongLeave->value,
            'start_date' => '2027-09-06',
            'end_date' => '2027-09-20',
            'reason' => 'Long leave',
        ]);

        // A 有休 inside a pending 長期休み is a legitimate combination; judging it is the
        // manager's job, so creation must not reject it.
        $second = $this->create([
            'type' => AdminLeaveType::PaidLeave->value,
            'start_date' => '2027-09-10',
            'end_date' => '2027-09-11',
            'reason' => 'Paid leave inside the long leave',
        ]);

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(2, AdminLeaveRequest::query()->count());
        $this->assertSame(AdminLeaveRequestStatus::Pending, $second->status);
    }

    public function testGeneratesAMissingHolidayYearWhileCounting(): void
    {
        $this->assertSame(0, CompanyHoliday::query()->count());

        $request = $this->create([
            'type' => AdminLeaveType::PaidLeave->value,
            'start_date' => '2027-01-01',
            'end_date' => '2027-01-05',
            'reason' => 'New year',
        ]);

        $this->assertSame(1, CompanyHoliday::query()->count());
        // Five dates minus the generated 元日.
        $this->assertSame(4, $request->leave_days);
    }

    /**
     * @param array<string, mixed> $data
     * @param UploadedFile[] $files
     */
    private function create(array $data, array $files = []): AdminLeaveRequest
    {
        $result = $this->app->make(CreateAdminLeaveRequestAction::class)($this->requester, $data, $files)->getData();

        return AdminLeaveRequest::query()->with('attachments')->findOrFail($result['id']);
    }

    private function markYearGenerated(int $year): void
    {
        CompanyHoliday::query()->create([
            'date' => $year . '-01-01',
            'name' => '元日',
            'source' => CompanyHolidaySource::Library->value,
        ]);
    }
}
