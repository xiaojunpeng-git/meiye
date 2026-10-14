-- upgrade_key: 20261014-002-card-combination-groups
-- 旧卡的分组号恒为 0；新卡只有在完整规则签发后才有分组状态。
SET NAMES utf8mb4;
SET @ddl := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='eb_store_card_related' AND COLUMN_NAME='combination_group_no')=0,
  'ALTER TABLE eb_store_card_related ADD COLUMN combination_group_no smallint unsigned NOT NULL DEFAULT 0 AFTER labor_fee_cents', 'SELECT 1');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;
SET @ddl := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='eb_store_card_related' AND COLUMN_NAME='combination_group_name')=0,
  'ALTER TABLE eb_store_card_related ADD COLUMN combination_group_name varchar(64) NOT NULL DEFAULT '''' AFTER combination_group_no', 'SELECT 1');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;
SET @ddl := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='eb_store_card_related' AND COLUMN_NAME='combination_group_rule')=0,
  'ALTER TABLE eb_store_card_related ADD COLUMN combination_group_rule varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '''' AFTER combination_group_name', 'SELECT 1');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;
SET @ddl := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='eb_store_card_related' AND COLUMN_NAME='combination_choice_limit')=0,
  'ALTER TABLE eb_store_card_related ADD COLUMN combination_choice_limit int unsigned NOT NULL DEFAULT 0 AFTER combination_group_rule', 'SELECT 1');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;
SET @ddl := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='eb_store_card_related' AND COLUMN_NAME='combination_shared_times')=0,
  'ALTER TABLE eb_store_card_related ADD COLUMN combination_shared_times int unsigned NOT NULL DEFAULT 0 AFTER combination_choice_limit', 'SELECT 1');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;
SET @ddl := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='eb_cashier_v3_card_rule_component' AND COLUMN_NAME='combination_group_no')=0,
  'ALTER TABLE eb_cashier_v3_card_rule_component ADD COLUMN combination_group_no smallint unsigned NOT NULL DEFAULT 0 AFTER relation_id', 'SELECT 1');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

-- 高频读写的组次数与已选种数使用结构化列，不能藏在可变 JSON 中。
CREATE TABLE IF NOT EXISTS eb_cashier_v3_card_rule_group_state (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  tenant_id varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  rule_state_id bigint unsigned NOT NULL,
  card_holder_id bigint unsigned NOT NULL,
  group_no smallint unsigned NOT NULL,
  group_name_snapshot varchar(64) NOT NULL,
  group_rule_type varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  choice_limit int unsigned NOT NULL DEFAULT 0,
  selected_kind_count int unsigned NOT NULL DEFAULT 0,
  shared_total_times int unsigned NOT NULL DEFAULT 0,
  shared_remaining_times int unsigned NOT NULL DEFAULT 0,
  immutable_fingerprint char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  definition_snapshot_json text NOT NULL,
  state_version bigint unsigned NOT NULL DEFAULT 1,
  status varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  occurred_at bigint unsigned NOT NULL,
  recorded_at bigint unsigned NOT NULL,
  add_time bigint unsigned NOT NULL,
  update_time bigint unsigned NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_rule_group (rule_state_id,group_no),
  KEY idx_holder_group (tenant_id,card_holder_id,group_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='issued combination card group rights';
