<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260530113000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Migrate legacy per-user SMTP settings to global app settings';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("
            INSERT INTO app_settings (setting_key, value)
            SELECT 'smtp_host', smtp_host FROM users
            WHERE smtp_host IS NOT NULL AND smtp_host <> ''
              AND smtp_user IS NOT NULL AND smtp_user <> ''
              AND smtp_password IS NOT NULL AND smtp_password <> ''
            ORDER BY id ASC
            LIMIT 1
            ON DUPLICATE KEY UPDATE value = app_settings.value
        ");
        $this->addSql("
            INSERT INTO app_settings (setting_key, value)
            SELECT 'smtp_port', COALESCE(CAST(smtp_port AS CHAR), '587') FROM users
            WHERE smtp_host IS NOT NULL AND smtp_host <> ''
              AND smtp_user IS NOT NULL AND smtp_user <> ''
              AND smtp_password IS NOT NULL AND smtp_password <> ''
            ORDER BY id ASC
            LIMIT 1
            ON DUPLICATE KEY UPDATE value = app_settings.value
        ");
        $this->addSql("
            INSERT INTO app_settings (setting_key, value)
            SELECT 'smtp_encryption', COALESCE(NULLIF(smtp_encryption, ''), 'starttls') FROM users
            WHERE smtp_host IS NOT NULL AND smtp_host <> ''
              AND smtp_user IS NOT NULL AND smtp_user <> ''
              AND smtp_password IS NOT NULL AND smtp_password <> ''
            ORDER BY id ASC
            LIMIT 1
            ON DUPLICATE KEY UPDATE value = app_settings.value
        ");
        $this->addSql("
            INSERT INTO app_settings (setting_key, value)
            SELECT 'smtp_user', smtp_user FROM users
            WHERE smtp_host IS NOT NULL AND smtp_host <> ''
              AND smtp_user IS NOT NULL AND smtp_user <> ''
              AND smtp_password IS NOT NULL AND smtp_password <> ''
            ORDER BY id ASC
            LIMIT 1
            ON DUPLICATE KEY UPDATE value = app_settings.value
        ");
        $this->addSql("
            INSERT INTO app_settings (setting_key, value)
            SELECT 'smtp_password', smtp_password FROM users
            WHERE smtp_host IS NOT NULL AND smtp_host <> ''
              AND smtp_user IS NOT NULL AND smtp_user <> ''
              AND smtp_password IS NOT NULL AND smtp_password <> ''
            ORDER BY id ASC
            LIMIT 1
            ON DUPLICATE KEY UPDATE value = app_settings.value
        ");
        $this->addSql("
            INSERT INTO app_settings (setting_key, value)
            VALUES ('smtp_from_name', 'YouTube Analyse')
            ON DUPLICATE KEY UPDATE value = app_settings.value
        ");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM app_settings WHERE setting_key IN ('smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_user', 'smtp_password', 'smtp_from_name', 'smtp_from_email')");
    }
}
