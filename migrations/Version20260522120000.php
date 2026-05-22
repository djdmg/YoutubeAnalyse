<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260522120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add reporting_job and demographic_snapshot tables (YouTube Reporting API)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE reporting_job (
            id INT AUTO_INCREMENT NOT NULL,
            user_id INT NOT NULL,
            report_type_id VARCHAR(60) NOT NULL,
            google_job_id VARCHAR(100) NOT NULL,
            last_processed_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            UNIQUE INDEX uniq_reporting_job_user_type (user_id, report_type_id),
            INDEX IDX_reporting_job_user (user_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE demographic_snapshot (
            id INT AUTO_INCREMENT NOT NULL,
            video_id INT NOT NULL,
            date DATE NOT NULL COMMENT \'(DC2Type:date_immutable)\',
            age_group VARCHAR(20) NOT NULL,
            gender VARCHAR(20) NOT NULL,
            views_percentage DOUBLE PRECISION NOT NULL,
            UNIQUE INDEX uniq_demo_snapshot (video_id, date, age_group, gender),
            INDEX idx_demo_video_date (video_id, date),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE reporting_job ADD CONSTRAINT FK_reporting_job_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE demographic_snapshot ADD CONSTRAINT FK_demo_snapshot_video FOREIGN KEY (video_id) REFERENCES videos (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE demographic_snapshot DROP FOREIGN KEY FK_demo_snapshot_video');
        $this->addSql('ALTER TABLE reporting_job DROP FOREIGN KEY FK_reporting_job_user');
        $this->addSql('DROP TABLE demographic_snapshot');
        $this->addSql('DROP TABLE reporting_job');
    }
}
