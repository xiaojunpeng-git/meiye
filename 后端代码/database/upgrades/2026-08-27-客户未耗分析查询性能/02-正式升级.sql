-- upgrade_key: 20260827-001-customer-unconsumed-query-performance
-- MySQL 5.6 compatible and idempotent. Run the read-only check first.
SET NAMES utf8mb4;
SET @db := DATABASE();

SET @sql := IF((SELECT COUNT(*) FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA=@db AND TABLE_NAME='eb_user_card_holder'
                  AND INDEX_NAME='idx_unconsumed_store_state')=0,
  'ALTER TABLE `eb_user_card_holder` ADD INDEX `idx_unconsumed_store_state` (`store_id`,`is_del`,`oid`,`write_surplus_times`)',
  'SELECT ''idx_unconsumed_store_state already exists''');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA=@db AND TABLE_NAME='eb_store_order_cart_info'
                  AND INDEX_NAME='idx_unconsumed_oid_state')=0,
  'ALTER TABLE `eb_store_order_cart_info` ADD INDEX `idx_unconsumed_oid_state` (`oid`,`cart_type`,`product_type`,`is_writeoff`,`write_surplus_times`)',
  'SELECT ''idx_unconsumed_oid_state already exists''');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

INSERT INTO eb_database_upgrade_log
(`upgrade_key`,`title`,`task_file`,`sql_checksum`,`code_version`,`executed_at`,`executed_by`,`result_note`)
SELECT '20260827-001-customer-unconsumed-query-performance',
       '客户未耗分析查询性能升级',
       '2026-08-27-客户未耗分析查询性能/02-正式升级.sql',
       '', '', NOW(), 'codex-local',
       'added idempotent holder and cart entitlement query indexes'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM eb_database_upgrade_log
                  WHERE upgrade_key='20260827-001-customer-unconsumed-query-performance');
