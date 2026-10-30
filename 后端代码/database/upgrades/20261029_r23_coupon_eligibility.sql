-- R23：新增规则默认关闭；不回填或猜测历史券的原领取人/发放门店。
-- applicable_type=3 为发放门店用，历史 0/1/2 含义不变。MySQL 5.6 可重复执行。
SET NAMES utf8mb4;
SET @r23_sql := IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='eb_store_coupon_issue' AND column_name='usage_target'), 'SELECT 1', 'ALTER TABLE `eb_store_coupon_issue` ADD COLUMN `usage_target` tinyint unsigned NOT NULL DEFAULT 0 COMMENT ''0全部 1仅原领取人以外的受赠人''');
PREPARE r23_stmt FROM @r23_sql; EXECUTE r23_stmt; DEALLOCATE PREPARE r23_stmt;
SET @r23_sql := IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='eb_store_coupon_issue' AND column_name='new_user_only'), 'SELECT 1', 'ALTER TABLE `eb_store_coupon_issue` ADD COLUMN `new_user_only` tinyint unsigned NOT NULL DEFAULT 0 COMMENT ''仅无消费历史用户可使用''');
PREPARE r23_stmt FROM @r23_sql; EXECUTE r23_stmt; DEALLOCATE PREPARE r23_stmt;
SET @r23_sql := IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='eb_store_coupon_user' AND column_name='original_recipient_uid'), 'SELECT 1', 'ALTER TABLE `eb_store_coupon_user` ADD COLUMN `original_recipient_uid` int unsigned NOT NULL DEFAULT 0 COMMENT ''首次领取人，转赠不改变''');
PREPARE r23_stmt FROM @r23_sql; EXECUTE r23_stmt; DEALLOCATE PREPARE r23_stmt;
SET @r23_sql := IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='eb_store_coupon_user' AND column_name='issuance_store_id'), 'SELECT 1', 'ALTER TABLE `eb_store_coupon_user` ADD COLUMN `issuance_store_id` int unsigned NOT NULL DEFAULT 0 COMMENT ''业务发生门店快照，0表示历史未知''');
PREPARE r23_stmt FROM @r23_sql; EXECUTE r23_stmt; DEALLOCATE PREPARE r23_stmt;
