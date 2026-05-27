-- PENDING_MIGRATIONS.sql
-- Run these manually on your Synology NAS MySQL database.
-- Generated for feat/advanced-analytics (2026-05-27)

-- Feature 2: Revenue/RPM estimation — add estimated_rpm to users
ALTER TABLE users
    ADD COLUMN `estimated_rpm` DOUBLE NOT NULL DEFAULT 2.0;

-- Feature 6: Multi-channel switcher — add active_channel_id to users
ALTER TABLE users
    ADD COLUMN `active_channel_id` VARCHAR(255) NULL DEFAULT NULL;

-- Feature 5: Goals & Milestones — create goals table
CREATE TABLE goals (
    id             INT AUTO_INCREMENT NOT NULL,
    user_id        INT NOT NULL,
    type           VARCHAR(50) NOT NULL DEFAULT 'views',
    target_value   BIGINT NOT NULL DEFAULT 0,
    current_value  BIGINT NOT NULL DEFAULT 0,
    label          VARCHAR(255) NOT NULL DEFAULT '',
    deadline       DATETIME NULL DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
    created_at     DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
    is_achieved    TINYINT(1) NOT NULL DEFAULT 0,
    achieved_at    DATETIME NULL DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
    PRIMARY KEY (id),
    INDEX IDX_5899FB57A76ED395 (user_id),
    CONSTRAINT FK_5899FB57A76ED395 FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE CASCADE
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;

-- Feature 3 & 4: New AiReportType values (thumbnail_analysis, description_optimization)
-- No schema change needed — AiReport.type is a VARCHAR(50) enum stored as string.
-- The new enum cases are already handled by PHP code.
