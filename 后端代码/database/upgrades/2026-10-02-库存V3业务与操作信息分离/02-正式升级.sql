-- upgrade_key: 20261002-001-inventory-business-operation-audit
SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS inventory_add_audit_column;
DELIMITER $$
CREATE PROCEDURE inventory_add_audit_column(IN p_table varchar(64), IN p_column varchar(64), IN p_definition varchar(500))
BEGIN
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=p_table AND column_name=p_column) THEN
    SET @inventory_audit_sql=CONCAT('ALTER TABLE `',p_table,'` ADD COLUMN `',p_column,'` ',p_definition);
    PREPARE inventory_audit_stmt FROM @inventory_audit_sql;
    EXECUTE inventory_audit_stmt;
    DEALLOCATE PREPARE inventory_audit_stmt;
  END IF;
END$$
DELIMITER ;

CALL inventory_add_audit_column('eb_inventory_batch_movement_fact','operator_type','varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '''' AFTER `store_id`');
CALL inventory_add_audit_column('eb_inventory_batch_movement_fact','operator_id','bigint(20) unsigned NOT NULL DEFAULT ''0'' AFTER `operator_type`');
CALL inventory_add_audit_column('eb_inventory_batch_movement_fact','operator_name_snapshot','varchar(128) NOT NULL DEFAULT '''' AFTER `operator_id`');
CALL inventory_add_audit_column('eb_inventory_stock_count_document','operator_name_snapshot','varchar(128) NOT NULL DEFAULT '''' AFTER `operator_type`');
CALL inventory_add_audit_column('eb_inventory_cross_transfer_document','created_by_operator_name_snapshot','varchar(128) NOT NULL DEFAULT '''' AFTER `created_by_operator_id`');
CALL inventory_add_audit_column('eb_inventory_salon_usage_document','operator_name_snapshot','varchar(128) NOT NULL DEFAULT '''' AFTER `operator_id`');
CALL inventory_add_audit_column('eb_inventory_v3_import_record','operator_name_snapshot','varchar(128) NOT NULL DEFAULT '''' AFTER `operator_id`');

DROP PROCEDURE IF EXISTS inventory_add_audit_column;
SELECT 'APPLY_OK' AS apply_result;
