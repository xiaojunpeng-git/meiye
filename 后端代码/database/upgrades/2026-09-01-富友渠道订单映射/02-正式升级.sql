-- upgrade_key: 20260901-001-fuyou-payment-attempt
-- MySQL 5.6 compatible, repeatable and additive only.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `eb_fuyou_payment_attempt` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `channel_order_no` char(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `business_order_no` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `business_type` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `channel` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `amount_cents` bigint(20) unsigned NOT NULL,
  `status` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'created',
  `transaction_id` varchar(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `response_code` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `add_time` bigint(20) unsigned NOT NULL,
  `update_time` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_channel_order_no` (`channel_order_no`),
  KEY `idx_business_order` (`business_type`,`business_order_no`,`id`),
  KEY `idx_status_time` (`status`,`update_time`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Fuyou channel payment attempts; not a business fact';

CREATE TABLE IF NOT EXISTS `eb_wechat_accesstoken` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `access_token` text COMMENT '微信token',
  `expires_time` int(11) NOT NULL DEFAULT '0' COMMENT '过期时间',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='WeChat access token cache for payment URL Link';

SELECT 'APPLY_OK' AS apply_result;
