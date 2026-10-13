-- 旧渼约余额流水的只读历史快照。它不是 008 的余额账、V3 事实或可支用权益。
CREATE TABLE IF NOT EXISTS `eb_mobile_customer_legacy_balance_record` (
    id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
    source_system varchar(32) NOT NULL,
    source_money_id int unsigned NOT NULL,
    source_member_id int unsigned NOT NULL,
    member_id int unsigned NOT NULL,
    source_store_id int unsigned NOT NULL DEFAULT 0,
    store_name_snapshot varchar(120) NOT NULL DEFAULT '',
    source_type varchar(64) NOT NULL,
    title_snapshot varchar(64) NOT NULL,
    mark_snapshot varchar(512) NOT NULL DEFAULT '',
    source_link_id varchar(32) NOT NULL DEFAULT '',
    pm tinyint unsigned NOT NULL,
    amount_yuan decimal(12,2) NOT NULL,
    balance_after_yuan decimal(12,2) NOT NULL,
    occurred_at int unsigned NOT NULL,
    source_row_sha256 char(64) NOT NULL,
    created_at datetime NOT NULL,
    UNIQUE KEY uk_source_money (source_system,source_money_id),
    KEY idx_member_occurred (member_id,occurred_at,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 一次性来源截面与 008 目标行数、摘要保持可核对。
CREATE TABLE IF NOT EXISTS `mig_morestore_20261013_balance_history_batch` (
    batch_key varchar(64) NOT NULL PRIMARY KEY,
    source_sha256 char(64) NOT NULL,
    skipped_sha256 char(64) NOT NULL,
    source_rows int unsigned NOT NULL,
    imported_rows int unsigned NOT NULL,
    skipped_rows int unsigned NOT NULL,
    source_cutoff_at int unsigned NOT NULL,
    created_at datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 删除会员的孤儿流水不赋予任何 008 会员，只记录来源 ID 供审计。
CREATE TABLE IF NOT EXISTS `mig_morestore_20261013_balance_history_skip` (
    source_money_id int unsigned NOT NULL PRIMARY KEY,
    source_member_id int unsigned NOT NULL,
    reason varchar(64) NOT NULL,
    created_at datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
