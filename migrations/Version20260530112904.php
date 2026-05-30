<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260530112904 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop per-user SMTP fields moved to global admin settings';
    }

    public function up(Schema $schema): void
    {
        // IF EXISTS guards against servers where the column was never added
        $this->addSql('ALTER TABLE users
            DROP COLUMN IF EXISTS smtp_host,
            DROP COLUMN IF EXISTS smtp_port,
            DROP COLUMN IF EXISTS smtp_encryption,
            DROP COLUMN IF EXISTS smtp_user,
            DROP COLUMN IF EXISTS smtp_password
        ');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users
            ADD smtp_host VARCHAR(255) DEFAULT NULL,
            ADD smtp_port INT DEFAULT NULL,
            ADD smtp_encryption VARCHAR(10) DEFAULT NULL,
            ADD smtp_user VARCHAR(255) DEFAULT NULL,
            ADD smtp_password VARCHAR(255) DEFAULT NULL
        ');
    }
}
