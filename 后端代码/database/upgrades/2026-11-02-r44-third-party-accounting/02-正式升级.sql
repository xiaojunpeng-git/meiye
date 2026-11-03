-- 第44轮：只有迁移后新创建的手机订单使用第三方支付，历史/待付款订单保持空标记。
-- 前置：member_commerce_order 与 cashier_v3_payment_method_config 已按对应正式迁移建立。
SET NAMES utf8mb4;
SET @r44_has_store_marker = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='eb_store_order' AND COLUMN_NAME='accounting_method');
SET @r44_store_ddl = IF(@r44_has_store_marker=0, 'ALTER TABLE eb_store_order ADD COLUMN accounting_method VARCHAR(32) NOT NULL DEFAULT \'\' COMMENT \'创建时冻结的手机端记账分类；空值保留历史渠道\'', 'SELECT 1');
PREPARE r44_store_stmt FROM @r44_store_ddl;
EXECUTE r44_store_stmt;
DEALLOCATE PREPARE r44_store_stmt;
SET @r44_has_member_marker = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='eb_member_commerce_order' AND COLUMN_NAME='accounting_method');
SET @r44_member_ddl = IF(@r44_has_member_marker=0, 'ALTER TABLE eb_member_commerce_order ADD COLUMN accounting_method VARCHAR(32) NOT NULL DEFAULT \'\' COMMENT \'创建时冻结的手机端记账分类；空值保留历史渠道\'', 'SELECT 1');
PREPARE r44_member_stmt FROM @r44_member_ddl;
EXECUTE r44_member_stmt;
DEALLOCATE PREPARE r44_member_stmt;
-- 唯一代码只增加字典，不改既有渠道、不回填订单、不生成任何资金事实。
INSERT INTO eb_cashier_v3_payment_method_config
(code,default_name,display_name,status,sort,version,created_at,updated_at)
VALUES ('third_party','第三方支付','第三方支付',1,76,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE code=VALUES(code);
