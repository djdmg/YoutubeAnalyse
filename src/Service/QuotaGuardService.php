<?php

namespace App\Service;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;

class QuotaGuardService
{
    private const CACHE_KEY    = 'youtube_api_quota_daily';
    private const DAILY_LIMIT  = 9000;

    public function __construct(
        private readonly CacheItemPoolInterface $cache,
        private readonly LoggerInterface $logger,
    ) {}

    public function getUsed(): int
    {
        $item = $this->cache->getItem(self::CACHE_KEY);
        return $item->isHit() ? (int) $item->get() : 0;
    }

    public function consume(int $units): void
    {
        $item  = $this->cache->getItem(self::CACHE_KEY);
        $used  = $item->isHit() ? (int) $item->get() : 0;
        $used += $units;

        $item->set($used);
        // Expire at end of day (Pacific Time — YouTube quota resets midnight PT)
        $midnight = new \DateTimeImmutable('tomorrow midnight', new \DateTimeZone('America/Los_Angeles'));
        $item->expiresAt(\DateTime::createFromImmutable($midnight));
        $this->cache->save($item);

        $this->logger->debug('YouTube quota consumed', ['units' => $units, 'total' => $used]);
    }

    public function hasQuota(int $required = 1): bool
    {
        return ($this->getUsed() + $required) <= self::DAILY_LIMIT;
    }

    /** Throws if quota would be exceeded */
    public function assertQuota(int $required = 1): void
    {
        if (!$this->hasQuota($required)) {
            $used = $this->getUsed();
            throw new \RuntimeException(
                sprintf('YouTube API quota exceeded: %d/%d units used today.', $used, self::DAILY_LIMIT)
            );
        }
    }

    public function reset(): void
    {
        $this->cache->deleteItem(self::CACHE_KEY);
        $this->logger->info('YouTube quota guard reset manually');
    }
}
