<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260525170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename app_settings.key to setting_key (key is a reserved word in MySQL)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE app_settings CHANGE `key` setting_key VARCHAR(100) NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE app_settings CHANGE setting_key `key` VARCHAR(100) NOT NULL");
    }
}
