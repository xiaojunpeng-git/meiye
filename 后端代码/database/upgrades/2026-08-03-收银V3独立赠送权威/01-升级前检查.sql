-- upgrade_key: 20260803-007-cashier-v3-direct-gift-authority
-- Read-only precheck; MySQL 5.6 compatible.
SET NAMES utf8mb4;
SET @dg_db := DATABASE();
SELECT COUNT(*) INTO @dg_log FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@dg_db AND TABLE_NAME='eb_database_upgrade_log' AND COLUMN_NAME='upgrade_key';
SELECT COUNT(*) INTO @dg_event FROM information_schema.TABLES WHERE TABLE_SCHEMA=@dg_db AND TABLE_NAME='eb_cashier_v3_business_event' AND ENGINE='InnoDB';
SELECT COUNT(*) INTO @dg_outbox FROM information_schema.TABLES WHERE TABLE_SCHEMA=@dg_db AND TABLE_NAME='eb_cashier_v3_outbox' AND ENGINE='InnoDB';
SELECT COUNT(*) INTO @dg_consumer_once FROM information_schema.TABLES WHERE TABLE_SCHEMA=@dg_db AND TABLE_NAME='eb_cashier_v3_consumer_once' AND ENGINE='InnoDB';
SELECT COUNT(*) INTO @dg_fact FROM information_schema.TABLES WHERE TABLE_SCHEMA=@dg_db AND TABLE_NAME='eb_cashier_v3_gift_fact' AND ENGINE='InnoDB';
SELECT COUNT(*) INTO @dg_document FROM information_schema.TABLES WHERE TABLE_SCHEMA=@dg_db AND TABLE_NAME='eb_cashier_v3_business_document_no' AND ENGINE='InnoDB';
SELECT COUNT(*) INTO @dg_authority FROM information_schema.TABLES WHERE TABLE_SCHEMA=@dg_db AND TABLE_NAME='eb_cashier_v3_direct_gift_authority';
SELECT COUNT(*) INTO @dg_item FROM information_schema.TABLES WHERE TABLE_SCHEMA=@dg_db AND TABLE_NAME='eb_cashier_v3_direct_gift_item';
SET @dg_failures := IF(@dg_log=1,0,1)+IF(@dg_event=1,0,1)+IF(@dg_outbox=1,0,1)+IF(@dg_consumer_once=1,0,1)+IF(@dg_fact=1,0,1)+IF(@dg_document=1,0,1)+IF(@dg_authority IN (0,1),0,1)+IF(@dg_item IN (0,1),0,1);
SELECT @dg_log AS upgrade_log_ready,@dg_event AS business_event_ready,@dg_outbox AS outbox_ready,@dg_consumer_once AS consumer_once_ready,@dg_fact AS gift_fact_ready,@dg_document AS document_number_ready,@dg_authority AS authority_existing,@dg_item AS item_existing,@dg_failures AS precheck_failure_count;
SET @dg_abort := IF(@dg_failures=0,'SELECT ''PRECHECK_OK'' AS precheck_result','SELECT * FROM STOP_CASHIER_V3_DIRECT_GIFT_PRECHECK_FAILED');
PREPARE dg_stmt FROM @dg_abort; EXECUTE dg_stmt; DEALLOCATE PREPARE dg_stmt;
