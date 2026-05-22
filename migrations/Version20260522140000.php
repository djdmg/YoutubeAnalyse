<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260522140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add SMTP notification settings to users table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users
            ADD notif_email VARCHAR(255) DEFAULT NULL,
            ADD smtp_host VARCHAR(255) DEFAULT NULL,
            ADD smtp_port INT DEFAULT NULL,
            ADD smtp_encryption VARCHAR(10) DEFAULT NULL,
            ADD smtp_user VARCHAR(255) DEFAULT NULL,
            ADD smtp_password VARCHAR(255) DEFAULT NULL
        ');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users
            DROP notif_email,
            DROP smtp_host,
            DROP smtp_port,
            DROP smtp_encryption,
            DROP smtp_user,
            DROP smtp_password
        ');
    }
}
