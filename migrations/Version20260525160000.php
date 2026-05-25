<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260525160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create app_settings table for admin-managed configuration';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE app_settings (
            `key` VARCHAR(100) NOT NULL,
            value LONGTEXT DEFAULT NULL,
            PRIMARY KEY (`key`)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DROP TABLE app_settings");
    }
}
