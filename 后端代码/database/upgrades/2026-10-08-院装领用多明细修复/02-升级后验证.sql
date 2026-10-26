SET NAMES utf8mb4;

SELECT `IS_NULLABLE`, `COLUMN_DEFAULT`
FROM `information_schema`.`COLUMNS`
WHERE `TABLE_SCHEMA` = DATABASE()
  AND `TABLE_NAME` = 'eb_inventory_salon_usage_line'
  AND `COLUMN_NAME` = 'source_usage_line_id';

SELECT `INDEX_NAME`, `SEQ_IN_INDEX`, `COLUMN_NAME`, `NON_UNIQUE`
FROM `information_schema`.`STATISTICS`
WHERE `TABLE_SCHEMA` = DATABASE()
  AND `TABLE_NAME` = 'eb_inventory_salon_usage_line'
  AND `INDEX_NAME` = 'uk_document_source_line'
ORDER BY `SEQ_IN_INDEX`;

SELECT COUNT(*) AS issue_lines_still_using_zero
FROM `eb_inventory_salon_usage_line` AS `line`
INNER JOIN `eb_inventory_salon_usage_document` AS `document`
  ON `document`.`id` = `line`.`document_id`
WHERE `document`.`operation_type` = 'ISSUE'
  AND `line`.`source_usage_line_id` = 0;

SELECT COUNT(*) AS return_lines_without_source
FROM `eb_inventory_salon_usage_line` AS `line`
INNER JOIN `eb_inventory_salon_usage_document` AS `document`
  ON `document`.`id` = `line`.`document_id`
WHERE `document`.`operation_type` = 'RETURN'
  AND (`line`.`source_usage_line_id` IS NULL OR `line`.`source_usage_line_id` = 0);
