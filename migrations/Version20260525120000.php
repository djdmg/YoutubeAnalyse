<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260525120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add video_meta_snapshots table for tracking title/description history';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE video_meta_snapshots (
            id INT AUTO_INCREMENT NOT NULL,
            video_id INT NOT NULL,
            title VARCHAR(500) NOT NULL,
            description LONGTEXT DEFAULT NULL,
            recorded_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            INDEX idx_snapshot_video_date (video_id, recorded_at),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE video_meta_snapshots
            ADD CONSTRAINT fk_snapshot_video
            FOREIGN KEY (video_id) REFERENCES videos (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE video_meta_snapshots DROP FOREIGN KEY fk_snapshot_video');
        $this->addSql('DROP TABLE video_meta_snapshots');
    }
}
