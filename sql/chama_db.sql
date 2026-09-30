-- ============================================================
-- CHAMA / SACCO FINANCIAL MANAGEMENT SYSTEM
-- Database Schema v1.0
-- Compatible with MySQL 5.7+ / MariaDB 10.3+
-- ============================================================

CREATE DATABASE IF NOT EXISTS chama_db 
    CHARACTER SET utf8mb4 
    COLLATE utf8mb4_unicode_ci;

USE chama_db;

-- ============================================================
-- TABLE: users
-- Stores all system users (admins and members)
-- ============================================================
CREATE TABLE IF NOT EXISTS users (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    full_name       VARCHAR(120)    NOT NULL,
    email           VARCHAR(180)    NOT NULL UNIQUE,
    phone           VARCHAR(20)     NOT NULL,
    national_id     VARCHAR(30)     DEFAULT NULL UNIQUE,
    password_hash   VARCHAR(255)    NOT NULL,
    role            ENUM('admin','member') NOT NULL DEFAULT 'member',
    status          ENUM('pending','active','suspended','rejected') NOT NULL DEFAULT 'pending',
    profile_photo   VARCHAR(255)    DEFAULT NULL,
    address         TEXT            DEFAULT NULL,
    occupation      VARCHAR(100)    DEFAULT NULL,
    next_of_kin     VARCHAR(120)    DEFAULT NULL,
    next_of_kin_phone VARCHAR(20)   DEFAULT NULL,
    membership_number VARCHAR(20)   UNIQUE DEFAULT NULL,   -- e.g. CHM-0001
    nickname        VARCHAR(60)     DEFAULT NULL,
    chama_position  VARCHAR(80)     DEFAULT NULL,          -- e.g. Chairman, Treasurer
    joined_date     DATE            DEFAULT NULL,          -- official approval date
    created_at      TIMESTAMP       DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP       DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    last_login      TIMESTAMP       NULL DEFAULT NULL,
    INDEX idx_role   (role),
    INDEX idx_status (status)
) ENGINE=InnoDB;

-- ============================================================
-- TABLE: contributions
-- Tracks monthly savings/contributions per member
-- ============================================================
CREATE TABLE IF NOT EXISTS contributions (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED    NOT NULL,
    amount          DECIMAL(12,2)   NOT NULL,
    payment_month   DATE            NOT NULL,              -- Store as first day: 2024-01-01
    payment_method  ENUM('cash','mpesa','bank') NOT NULL DEFAULT 'cash',
    reference_code  VARCHAR(60)     DEFAULT NULL,          -- MPESA code / bank ref
    status          ENUM('pending','confirmed','rejected') NOT NULL DEFAULT 'pending',
    confirmed_by    INT UNSIGNED    DEFAULT NULL,          -- admin user_id
    confirmed_at    TIMESTAMP       NULL DEFAULT NULL,
    notes           TEXT            DEFAULT NULL,
    recorded_at     TIMESTAMP       DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id)      REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (confirmed_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_user_month (user_id, payment_month),
    INDEX idx_status     (status)
) ENGINE=InnoDB;

-- ============================================================
-- TABLE: loans
-- Loan applications and their lifecycle
-- ============================================================
CREATE TABLE IF NOT EXISTS loans (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED    NOT NULL,
    loan_number     VARCHAR(30)     UNIQUE,                -- e.g. LN-2024-0001
    amount_requested DECIMAL(12,2)  NOT NULL,
    amount_approved  DECIMAL(12,2)  DEFAULT NULL,
    interest_rate   DECIMAL(5,2)    NOT NULL DEFAULT 10.00, -- % per month
    duration_months TINYINT UNSIGNED NOT NULL,             -- repayment period
    purpose         TEXT            NOT NULL,
    status          ENUM('pending','approved','rejected','disbursed','completed','defaulted') 
                    NOT NULL DEFAULT 'pending',
    reviewed_by     INT UNSIGNED    DEFAULT NULL,
    review_date     TIMESTAMP       NULL DEFAULT NULL,
    review_notes    TEXT            DEFAULT NULL,
    disbursed_at    TIMESTAMP       NULL DEFAULT NULL,
    disbursement_method ENUM('cash','mpesa','bank') DEFAULT NULL,
    disbursement_ref    VARCHAR(60) DEFAULT NULL,
    member_confirmed    TINYINT(1)  DEFAULT 0,
    member_confirmed_at TIMESTAMP   NULL DEFAULT NULL,
    total_repayable DECIMAL(12,2)   DEFAULT NULL,         -- principal + interest
    amount_repaid   DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
    balance         DECIMAL(12,2)   DEFAULT NULL,
    due_date        DATE            DEFAULT NULL,
    applied_at      TIMESTAMP       DEFAULT CURRENT_TIMESTAMP,
    completed_at    TIMESTAMP       NULL DEFAULT NULL,
    FOREIGN KEY (user_id)    REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_user_id (user_id),
    INDEX idx_status  (status)
) ENGINE=InnoDB;

-- ============================================================
-- TABLE: loan_payments
-- Individual repayment transactions against a loan
-- ============================================================
CREATE TABLE IF NOT EXISTS loan_payments (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    loan_id         INT UNSIGNED    NOT NULL,
    user_id         INT UNSIGNED    NOT NULL,
    amount          DECIMAL(12,2)   NOT NULL,
    payment_method  ENUM('cash','mpesa','bank') NOT NULL DEFAULT 'cash',
    reference_code  VARCHAR(60)     DEFAULT NULL,
    status          ENUM('pending','confirmed','rejected') NOT NULL DEFAULT 'pending',
    confirmed_by    INT UNSIGNED    DEFAULT NULL,
    confirmed_at    TIMESTAMP       NULL DEFAULT NULL,
    notes           TEXT            DEFAULT NULL,
    paid_at         TIMESTAMP       DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (loan_id)      REFERENCES loans(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id)      REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (confirmed_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_loan_id (loan_id),
    INDEX idx_user_id (user_id)
) ENGINE=InnoDB;

-- ============================================================
-- TABLE: expenses
-- Group operational expenses recorded by admin
-- ============================================================
CREATE TABLE IF NOT EXISTS expenses (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category        VARCHAR(80)     NOT NULL,             -- e.g. 'venue','stationery','utilities'
    description     TEXT            NOT NULL,
    amount          DECIMAL(12,2)   NOT NULL,
    expense_date    DATE            NOT NULL,
    recorded_by     INT UNSIGNED    NOT NULL,
    receipt_ref     VARCHAR(100)    DEFAULT NULL,
    created_at      TIMESTAMP       DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_date (expense_date)
) ENGINE=InnoDB;

-- ============================================================
-- TABLE: announcements
-- Admin-posted notices visible to members
-- ============================================================
CREATE TABLE IF NOT EXISTS announcements (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title           VARCHAR(200)    NOT NULL,
    body            TEXT            NOT NULL,
    posted_by       INT UNSIGNED    NOT NULL,
    priority        ENUM('normal','urgent') NOT NULL DEFAULT 'normal',
    is_active       TINYINT(1)      NOT NULL DEFAULT 1,
    created_at      TIMESTAMP       DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP       DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (posted_by) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_active (is_active)
) ENGINE=InnoDB;

-- ============================================================
-- TABLE: notifications
-- Per-user in-app notifications
-- ============================================================
CREATE TABLE IF NOT EXISTS notifications (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED    NOT NULL,
    title           VARCHAR(200)    NOT NULL,
    message         TEXT            NOT NULL,
    type            ENUM('info','success','warning','danger') NOT NULL DEFAULT 'info',
    is_read         TINYINT(1)      NOT NULL DEFAULT 0,
    link            VARCHAR(255)    DEFAULT NULL,
    created_at      TIMESTAMP       DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_read (user_id, is_read)
) ENGINE=InnoDB;

-- ============================================================
-- TABLE: activity_log
-- Audit trail for sensitive actions
-- ============================================================
CREATE TABLE IF NOT EXISTS activity_log (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED    DEFAULT NULL,
    action          VARCHAR(100)    NOT NULL,
    description     TEXT            DEFAULT NULL,
    ip_address      VARCHAR(45)     DEFAULT NULL,
    created_at      TIMESTAMP       DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_user_id  (user_id),
    INDEX idx_action   (action),
    INDEX idx_created  (created_at)
) ENGINE=InnoDB;

-- ============================================================
-- TABLE: system_settings
-- Key-value store for configurable group settings
-- ============================================================
CREATE TABLE IF NOT EXISTS system_settings (
    setting_key     VARCHAR(80)     NOT NULL PRIMARY KEY,
    setting_value   TEXT            NOT NULL,
    description     VARCHAR(255)    DEFAULT NULL,
    updated_at      TIMESTAMP       DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ============================================================
-- SEED: Default system settings
-- ============================================================
INSERT INTO system_settings (setting_key, setting_value, description) VALUES
('group_name',          'My Chama Group',       'Official group name'),
('monthly_contribution','10300.00',             'Required monthly contribution (KES)'),
('loan_interest_rate',  '10.00',                'Default loan interest rate (% per month)'),
('max_loan_multiplier', '3',                    'Max loan = multiplier × member savings'),
('currency',            'KES',                  'Currency symbol'),
('membership_prefix',   'CHM',                  'Prefix for membership numbers'),
('loan_prefix',         'LN',                   'Prefix for loan numbers'),
('admin_email',         'admin@chama.local',    'Admin notification email');

-- ============================================================
-- SEED: Default admin account
-- Password: Admin@1234 (change immediately after first login)
-- Hash generated with: password_hash('Admin@1234', PASSWORD_BCRYPT, [cost=>12])
-- ============================================================
INSERT INTO users (
    full_name, email, phone, national_id, password_hash,
    role, status, membership_number, joined_date
) VALUES (
    'System Administrator',
    'admin@chama.local',
    '0700000000',
    'ADMIN000001',
    '$2b$12$HqDX8ElNlnfcXrEyzT8Jc.c/IFS8AF3TzMSBq9xFA99JB9yG/wcMm', -- Admin@1234
    'admin',
    'active',
    'CHM-ADMIN',
    CURDATE()
);

-- ── M-Pesa Transactions Table ────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS mpesa_transactions (
    id                  INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    user_id             INT UNSIGNED    NOT NULL,
    contribution_id     INT UNSIGNED    DEFAULT NULL,
    phone               VARCHAR(20)     NOT NULL,
    amount              DECIMAL(12,2)   NOT NULL,
    payment_month       DATE            NOT NULL,
    event_id            INT UNSIGNED    DEFAULT NULL,
    loan_id             INT UNSIGNED    DEFAULT NULL,
    checkout_request_id VARCHAR(100)    UNIQUE,
    merchant_request_id VARCHAR(100),
    mpesa_receipt       VARCHAR(50)     DEFAULT NULL,
    result_code         INT             DEFAULT NULL,
    result_desc         VARCHAR(255)    DEFAULT NULL,
    status              ENUM('pending','completed','failed','cancelled') DEFAULT 'pending',
    initiated_at        TIMESTAMP       DEFAULT CURRENT_TIMESTAMP,
    completed_at        TIMESTAMP       NULL DEFAULT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_checkout  (checkout_request_id),
    INDEX idx_user      (user_id),
    INDEX idx_status    (status)
) ENGINE=InnoDB;

-- ── Password Reset Tokens ─────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS password_resets (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email      VARCHAR(180) NOT NULL,
    token      VARCHAR(100) NOT NULL UNIQUE,
    expires_at DATETIME     NOT NULL,
    used       TINYINT(1)   DEFAULT 0,
    created_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_token (token),
    INDEX idx_email (email)
) ENGINE=InnoDB;

-- ── Group Chat / Announcement Replies ────────────────────────────────────────
CREATE TABLE IF NOT EXISTS announcement_replies (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    announcement_id INT UNSIGNED NOT NULL,
    user_id         INT UNSIGNED NOT NULL,
    message         TEXT         NOT NULL,
    created_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (announcement_id) REFERENCES announcements(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id)         REFERENCES users(id)         ON DELETE CASCADE,
    INDEX idx_ann (announcement_id)
) ENGINE=InnoDB;

-- ── AI Insights Cache ─────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS ai_insights (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    type       VARCHAR(60)  NOT NULL,
    payload    JSON         NOT NULL,
    generated_at TIMESTAMP  DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_type (type)
) ENGINE=InnoDB;

-- ── Member Wallet ─────────────────────────────────────────────────────────────
-- Tracks running balance per member. Negative = owes chama. Positive = chama owes member.
CREATE TABLE IF NOT EXISTS member_wallet (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL UNIQUE,
    balance     DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ── Wallet Ledger (every transaction that affects balance) ────────────────────
CREATE TABLE IF NOT EXISTS wallet_ledger (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL,
    type        ENUM('credit','debit') NOT NULL,
    amount      DECIMAL(12,2) NOT NULL,
    balance_after DECIMAL(12,2) NOT NULL,
    description VARCHAR(255) NOT NULL,
    ref_type    VARCHAR(40)  DEFAULT NULL,  -- 'contribution', 'monthly_charge', 'adjustment'
    ref_id      INT UNSIGNED DEFAULT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user (user_id),
    INDEX idx_date (created_at)
) ENGINE=InnoDB;

-- ============================================================
-- TABLE: member_fines
-- Tracks fines per member (absentee, late, AGM, custom)
-- ============================================================
CREATE TABLE IF NOT EXISTS member_fines (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL,
    fine_type   VARCHAR(60)  NOT NULL,   -- 'absentee','late_contribution','agm','custom'
    amount      DECIMAL(10,2) NOT NULL,
    month       DATE         NOT NULL,   -- which month this fine applies to
    description VARCHAR(200) DEFAULT NULL,
    status      ENUM('pending','paid','waived') NOT NULL DEFAULT 'pending',
    created_by  INT UNSIGNED DEFAULT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id)   REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_user  (user_id),
    INDEX idx_month (month)
) ENGINE=InnoDB;

-- ============================================================
-- TABLE: events  (special/one-off contributions)
-- ============================================================
CREATE TABLE IF NOT EXISTS events (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title       VARCHAR(120) NOT NULL,
    description TEXT         DEFAULT NULL,
    event_date  DATE         NOT NULL,
    target_amount DECIMAL(12,2) DEFAULT NULL,   -- optional target per member
    is_mandatory TINYINT(1)  NOT NULL DEFAULT 0,
    status      ENUM('active','closed','cancelled') NOT NULL DEFAULT 'active',
    created_by  INT UNSIGNED DEFAULT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ============================================================
-- TABLE: event_contributions
-- ============================================================
CREATE TABLE IF NOT EXISTS event_contributions (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_id    INT UNSIGNED NOT NULL,
    user_id     INT UNSIGNED NOT NULL,
    amount      DECIMAL(12,2) NOT NULL,
    payment_method ENUM('cash','mpesa','bank') NOT NULL DEFAULT 'cash',
    reference_code VARCHAR(60) DEFAULT NULL,
    notes       TEXT          DEFAULT NULL,
    status      ENUM('pending','confirmed','rejected') NOT NULL DEFAULT 'pending',
    confirmed_by INT UNSIGNED DEFAULT NULL,
    confirmed_at TIMESTAMP    NULL DEFAULT NULL,
    recorded_at TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (event_id)    REFERENCES events(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id)     REFERENCES users(id)  ON DELETE CASCADE,
    FOREIGN KEY (confirmed_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_event  (event_id),
    INDEX idx_user   (user_id)
) ENGINE=InnoDB;

-- ============================================================
-- TABLE: dividends
-- ============================================================
CREATE TABLE IF NOT EXISTS dividends (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    year         YEAR NOT NULL,
    total_profit DECIMAL(14,2) NOT NULL DEFAULT 0,
    total_savings DECIMAL(14,2) NOT NULL DEFAULT 0,
    notes        TEXT DEFAULT NULL,
    status       ENUM('draft','distributed') NOT NULL DEFAULT 'draft',
    created_by   INT UNSIGNED DEFAULT NULL,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    distributed_at TIMESTAMP NULL DEFAULT NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY uq_year (year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- TABLE: dividend_shares
-- ============================================================
CREATE TABLE IF NOT EXISTS dividend_shares (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    dividend_id  INT UNSIGNED NOT NULL,
    user_id      INT UNSIGNED NOT NULL,
    avg_balance  DECIMAL(14,2) NOT NULL DEFAULT 0,
    share_pct    DECIMAL(8,4)  NOT NULL DEFAULT 0,
    share_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
    notified     TINYINT(1) DEFAULT 0,
    FOREIGN KEY (dividend_id) REFERENCES dividends(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id)     REFERENCES users(id)     ON DELETE CASCADE,
    UNIQUE KEY uq_div_user (dividend_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- TABLE: meeting_minutes
-- ============================================================
CREATE TABLE IF NOT EXISTS meeting_minutes (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    meeting_date     DATE NOT NULL,
    meeting_type     VARCHAR(60) NOT NULL DEFAULT 'regular',
    venue            VARCHAR(150) DEFAULT NULL,
    agenda           TEXT DEFAULT NULL,
    minutes          TEXT DEFAULT NULL,
    attendees_count  INT DEFAULT 0,
    attendee_ids     TEXT DEFAULT NULL,
    next_meeting_date DATE DEFAULT NULL,
    recorded_by      INT UNSIGNED DEFAULT NULL,
    created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- TABLE: mpesa_verifications
-- ============================================================
CREATE TABLE IF NOT EXISTS mpesa_verifications (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    receipt_code VARCHAR(20) NOT NULL,
    context      VARCHAR(30) DEFAULT 'contribution',
    context_id   INT UNSIGNED DEFAULT NULL,
    status       ENUM('pending','verified','failed') DEFAULT 'pending',
    result_data  TEXT DEFAULT NULL,
    checked_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_receipt (receipt_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- TABLE: mpesa_verify_results (Transaction Status API callback)
-- ============================================================
CREATE TABLE IF NOT EXISTS mpesa_verify_results (
    id                          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    originator_conversation_id  VARCHAR(100) DEFAULT NULL,
    transaction_id              VARCHAR(50)  DEFAULT NULL,
    result_code                 INT NOT NULL DEFAULT -1,
    amount                      DECIMAL(10,2) DEFAULT NULL,
    trans_date                  VARCHAR(20) DEFAULT NULL,
    raw_response                TEXT DEFAULT NULL,
    created_at                  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at                  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_orig (originator_conversation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- TABLE: sms_log
-- ============================================================
CREATE TABLE IF NOT EXISTS sms_log (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED DEFAULT NULL,
    phone      VARCHAR(20) NOT NULL,
    message    TEXT NOT NULL,
    type       VARCHAR(50) DEFAULT 'general',
    status     VARCHAR(20) DEFAULT 'sent',
    response   TEXT DEFAULT NULL,
    sent_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_type (type),
    INDEX idx_sent_at (sent_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- ALTER: Add email_verified columns to users (if not exists)
-- ============================================================
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS email_verified       TINYINT(1)  NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS verify_token         VARCHAR(64) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS verify_token_expires DATETIME    DEFAULT NULL;

-- Set all existing active members as verified
UPDATE users SET email_verified = 1 WHERE status = 'active' AND email_verified = 0;

-- ============================================================
-- ALTER: Add missing `type` column to sms_log (for existing databases)
-- This fixes the "Unknown column 'type'" PDOException in sms_reminders.php
-- ============================================================
ALTER TABLE sms_log
    ADD COLUMN IF NOT EXISTS type VARCHAR(50) DEFAULT 'general' AFTER message;
