<?php

namespace Tests\Unit\Services\PdfTemplate;

use App\Services\Admin\PdfTemplate\PdfTemplateLockService;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class PdfTemplateLockServiceTest extends TestCase
{
    private PdfTemplateLockService $lockService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lockService = new PdfTemplateLockService();

        // Clean up any existing test locks
        Cache::forget('pdf_template:lock:1');
        Cache::forget('pdf_template:lock:2');
        Cache::forget('pdf_template:lock:999');
    }

    public function testAcquiresLockSuccessfully(): void
    {
        $result = $this->lockService->acquire(1, 10, 'editing', 'Admin User');

        $this->assertTrue($result);
        $this->assertTrue($this->lockService->isLocked(1));
    }

    public function testRejectsLockWhenAlreadyLockedByAnotherUser(): void
    {
        $this->lockService->acquire(1, 10, 'editing', 'Admin User');

        $result = $this->lockService->acquire(1, 20, 'editing', 'Other User');

        $this->assertFalse($result);
    }

    public function testGetReturnsLockInfo(): void
    {
        $this->lockService->acquire(1, 10, 'editing', 'Admin User');

        $lockInfo = $this->lockService->get(1);

        $this->assertNotNull($lockInfo);
        $this->assertEquals(10, $lockInfo['user_id']);
        $this->assertEquals('editing', $lockInfo['reason']);
        $this->assertEquals('Admin User', $lockInfo['user_name']);
        $this->assertArrayHasKey('locked_at', $lockInfo);
        $this->assertArrayHasKey('ttl', $lockInfo);
        $this->assertLessThanOrEqual(1800, $lockInfo['ttl']);
        $this->assertGreaterThan(1700, $lockInfo['ttl']);
    }

    public function testGetReturnsNullWhenNotLocked(): void
    {
        $lockInfo = $this->lockService->get(999);

        $this->assertNull($lockInfo);
    }

    public function testReleasesLockByOwner(): void
    {
        $this->lockService->acquire(1, 10, 'editing', 'Admin User');

        $result = $this->lockService->release(1, 10);

        $this->assertTrue($result);
        $this->assertFalse($this->lockService->isLocked(1));
    }

    public function testRejectsReleaseByNonOwner(): void
    {
        $this->lockService->acquire(1, 10, 'editing', 'Admin User');

        $result = $this->lockService->release(1, 20);

        $this->assertFalse($result);
        $this->assertTrue($this->lockService->isLocked(1));
    }

    public function testReleaseReturnsTrueWhenNotLocked(): void
    {
        $result = $this->lockService->release(999, 10);

        $this->assertTrue($result);
    }

    public function testRefreshesLockByOwner(): void
    {
        $this->lockService->acquire(1, 10, 'editing', 'Admin User');

        $result = $this->lockService->refresh(1, 10);

        $this->assertTrue($result);
        $this->assertTrue($this->lockService->isLocked(1));
    }

    public function testRejectsRefreshByNonOwner(): void
    {
        $this->lockService->acquire(1, 10, 'editing', 'Admin User');

        $result = $this->lockService->refresh(1, 20);

        $this->assertFalse($result);
    }

    public function testRejectsRefreshWhenNotLocked(): void
    {
        $result = $this->lockService->refresh(999, 10);

        $this->assertFalse($result);
    }

    public function testIsLockedReturnsFalseWhenNotLocked(): void
    {
        $this->assertFalse($this->lockService->isLocked(999));
    }

    public function testIsLockedReturnsTrueWhenLocked(): void
    {
        $this->lockService->acquire(1, 10, 'editing', 'Admin User');

        $this->assertTrue($this->lockService->isLocked(1));
    }

    public function testIsLockedByCorrectUser(): void
    {
        $this->lockService->acquire(1, 10, 'editing', 'Admin User');

        $this->assertTrue($this->lockService->isLockedBy(1, 10));
        $this->assertFalse($this->lockService->isLockedBy(1, 20));
    }

    public function testIsLockedByReturnsFalseWhenNotLocked(): void
    {
        $this->assertFalse($this->lockService->isLockedBy(999, 10));
    }

    public function testMultipleTemplatesLockedIndependently(): void
    {
        $this->lockService->acquire(1, 10, 'editing', 'User A');
        $this->lockService->acquire(2, 20, 'exporting', 'User B');

        $this->assertTrue($this->lockService->isLockedBy(1, 10));
        $this->assertTrue($this->lockService->isLockedBy(2, 20));
        $this->assertFalse($this->lockService->isLockedBy(1, 20));
        $this->assertFalse($this->lockService->isLockedBy(2, 10));
    }

    public function testLockInfoContainsTtl(): void
    {
        $this->lockService->acquire(1, 10, 'editing', 'Admin User');

        $lockInfo = $this->lockService->get(1);

        // TTL should be close to 1800 seconds (30 minutes)
        $this->assertLessThanOrEqual(1800, $lockInfo['ttl']);
        $this->assertGreaterThan(1700, $lockInfo['ttl']);
    }
}
