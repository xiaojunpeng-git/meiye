-- upgrade_key: 20260805-003-inventory-v3-request-termination-transfer-reversal
SET NAMES utf8mb4;

SET @upgrade_log_exists := (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='eb_database_upgrade_log');
SET @upgrade_key_registered := IF(@upgrade_log_exists=1,(SELECT COUNT(*) FROM eb_database_upgrade_log WHERE upgrade_key='20260805-003-inventory-v3-request-termination-transfer-reversal'),1);
SET @authority_tables := (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('eb_inventory_stock_request_document','eb_inventory_stock_request_fulfillment','eb_inventory_cross_transfer_document','eb_inventory_batch_movement_fact'));
SELECT IF(@upgrade_log_exists=1 AND @upgrade_key_registered=0 AND @authority_tables=4,'PRECHECK_OK','PRECHECK_FAILED') AS precheck_result,
       @upgrade_log_exists AS upgrade_log_exists,@upgrade_key_registered AS upgrade_key_registered,@authority_tables AS authority_tables;

