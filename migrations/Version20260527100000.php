<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260527100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add estimated_rpm + active_channel_id to users; create goals table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ADD estimated_rpm DOUBLE PRECISION NOT NULL DEFAULT 2.0');
        $this->addSql('ALTER TABLE users ADD active_channel_id VARCHAR(255) DEFAULT NULL');
        $this->addSql('CREATE TABLE goals (
            id INT AUTO_INCREMENT NOT NULL,
            user_id INT NOT NULL,
            type VARCHAR(50) NOT NULL DEFAULT \'views\',
            target_value BIGINT NOT NULL DEFAULT 0,
            current_value BIGINT NOT NULL DEFAULT 0,
            label VARCHAR(255) NOT NULL DEFAULT \'\',
            deadline DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            is_achieved TINYINT(1) NOT NULL DEFAULT 0,
            achieved_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            INDEX IDX_goals_user (user_id),
            PRIMARY KEY (id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');
        $this->addSql('ALTER TABLE goals ADD CONSTRAINT FK_goals_users FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE goals DROP FOREIGN KEY FK_goals_users');
        $this->addSql('DROP TABLE goals');
        $this->addSql('ALTER TABLE users DROP estimated_rpm, DROP active_channel_id');
    }
}
