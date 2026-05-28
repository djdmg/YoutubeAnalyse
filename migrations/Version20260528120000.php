<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260528120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create thumbnail_changes table for tracking thumbnail history';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE thumbnail_changes (
            id INT AUTO_INCREMENT NOT NULL,
            video_id INT NOT NULL,
            old_url VARCHAR(500) DEFAULT NULL,
            new_url VARCHAR(500) NOT NULL,
            applied_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            INDEX IDX_THUMB_CHANGE_VIDEO (video_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE thumbnail_changes ADD CONSTRAINT FK_THUMB_CHANGE_VIDEO
            FOREIGN KEY (video_id) REFERENCES videos (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE thumbnail_changes DROP FOREIGN KEY FK_THUMB_CHANGE_VIDEO');
        $this->addSql('DROP TABLE thumbnail_changes');
    }
}
