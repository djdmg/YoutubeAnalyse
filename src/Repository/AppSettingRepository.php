<?php

namespace App\Repository;

use App\Entity\AppSetting;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * @extends ServiceEntityRepository<AppSetting>
 */
class AppSettingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry, private readonly CacheInterface $cache)
    {
        parent::__construct($registry, AppSetting::class);
    }

    public function get(string $key): ?string
    {
        return $this->cache->get('app_setting_' . $key, function (ItemInterface $item) use ($key) {
            $item->expiresAfter(86400); // 24h
            return $this->find($key)?->getValue();
        });
    }

    public function set(string $key, ?string $value): void
    {
        $setting = $this->find($key) ?? new AppSetting($key);
        $setting->setValue($value);
        $this->getEntityManager()->persist($setting);
        $this->getEntityManager()->flush();
        $this->cache->delete('app_setting_' . $key);
    }
}
