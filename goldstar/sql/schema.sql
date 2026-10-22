-- GoldStarBot schema (MySQL / MariaDB, utf8mb4)
-- Run once in phpMyAdmin (SiteGround > Site Tools > MySQL > phpMyAdmin)
-- against your GoldStarBot database.

SET NAMES utf8mb4;

-- Append-only activity log shown to the superadmin.
CREATE TABLE IF NOT EXISTS gsb_activity (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    type       VARCHAR(24)  NOT NULL,
    message    VARCHAR(500) NOT NULL,
    team_id    VARCHAR(32)      NULL,
    actor      VARCHAR(191)     NULL,
    created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- User accounts + roles. superadmin = operator (all workspaces); customer =
-- a paying account that owns one workspace.
CREATE TABLE IF NOT EXISTS gsb_accounts (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    email          VARCHAR(191) NOT NULL,
    password_hash  VARCHAR(255)     NULL,
    role           VARCHAR(20)  NOT NULL DEFAULT 'customer',   -- superadmin | customer
    team_id        VARCHAR(32)      NULL,                      -- workspace owned (null until linked)
    workspace_name VARCHAR(191)     NULL,
    status         VARCHAR(20)  NOT NULL DEFAULT 'active',     -- active | pending_password
    setup_token    VARCHAR(64)      NULL,
    created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_email (email),
    KEY idx_token (setup_token),
    KEY idx_team (team_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Free-trial checkout leads (no card data is ever stored here).
CREATE TABLE IF NOT EXISTS gsb_leads (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    plan       VARCHAR(32)      NULL,
    workspace  VARCHAR(191)     NULL,
    first_name VARCHAR(120)     NULL,
    last_name  VARCHAR(120)     NULL,
    email      VARCHAR(191)     NULL,
    slack_url  VARCHAR(255)     NULL,
    created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Key/value store for Slack settings managed from the admin Settings page
-- (signing_secret, bot_token, post_target, recognition_channel). Overrides the
-- defaults in config.local.php.
CREATE TABLE IF NOT EXISTS gsb_settings (
    skey       VARCHAR(64) NOT NULL,
    svalue     TEXT            NULL,
    updated_at TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (skey)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Workspace (Slack team) allowlist. New teams are 'pending' and cannot give
-- stars until an admin approves them via /admin.
CREATE TABLE IF NOT EXISTS gsb_teams (
    team_id     VARCHAR(32)  NOT NULL,
    team_domain VARCHAR(191)     NULL,
    status      VARCHAR(16)  NOT NULL DEFAULT 'pending',  -- pending | active | blocked
    first_seen  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    approved_at TIMESTAMP        NULL,
    PRIMARY KEY (team_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cache of Slack users so leaderboards/stats can show names.
CREATE TABLE IF NOT EXISTS gsb_users (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    slack_user_id  VARCHAR(32)  NOT NULL,
    slack_username VARCHAR(191)     NULL,
    display_name   VARCHAR(191)     NULL,
    team_id        VARCHAR(32)      NULL,
    created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_slack_user (slack_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per star given.
CREATE TABLE IF NOT EXISTS gsb_stars (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    giver_slack_id    VARCHAR(32)  NOT NULL,
    receiver_slack_id VARCHAR(32)  NOT NULL,
    team_id           VARCHAR(32)  NOT NULL,
    channel_id        VARCHAR(32)      NULL,
    reason            TEXT             NULL,
    created_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_receiver (team_id, receiver_slack_id),
    KEY idx_giver (team_id, giver_slack_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Authoritative running total of stars received, per (team, receiver).
-- Incremented atomically (INSERT ... ON DUPLICATE KEY UPDATE) so concurrent
-- /star commands serialize on the single row and can't miscount milestones.
CREATE TABLE IF NOT EXISTS gsb_star_totals (
    team_id           VARCHAR(32)  NOT NULL,
    receiver_slack_id VARCHAR(32)  NOT NULL,
    total             INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (team_id, receiver_slack_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Achievements earned (one row per user per achievement code).
CREATE TABLE IF NOT EXISTS gsb_achievements (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    slack_user_id VARCHAR(32)  NOT NULL,
    team_id       VARCHAR(32)  NOT NULL,
    code          VARCHAR(64)  NOT NULL,
    threshold     INT UNSIGNED NOT NULL,
    awarded_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_user_team_code (slack_user_id, team_id, code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
