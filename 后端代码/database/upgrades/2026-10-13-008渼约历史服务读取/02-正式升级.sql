-- 旧渼约已完成工作单的只读展示快照。它不是 V3 服务、核销或业绩事实，
-- 不参与扣次、报表、工资、会员最近到店或任何经营统计。
CREATE TABLE IF NOT EXISTS `eb_mobile_customer_legacy_service_record` (
    id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
    source_system varchar(32) NOT NULL,
    source_work_id int unsigned NOT NULL,
    source_member_id int unsigned NOT NULL,
    member_id int unsigned NOT NULL,
    source_store_id int unsigned NOT NULL,
    store_name_snapshot varchar(120) NOT NULL DEFAULT '',
    project_name_snapshot varchar(255) NOT NULL,
    source_card_name_snapshot varchar(255) NOT NULL DEFAULT '',
    quantity int unsigned NOT NULL,
    craftsman_name_snapshot varchar(120) NOT NULL DEFAULT '',
    business_date date NOT NULL,
    completed_at int unsigned NOT NULL,
    source_row_sha256 char(64) NOT NULL,
    created_at datetime NOT NULL,
    UNIQUE KEY uk_source_work (source_system, source_work_id),
    KEY idx_member_completed (member_id, completed_at, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 固定源快照与导入计数用于拒绝漂移、重复导入和回滚核对。
CREATE TABLE IF NOT EXISTS `mig_morestore_20261013_service_history_batch` (
    batch_key varchar(64) NOT NULL PRIMARY KEY,
    source_sha256 char(64) NOT NULL,
    source_rows int unsigned NOT NULL,
    target_rows int unsigned NOT NULL,
    source_cutoff_at int unsigned NOT NULL,
    created_at datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
