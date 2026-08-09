-- upgrade_key: 20260805-005-cashier-v3-recharge-gift-coupon-claim-reversal
-- MySQL 5.6 compatible. New V3 records only; no historical backfill.
SET NAMES utf8mb4;
SET @rgcm_db := DATABASE();

SELECT COUNT(*) INTO @rgcm_issue_user_id
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@rgcm_db AND TABLE_NAME='eb_store_coupon_issue_user' AND COLUMN_NAME='id';
SET @rgcm_sql := IF(@rgcm_issue_user_id=0,
  'ALTER TABLE `eb_store_coupon_issue_user` ADD COLUMN `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT FIRST, ADD PRIMARY KEY (`id`)',
  'SELECT 1');
PREPARE rgcm_stmt FROM @rgcm_sql; EXECUTE rgcm_stmt; DEALLOCATE PREPARE rgcm_stmt;

CREATE TABLE IF NOT EXISTS `eb_cashier_v3_recharge_gift_coupon_issue_mapping` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `mapping_id` varchar(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `tenant_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `store_id` bigint(20) unsigned NOT NULL,
  `member_id` bigint(20) unsigned NOT NULL,
  `recharge_id` bigint(20) unsigned NOT NULL,
  `gift_id` varchar(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `gift_item_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `item_sequence` int(10) unsigned NOT NULL,
  `coupon_issue_id` bigint(20) unsigned NOT NULL,
  `coupon_user_id` bigint(20) unsigned NOT NULL,
  `coupon_issue_user_id` bigint(20) unsigned NOT NULL,
  `status` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `void_operation_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `voided_at` bigint(20) unsigned NOT NULL DEFAULT '0',
  `created_at` bigint(20) unsigned NOT NULL,
  `updated_at` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_mapping_id` (`mapping_id`),
  UNIQUE KEY `uk_coupon_user` (`coupon_user_id`),
  UNIQUE KEY `uk_coupon_issue_user` (`coupon_issue_user_id`),
  UNIQUE KEY `uk_gift_item_sequence` (`gift_item_id`,`item_sequence`),
  KEY `idx_gift_item_status` (`tenant_id`,`gift_item_id`,`status`,`id`),
  KEY `idx_claim_status` (`coupon_issue_user_id`,`status`,`id`),
  KEY `idx_recharge` (`tenant_id`,`recharge_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='cashier v3 recharge gift coupon claim and void mapping';

SELECT 'APPLY_OK' AS apply_result;
