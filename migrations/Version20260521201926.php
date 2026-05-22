<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260521201926 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE ai_reports (id INT AUTO_INCREMENT NOT NULL, type VARCHAR(50) NOT NULL, payload JSON DEFAULT NULL, status VARCHAR(20) NOT NULL, generated_at DATETIME NOT NULL, model_version VARCHAR(100) DEFAULT NULL, tokens_input INT DEFAULT NULL, tokens_output INT DEFAULT NULL, duration_ms INT DEFAULT NULL, video_id INT DEFAULT NULL, INDEX IDX_CD1AA99629C1004E (video_id), INDEX idx_ai_report_video_type_date (video_id, type, generated_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE comments (id INT AUTO_INCREMENT NOT NULL, youtube_comment_id VARCHAR(100) NOT NULL, text LONGTEXT NOT NULL, published_at DATETIME NOT NULL, synced_at DATETIME NOT NULL, video_id INT NOT NULL, INDEX idx_comment_video (video_id), UNIQUE INDEX uniq_youtube_comment_id (youtube_comment_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE daily_metrics (id INT AUTO_INCREMENT NOT NULL, date DATE NOT NULL, views BIGINT NOT NULL, impressions BIGINT DEFAULT NULL, ctr DOUBLE PRECISION DEFAULT NULL, watch_time_minutes BIGINT DEFAULT NULL, avg_retention_percent DOUBLE PRECISION DEFAULT NULL, subscribers_gained INT DEFAULT NULL, traffic_sources JSON DEFAULT NULL, video_id INT NOT NULL, INDEX IDX_FD6B7B1329C1004E (video_id), UNIQUE INDEX uniq_video_date (video_id, date), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE retention_points (id INT AUTO_INCREMENT NOT NULL, date DATE NOT NULL, second INT NOT NULL, retention_percent DOUBLE PRECISION NOT NULL, video_id INT NOT NULL, INDEX IDX_82EF88A129C1004E (video_id), INDEX idx_retention_video_date (video_id, date), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE videos (id INT AUTO_INCREMENT NOT NULL, youtube_id VARCHAR(50) NOT NULL, title VARCHAR(500) NOT NULL, description LONGTEXT DEFAULT NULL, published_at DATETIME DEFAULT NULL, genre VARCHAR(100) DEFAULT NULL, duration_seconds INT DEFAULT NULL, thumbnail_url VARCHAR(500) DEFAULT NULL, channel_id VARCHAR(255) NOT NULL, user_id INT NOT NULL, INDEX IDX_29AA6432A76ED395 (user_id), UNIQUE INDEX uniq_youtube_id (youtube_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE ai_reports ADD CONSTRAINT FK_CD1AA99629C1004E FOREIGN KEY (video_id) REFERENCES videos (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE comments ADD CONSTRAINT FK_5F9E962A29C1004E FOREIGN KEY (video_id) REFERENCES videos (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE daily_metrics ADD CONSTRAINT FK_FD6B7B1329C1004E FOREIGN KEY (video_id) REFERENCES videos (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE retention_points ADD CONSTRAINT FK_82EF88A129C1004E FOREIGN KEY (video_id) REFERENCES videos (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE videos ADD CONSTRAINT FK_29AA6432A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE channel_stats CHANGE average_view_duration average_view_duration NUMERIC(5, 2) DEFAULT NULL');
        $this->addSql('ALTER TABLE google_tokens CHANGE channel_title channel_title VARCHAR(500) DEFAULT NULL');
        $this->addSql('ALTER TABLE users CHANGE avatar_url avatar_url VARCHAR(255) DEFAULT NULL, CHANGE roles roles JSON NOT NULL, CHANGE approved_at approved_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE video_stats CHANGE thumbnail_url thumbnail_url VARCHAR(500) DEFAULT NULL, CHANGE published_at published_at DATETIME DEFAULT NULL, CHANGE average_view_percentage average_view_percentage NUMERIC(5, 2) DEFAULT NULL, CHANGE duration duration VARCHAR(20) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE ai_reports DROP FOREIGN KEY FK_CD1AA99629C1004E');
        $this->addSql('ALTER TABLE comments DROP FOREIGN KEY FK_5F9E962A29C1004E');
        $this->addSql('ALTER TABLE daily_metrics DROP FOREIGN KEY FK_FD6B7B1329C1004E');
        $this->addSql('ALTER TABLE retention_points DROP FOREIGN KEY FK_82EF88A129C1004E');
        $this->addSql('ALTER TABLE videos DROP FOREIGN KEY FK_29AA6432A76ED395');
        $this->addSql('DROP TABLE ai_reports');
        $this->addSql('DROP TABLE comments');
        $this->addSql('DROP TABLE daily_metrics');
        $this->addSql('DROP TABLE retention_points');
        $this->addSql('DROP TABLE videos');
        $this->addSql('ALTER TABLE channel_stats CHANGE average_view_duration average_view_duration NUMERIC(5, 2) DEFAULT \'NULL\'');
        $this->addSql('ALTER TABLE google_tokens CHANGE channel_title channel_title VARCHAR(500) DEFAULT \'NULL\'');
        $this->addSql('ALTER TABLE users CHANGE avatar_url avatar_url VARCHAR(255) DEFAULT \'NULL\', CHANGE roles roles LONGTEXT NOT NULL COLLATE `utf8mb4_bin`, CHANGE approved_at approved_at DATETIME DEFAULT \'NULL\'');
        $this->addSql('ALTER TABLE video_stats CHANGE thumbnail_url thumbnail_url VARCHAR(500) DEFAULT \'NULL\', CHANGE published_at published_at DATETIME DEFAULT \'NULL\', CHANGE average_view_percentage average_view_percentage NUMERIC(5, 2) DEFAULT \'NULL\', CHANGE duration duration VARCHAR(20) DEFAULT \'NULL\'');
    }
}
