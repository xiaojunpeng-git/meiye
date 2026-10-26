-- upgrade_key: 20260802-002-cashier-v3-recharge-gift-authority
-- Read-only postcheck; MySQL 5.6 compatible.
SET NAMES utf8mb4;
SET @rg_db := DATABASE();
SELECT COUNT(*) INTO @rg_tables FROM information_schema.TABLES WHERE TABLE_SCHEMA=@rg_db AND TABLE_NAME IN ('eb_cashier_v3_recharge_gift_authority','eb_cashier_v3_recharge_gift_item','eb_cashier_v3_gift_fact') AND ENGINE='InnoDB';
SELECT COUNT(*) INTO @rg_authority_cols FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@rg_db AND TABLE_NAME='eb_cashier_v3_recharge_gift_authority' AND COLUMN_NAME IN ('gift_id','recharge_id','configuration_snapshot_json','command_idempotency_key','immutable_fingerprint','status');
SELECT COUNT(*) INTO @rg_item_cols FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@rg_db AND TABLE_NAME='eb_cashier_v3_recharge_gift_item' AND COLUMN_NAME IN ('gift_id','item_no','item_id','gift_kind','benefit_detail_id','coupon_user_ids_json','status');
SELECT COUNT(*) INTO @rg_fact_cols FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@rg_db AND TABLE_NAME='eb_cashier_v3_gift_fact' AND COLUMN_NAME IN ('gift_fact_id','natural_key','business_event_no','gift_kind','recharge_id','business_date','status');
SELECT COUNT(*) INTO @rg_unique FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=@rg_db AND TABLE_NAME IN ('eb_cashier_v3_recharge_gift_authority','eb_cashier_v3_recharge_gift_item','eb_cashier_v3_gift_fact') AND NON_UNIQUE=0;
SET @rg_failures := IF(@rg_tables=3,0,1)+IF(@rg_authority_cols=6,0,1)+IF(@rg_item_cols=7,0,1)+IF(@rg_fact_cols=7,0,1)+IF(@rg_unique>=7,0,1);
SELECT @rg_tables AS exact_table_count,@rg_authority_cols AS authority_column_count,@rg_item_cols AS item_column_count,@rg_fact_cols AS fact_column_count,@rg_unique AS unique_index_count,@rg_failures AS postcheck_failure_count;
SET @rg_abort := IF(@rg_failures=0,'SELECT ''POSTCHECK_OK'' AS postcheck_result','SELECT * FROM STOP_CASHIER_V3_RECHARGE_GIFT_POSTCHECK_FAILED');
PREPARE rg_stmt FROM @rg_abort; EXECUTE rg_stmt; DEALLOCATE PREPARE rg_stmt;
