-- upgrade_key: 20261008-001-inventory-salon-multiple-issue-lines
SET NAMES utf8mb4;

-- 领用行没有上游领用明细，必须用 NULL 而非 0；MySQL 唯一键允许
-- 同一单据的多个 NULL，同时仍拒绝退回行重复引用同一来源明细。
ALTER TABLE `eb_inventory_salon_usage_line`
  MODIFY COLUMN `source_usage_line_id` bigint(20) unsigned NULL DEFAULT NULL;

-- 历史单行领用的 0 也转换为无来源语义；退回行的来源 ID 不改。
UPDATE `eb_inventory_salon_usage_line` AS `line`
INNER JOIN `eb_inventory_salon_usage_document` AS `document`
  ON `document`.`id` = `line`.`document_id`
SET `line`.`source_usage_line_id` = NULL
WHERE `document`.`operation_type` = 'ISSUE'
  AND `line`.`source_usage_line_id` = 0;

SELECT 'APPLY_OK' AS apply_result;
