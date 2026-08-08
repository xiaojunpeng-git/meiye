-- upgrade_key: 20260803-003-inventory-v3-request-revision
SET NAMES utf8mb4;

SELECT COUNT(*) AS request_revision_table_exists
FROM information_schema.tables
WHERE table_schema = DATABASE() AND table_name = 'eb_inventory_stock_request_revision';

SELECT COUNT(*) AS request_revision_log_exists
FROM `eb_database_upgrade_log`
WHERE `upgrade_key` = '20260803-003-inventory-v3-request-revision';
