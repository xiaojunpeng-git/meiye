-- upgrade_key: 20261015-001-order-threshold-line-multi-coupon
-- MySQL 5.6 compatible; existing coupons remain detail-mode by default.
SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS `cashier_v3_add_order_coupon_issue_column`;
DELIMITER $$
CREATE PROCEDURE `cashier_v3_add_order_coupon_issue_column`(IN p_column varchar(64), IN p_definition text)
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='eb_store_coupon_issue' AND COLUMN_NAME=p_column
  ) THEN
    SET @order_coupon_issue_sql = CONCAT('ALTER TABLE `eb_store_coupon_issue` ADD COLUMN `', p_column, '` ', p_definition);
    PREPARE order_coupon_issue_stmt FROM @order_coupon_issue_sql;
    EXECUTE order_coupon_issue_stmt;
    DEALLOCATE PREPARE order_coupon_issue_stmt;
  END IF;
END$$
DELIMITER ;

CALL cashier_v3_add_order_coupon_issue_column('use_mode', 'tinyint(1) NOT NULL DEFAULT 0 COMMENT ''0 detail, 1 order threshold with line tender''');
CALL cashier_v3_add_order_coupon_issue_column('order_tiers_json', 'text NULL COMMENT ''immutable order receivable thresholds in cents''');
DROP PROCEDURE IF EXISTS `cashier_v3_add_order_coupon_issue_column`;

CREATE TABLE IF NOT EXISTS `eb_cashier_v3_checkout_order_coupon` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `store_id` bigint(20) unsigned NOT NULL,
  `member_id` bigint(20) unsigned NOT NULL,
  `request_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `request_version` bigint(20) unsigned NOT NULL,
  `checkout_line_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `coupon_user_id` bigint(20) unsigned NOT NULL,
  `coupon_issue_id` bigint(20) unsigned NOT NULL,
  `amount_cents` bigint(20) unsigned NOT NULL,
  `status` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'editing',
  `order_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `order_line_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `created_at` int(11) unsigned NOT NULL,
  `settled_at` int(11) unsigned NOT NULL DEFAULT 0,
  `voided_at` int(11) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_request_coupon` (`request_id`,`coupon_user_id`),
  KEY `idx_request_line` (`tenant_id`,`request_id`,`checkout_line_id`,`status`),
  KEY `idx_order` (`tenant_id`,`order_id`,`status`),
  KEY `idx_coupon` (`coupon_user_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='frozen order threshold coupon tender by selected sale line';

CREATE TABLE IF NOT EXISTS `eb_cashier_v3_order_balance_allocation` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `store_id` bigint(20) unsigned NOT NULL,
  `member_id` bigint(20) unsigned NOT NULL,
  `request_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `order_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `order_line_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `amount_cents` bigint(20) unsigned NOT NULL,
  `created_at` int(11) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_order_line` (`tenant_id`,`order_line_id`),
  KEY `idx_order` (`tenant_id`,`order_id`,`id`),
  KEY `idx_request` (`tenant_id`,`request_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='immutable balance tender allocation for new settled order lines';

SELECT 'APPLY_OK' AS apply_result;
