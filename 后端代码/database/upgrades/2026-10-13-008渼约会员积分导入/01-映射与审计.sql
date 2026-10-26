-- 008 渼约会员导入的旧 UID→目标 UID 对照表。目标 UID 可重复，
-- 因为用户确认同手机号旧会员需要合并，而旧 UID 仍须逐条保留审计。
CREATE TABLE IF NOT EXISTS `mig_morestore_20261013_member_map` (
    source_uid int unsigned NOT NULL PRIMARY KEY,
    target_uid int unsigned NOT NULL,
    master_source_uid int unsigned NOT NULL,
    source_phone char(15) NOT NULL DEFAULT '',
    source_store_id int NOT NULL DEFAULT 0,
    target_store_id int NOT NULL DEFAULT 0,
    source_balance_yuan decimal(12,2) NOT NULL DEFAULT 0,
    source_integral int unsigned NOT NULL DEFAULT 0,
    source_nickname varchar(60) NOT NULL DEFAULT '',
    source_real_name varchar(25) NOT NULL DEFAULT '',
    source_profile_sha256 char(64) NOT NULL,
    is_master tinyint(1) NOT NULL,
    created_at datetime NOT NULL,
    KEY idx_target_uid(target_uid),
    KEY idx_source_phone(source_phone)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 旧积分账中指向已不存在或已删除会员的行不可误挂其他目标会员。
CREATE TABLE IF NOT EXISTS `mig_morestore_20261013_integral_bill_skip` (
    source_bill_id int unsigned NOT NULL PRIMARY KEY,
    source_uid int unsigned NOT NULL,
    reason varchar(64) NOT NULL,
    created_at datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 固定来源与执行脚本指纹用于导入批次追溯和重复执行拦截。
CREATE TABLE IF NOT EXISTS `mig_morestore_20261013_member_batch` (
    batch_key varchar(64) NOT NULL PRIMARY KEY,
    source_sha256 char(64) NOT NULL,
    script_sha256 char(64) NOT NULL,
    source_members int unsigned NOT NULL,
    target_members int unsigned NOT NULL,
    gift_balance_yuan decimal(14,2) NOT NULL,
    current_integral bigint unsigned NOT NULL,
    integral_bills int unsigned NOT NULL,
    created_at datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
