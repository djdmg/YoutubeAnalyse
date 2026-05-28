<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260528100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add messenger_log table for Messenger monitoring';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<SQL
            CREATE TABLE messenger_log (
                id INT AUTO_INCREMENT NOT NULL,
                message_class VARCHAR(255) NOT NULL,
                payload JSON NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'pending',
                error LONGTEXT DEFAULT NULL,
                created_at DATETIME(6) NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                finished_at DATETIME(6) DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                duration_ms INT DEFAULT NULL,
                retry_count INT DEFAULT NULL,
                INDEX idx_msg_class (message_class),
                INDEX idx_msg_status (status),
                INDEX idx_msg_date (created_at),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE messenger_log');
    }
}
