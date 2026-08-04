-- upgrade_key: 20260803-007-cashier-v3-direct-gift-authority
-- Read-only postcheck; MySQL 5.6 compatible.
SET NAMES utf8mb4;
SET @dg_db := DATABASE();
SELECT COUNT(*) INTO @dg_tables FROM information_schema.TABLES WHERE TABLE_SCHEMA=@dg_db AND TABLE_NAME IN ('eb_cashier_v3_direct_gift_authority','eb_cashier_v3_direct_gift_item') AND ENGINE='InnoDB';
SELECT COUNT(*) INTO @dg_authority_cols FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@dg_db AND TABLE_NAME='eb_cashier_v3_direct_gift_authority' AND COLUMN_NAME IN ('gift_id','gift_no','member_id','reason_snapshot','validity_end','command_idempotency_key','immutable_fingerprint','status');
SELECT COUNT(*) INTO @dg_item_cols FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@dg_db AND TABLE_NAME='eb_cashier_v3_direct_gift_item' AND COLUMN_NAME IN ('gift_id','item_no','item_id','gift_kind','benefit_detail_id','coupon_user_ids_json','status');
SELECT COUNT(*) INTO @dg_unique FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=@dg_db AND TABLE_NAME IN ('eb_cashier_v3_direct_gift_authority','eb_cashier_v3_direct_gift_item') AND NON_UNIQUE=0;
SET @dg_failures := IF(@dg_tables=2,0,1)+IF(@dg_authority_cols=8,0,1)+IF(@dg_item_cols=7,0,1)+IF(@dg_unique>=5,0,1);
SELECT @dg_tables AS exact_table_count,@dg_authority_cols AS authority_column_count,@dg_item_cols AS item_column_count,@dg_unique AS unique_index_count,@dg_failures AS postcheck_failure_count;
SET @dg_abort := IF(@dg_failures=0,'SELECT ''POSTCHECK_OK'' AS postcheck_result','SELECT * FROM STOP_CASHIER_V3_DIRECT_GIFT_POSTCHECK_FAILED');
PREPARE dg_stmt FROM @dg_abort; EXECUTE dg_stmt; DEALLOCATE PREPARE dg_stmt;
