-- upgrade_key: 20260731-005-cashier-v3-custom-card-configuration
-- Read-only precheck; MySQL 5.6.51 compatible.
SET NAMES utf8mb4;
SET @ccc_db := DATABASE();

SELECT COUNT(*) INTO @ccc_dependencies
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@ccc_db AND TABLE_NAME IN (
  'eb_cashier_v3_workspace_draft','eb_cashier_v3_workspace_line',
  'eb_cashier_v3_card_purchase_receipt','eb_cashier_v3_card_state'
) AND ENGINE='InnoDB';

SELECT @ccc_dependencies AS required_dependency_table_count;
SET @ccc_abort := IF(@ccc_dependencies=4,
  'SELECT ''PRECHECK_OK'' AS precheck_result',
  'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT=''cashier custom card configuration precheck failed''');
PREPARE ccc_stmt FROM @ccc_abort; EXECUTE ccc_stmt; DEALLOCATE PREPARE ccc_stmt;
