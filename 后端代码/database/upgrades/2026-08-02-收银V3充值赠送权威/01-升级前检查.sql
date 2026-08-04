-- upgrade_key: 20260802-002-cashier-v3-recharge-gift-authority
-- Read-only precheck; MySQL 5.6 compatible.
SET NAMES utf8mb4;
SET @rg_db := DATABASE();
SELECT COUNT(*) INTO @rg_log FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@rg_db AND TABLE_NAME='eb_database_upgrade_log' AND COLUMN_NAME='upgrade_key';
SELECT COUNT(*) INTO @rg_recharge FROM information_schema.TABLES WHERE TABLE_SCHEMA=@rg_db AND TABLE_NAME='eb_user_recharge' AND ENGINE='InnoDB';
SELECT COUNT(*) INTO @rg_event FROM information_schema.TABLES WHERE TABLE_SCHEMA=@rg_db AND TABLE_NAME='eb_cashier_v3_business_event' AND ENGINE='InnoDB';
SELECT COUNT(*) INTO @rg_authority FROM information_schema.TABLES WHERE TABLE_SCHEMA=@rg_db AND TABLE_NAME='eb_cashier_v3_recharge_gift_authority';
SELECT COUNT(*) INTO @rg_item FROM information_schema.TABLES WHERE TABLE_SCHEMA=@rg_db AND TABLE_NAME='eb_cashier_v3_recharge_gift_item';
SELECT COUNT(*) INTO @rg_fact FROM information_schema.TABLES WHERE TABLE_SCHEMA=@rg_db AND TABLE_NAME='eb_cashier_v3_gift_fact';
SET @rg_failures := IF(@rg_log=1,0,1)+IF(@rg_recharge=1,0,1)+IF(@rg_event=1,0,1)+IF(@rg_authority IN (0,1),0,1)+IF(@rg_item IN (0,1),0,1)+IF(@rg_fact IN (0,1),0,1);
SELECT @rg_log AS upgrade_log_ready,@rg_recharge AS recharge_ready,@rg_event AS business_event_ready,@rg_authority AS authority_existing,@rg_item AS item_existing,@rg_fact AS fact_existing,@rg_failures AS precheck_failure_count;
SET @rg_abort := IF(@rg_failures=0,'SELECT ''PRECHECK_OK'' AS precheck_result','SELECT * FROM STOP_CASHIER_V3_RECHARGE_GIFT_PRECHECK_FAILED');
PREPARE rg_stmt FROM @rg_abort; EXECUTE rg_stmt; DEALLOCATE PREPARE rg_stmt;
