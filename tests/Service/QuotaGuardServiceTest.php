<?php

namespace App\Tests\Service;

use App\Service\QuotaGuardService;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\NullLogger;

class QuotaGuardServiceTest extends TestCase
{
    private function makeCache(int $currentUsage = 0): CacheItemPoolInterface
    {
        $item = $this->createMock(CacheItemInterface::class);
        $item->method('isHit')->willReturn($currentUsage > 0);
        $item->method('get')->willReturn($currentUsage);
        $item->method('set')->willReturnSelf();
        $item->method('expiresAt')->willReturnSelf();

        $pool = $this->createMock(CacheItemPoolInterface::class);
        $pool->method('getItem')->willReturn($item);
        $pool->method('save')->willReturn(true);

        return $pool;
    }

    public function testHasQuotaReturnsTrueWhenUnderLimit(): void
    {
        $guard = new QuotaGuardService($this->makeCache(1000), new NullLogger());
        $this->assertTrue($guard->hasQuota(100));
    }

    public function testHasQuotaReturnsFalseWhenAtLimit(): void
    {
        $guard = new QuotaGuardService($this->makeCache(9000), new NullLogger());
        $this->assertFalse($guard->hasQuota(1));
    }

    public function testHasQuotaReturnsFalseWhenOverLimit(): void
    {
        $guard = new QuotaGuardService($this->makeCache(8999), new NullLogger());
        $this->assertFalse($guard->hasQuota(2));
    }

    public function testHasQuotaReturnsTrueAtExactBoundary(): void
    {
        $guard = new QuotaGuardService($this->makeCache(8900), new NullLogger());
        $this->assertTrue($guard->hasQuota(100));
    }

    public function testAssertQuotaThrowsWhenExceeded(): void
    {
        $guard = new QuotaGuardService($this->makeCache(9000), new NullLogger());
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/quota exceeded/i');
        $guard->assertQuota(1);
    }

    public function testAssertQuotaDoesNotThrowWhenUnder(): void
    {
        $guard = new QuotaGuardService($this->makeCache(100), new NullLogger());
        $guard->assertQuota(100); // should not throw
        $this->assertTrue(true);
    }

    public function testGetUsedReturnsZeroOnCacheMiss(): void
    {
        $item = $this->createMock(CacheItemInterface::class);
        $item->method('isHit')->willReturn(false);

        $pool = $this->createMock(CacheItemPoolInterface::class);
        $pool->method('getItem')->willReturn($item);

        $guard = new QuotaGuardService($pool, new NullLogger());
        $this->assertSame(0, $guard->getUsed());
    }
}
