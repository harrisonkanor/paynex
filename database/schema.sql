-- ============================================================
-- payNex — Full Database Schema (v3)
-- Run: mysql -u root -p -e "CREATE DATABASE paynex CHARACTER SET utf8mb4;"
--      mysql -u root -p paynex < schema.sql
-- ============================================================

-- --------------------------------------------------------
-- USERS
-- Only two roles: 'earner' | 'admin'   (no creator role)
-- vip_level:  NULL = no plan   |   1/2/3 = active VIP tier
-- btc_address / usdt_trc20_address: user's OWN payout wallets
-- referral_code: unique 8-char code generated at signup
-- referred_by: user_id of whoever referred this user
-- profile_photo: filename stored in /uploads/
-- suspension_note: message shown to the user when suspended
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id                  INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    name                VARCHAR(120)    NOT NULL,
    email               VARCHAR(190)    NOT NULL UNIQUE,
    password_hash       VARCHAR(255)    NOT NULL,
    role                ENUM('earner','admin') NOT NULL DEFAULT 'earner',
    wallet_balance      DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
    status              ENUM('active','suspended') NOT NULL DEFAULT 'active',
    suspension_note     VARCHAR(255)    NULL,
    vip_level           TINYINT         NULL,
    vip_expires_at      DATE            NULL,
    last_spin_at        DATETIME        NULL,
    btc_address         VARCHAR(100)    NULL,
    usdt_trc20_address  VARCHAR(100)    NULL,
    referral_code       VARCHAR(16)     NOT NULL UNIQUE,
    referred_by         INT UNSIGNED    NULL,
    profile_photo       VARCHAR(255)    NULL,
    email_verified      TINYINT(1)      NOT NULL DEFAULT 0,
    verification_code   VARCHAR(6)      NULL,
    total_referrals     INT UNSIGNED    NOT NULL DEFAULT 0,
    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_referred_by FOREIGN KEY (referred_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- VIP PLANS
-- Seeded with the three fixed tiers from the spec.
-- deposit_amount : cost to activate the tier
-- task_reward    : earned per individual task completion
-- tasks_per_day  : daily task quota
-- working_days   : Mon–Fri = 5
-- min_withdrawal : minimum payout threshold
-- referral_bonus : flat reward per referred user who joins this tier
-- referrals_needed: users must recruit this many to unlock next tier
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS vip_plans (
    level               TINYINT         PRIMARY KEY,
    deposit_amount      DECIMAL(10,2)   NOT NULL,
    task_reward         DECIMAL(10,2)   NOT NULL,
    tasks_per_day       TINYINT         NOT NULL DEFAULT 3,
    working_days        TINYINT         NOT NULL DEFAULT 5,
    min_withdrawal      DECIMAL(10,2)   NOT NULL,
    referral_bonus      DECIMAL(10,2)   NOT NULL,
    referrals_needed    TINYINT         NOT NULL DEFAULT 2,
    label               VARCHAR(20)     NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed the three VIP tiers (spec values)
INSERT INTO vip_plans
    (level, deposit_amount, task_reward, tasks_per_day, working_days, min_withdrawal, referral_bonus, referrals_needed, label)
VALUES
    (1,  5.00, 0.20, 3, 5,  5.00, 1.00, 2, 'VIP 1'),
    (2, 10.00, 0.50, 3, 5, 15.00, 1.00, 3, 'VIP 2'),
    (3, 20.00, 1.00, 3, 5, 25.00, 1.00, 2, 'VIP 3')
ON DUPLICATE KEY UPDATE level = level;

-- --------------------------------------------------------
-- DEPOSIT ORDERS
-- User sends crypto, then submits TX hash here.
-- Admin confirms → VIP plan is activated.
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS deposit_orders (
    id              INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED    NOT NULL,
    vip_level       TINYINT         NOT NULL,
    amount          DECIMAL(10,2)   NOT NULL,
    status          ENUM('pending','confirmed','rejected') NOT NULL DEFAULT 'pending',
    tx_hash         VARCHAR(100)    NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    confirmed_at    DATETIME        NULL,
    CONSTRAINT fk_dep_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- TASKS  (admin-created only)
-- type              : 'survey' | 'spin_wheel'
-- vip_level         : which VIP tier sees this task
-- time_limit_minutes: user must submit within this window after claiming
-- available_from/until: daily time window (e.g. 09:00–17:00)
-- ticket_price      : cost deducted from wallet to claim the task
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS tasks (
    id                  INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    admin_id            INT UNSIGNED    NOT NULL,
    title               VARCHAR(160)    NOT NULL,
    description         TEXT            NOT NULL,
    type                ENUM('survey','spin_wheel') NOT NULL DEFAULT 'survey',
    vip_level           TINYINT         NOT NULL DEFAULT 1,
    reward              DECIMAL(10,2)   NOT NULL,
    ticket_price        DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
    slots               INT UNSIGNED    NOT NULL DEFAULT 100,
    slots_filled        INT UNSIGNED    NOT NULL DEFAULT 0,
    time_limit_minutes  SMALLINT        NOT NULL DEFAULT 60,
    available_from      TIME            NOT NULL DEFAULT '00:00:00',
    available_until     TIME            NOT NULL DEFAULT '23:59:00',
    status              ENUM('open','closed') NOT NULL DEFAULT 'open',
    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_task_admin FOREIGN KEY (admin_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- TASK CLAIMS
-- Records the moment a user starts a task (ticket is purchased here).
-- If they don't submit within time_limit_minutes the claim expires.
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS task_claims (
    id          INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    task_id     INT UNSIGNED    NOT NULL,
    user_id     INT UNSIGNED    NOT NULL,
    ticket_paid DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
    claimed_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at  DATETIME        NOT NULL,
    UNIQUE KEY uniq_claim (task_id, user_id),
    CONSTRAINT fk_claim_task FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    CONSTRAINT fk_claim_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- TASK SUBMISSIONS
-- One row per user per task.
-- spin_result: stored JSON/text for spin-wheel tasks.
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS task_submissions (
    id              INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    task_id         INT UNSIGNED    NOT NULL,
    user_id         INT UNSIGNED    NOT NULL,
    proof_text      TEXT            NOT NULL,
    spin_result     VARCHAR(100)    NULL,
    screenshot_path VARCHAR(255)    NULL,
    status          ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    submitted_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_at     DATETIME        NULL,
    UNIQUE KEY uniq_sub (task_id, user_id),
    CONSTRAINT fk_sub_task FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    CONSTRAINT fk_sub_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- WALLET TRANSACTIONS  (full ledger)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS wallet_transactions (
    id          INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED    NOT NULL,
    type        ENUM('credit','debit') NOT NULL,
    amount      DECIMAL(10,2)   NOT NULL,
    description VARCHAR(255)    NOT NULL,
    created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_wallet_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- WITHDRAWALS
-- Amount is deducted at request time; rejected ones are auto-refunded.
-- admin_note: rejection reason shown to user.
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS withdrawals (
    id              INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED    NOT NULL,
    amount          DECIMAL(10,2)   NOT NULL,
    method          VARCHAR(50)     NOT NULL,
    account_details VARCHAR(255)    NOT NULL,
    status          ENUM('pending','approved','rejected','paid') NOT NULL DEFAULT 'pending',
    admin_note      VARCHAR(255)    NULL,
    requested_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_at    DATETIME        NULL,
    CONSTRAINT fk_wd_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- REFERRALS
-- Tracks who referred whom and whether the bonus was paid.
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS referrals (
    id              INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    referrer_id     INT UNSIGNED    NOT NULL,
    referred_id     INT UNSIGNED    NOT NULL UNIQUE,
    vip_level       TINYINT         NULL,
    bonus_paid      TINYINT(1)      NOT NULL DEFAULT 0,
    bonus_amount    DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_ref_referrer FOREIGN KEY (referrer_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_ref_referred FOREIGN KEY (referred_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- SITE SETTINGS  (admin key-value store)
-- Keys used by the app:
--   deposit_wallet_btc      → BTC deposit address shown to users
--   deposit_wallet_usdt     → USDT TRC-20 deposit address
--   chatwoot_website_token  → Chatwoot widget token
--   referral_commission_pct → % of referred user's earnings to credit referrer
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS site_settings (
    setting_key     VARCHAR(80)     PRIMARY KEY,
    setting_value   TEXT            NOT NULL,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO site_settings (setting_key, setting_value) VALUES
    ('deposit_wallet_btc',     'YOUR_BTC_DEPOSIT_ADDRESS_HERE'),
    ('deposit_wallet_usdt',    'YOUR_USDT_TRC20_DEPOSIT_ADDRESS_HERE'),
    ('chatwoot_website_token', ''),
    ('referral_commission_pct','5')
ON DUPLICATE KEY UPDATE setting_key = setting_key;

-- --------------------------------------------------------
-- ACTIVITY LOGS
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS activity_logs (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED    NULL,
    action      VARCHAR(255)    NOT NULL,
    ip_address  VARCHAR(45)     NOT NULL,
    created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_log_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- LOGIN ATTEMPTS  (brute-force rate limiting)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS login_attempts (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email           VARCHAR(190)    NOT NULL,
    ip_address      VARCHAR(45)     NOT NULL,
    attempted_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_email_time (email, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- DEFAULT ADMIN ACCOUNT
-- Email   : admin@paynex.local
-- Password: ChangeMe123!   ← CHANGE THIS IMMEDIATELY AFTER FIRST LOGIN
-- --------------------------------------------------------
INSERT INTO users (name, email, password_hash, role, status, referral_code)
VALUES (
    'Site Admin',
    'admin@paynex.local',
    '$2y$10$fmjNPVMfi.S7aVCeskAGou72XaO9wSEcnqrB7FqG7Fjgl9OPMe4A6',
    'admin',
    'active',
    'ADMIN0001'
) ON DUPLICATE KEY UPDATE email = email;

-- --------------------------------------------------------
-- REPORTED ISSUES
-- Users can report issues/problems from their dashboard.
-- Admins review and resolve them in the admin panel.
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS reported_issues (
    id              INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED    NOT NULL,
    subject         VARCHAR(200)    NOT NULL,
    description     TEXT            NOT NULL,
    status          ENUM('open','resolved') NOT NULL DEFAULT 'open',
    admin_notes     TEXT            NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resolved_at     DATETIME        NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
