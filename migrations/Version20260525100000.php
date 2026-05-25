<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260525100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add video_search_terms table for YouTube search query tracking';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE video_search_terms (
            id INT AUTO_INCREMENT NOT NULL,
            video_id INT NOT NULL,
            query VARCHAR(500) NOT NULL,
            views INT NOT NULL DEFAULT 0,
            synced_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            INDEX idx_search_term_video (video_id),
            UNIQUE INDEX uniq_video_query (video_id, query),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE video_search_terms
            ADD CONSTRAINT fk_search_term_video
            FOREIGN KEY (video_id) REFERENCES videos (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE video_search_terms DROP FOREIGN KEY fk_search_term_video');
        $this->addSql('DROP TABLE video_search_terms');
    }
}
