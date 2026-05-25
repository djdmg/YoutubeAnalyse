<?php

namespace App\Repository;

use App\Entity\AppSetting;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AppSetting>
 */
class AppSettingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AppSetting::class);
    }

    public function get(string $key): ?string
    {
        return $this->find($key)?->getValue();
    }

    public function set(string $key, ?string $value): void
    {
        $setting = $this->find($key) ?? new AppSetting($key);
        $setting->setValue($value);
        $this->getEntityManager()->persist($setting);
        $this->getEntityManager()->flush();
    }
}
