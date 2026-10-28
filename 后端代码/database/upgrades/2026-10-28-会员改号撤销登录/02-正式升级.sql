-- 本地开发迁移；生产执行必须单独授权。MySQL 5.6+，新增表，不改历史会员或资产。
CREATE TABLE IF NOT EXISTS `eb_member_phone_login_reset` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `uid` bigint unsigned NOT NULL,
  `nonce` char(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `phone_verified` tinyint unsigned NOT NULL DEFAULT 0,
  `binding_ids` text NOT NULL COMMENT '被撤销微信历史行ID，不存会话密钥',
  `operator_id` bigint unsigned NOT NULL DEFAULT 0,
  `source` varchar(64) NOT NULL DEFAULT '',
  `created_at` int unsigned NOT NULL,
  PRIMARY KEY (`id`), UNIQUE KEY `uk_nonce` (`nonce`), KEY `idx_uid_id` (`uid`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='会员改号登录撤销与重新授权事件';
