CREATE TABLE IF NOT EXISTS `eb_cashier_v3_store_session` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `employee_id` int unsigned NOT NULL DEFAULT 0,
  `store_id` int unsigned NOT NULL DEFAULT 0,
  `token_secret_hash` char(32) NOT NULL DEFAULT '' COMMENT '随机会话密钥 MD5，不保存明文',
  `auth_version` int unsigned NOT NULL DEFAULT 1 COMMENT '员工权限版本',
  `expire_time` int unsigned NOT NULL DEFAULT 0,
  `status` tinyint unsigned NOT NULL DEFAULT 1,
  `is_del` tinyint unsigned NOT NULL DEFAULT 0,
  `source` varchar(32) NOT NULL DEFAULT 'data_scope',
  `account_snapshot` varchar(128) NOT NULL DEFAULT '',
  `staff_name_snapshot` varchar(128) NOT NULL DEFAULT '',
  `add_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_token_secret_hash` (`token_secret_hash`),
  KEY `idx_employee_status` (`employee_id`,`status`,`is_del`),
  KEY `idx_store_status` (`store_id`,`status`,`is_del`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='收银V3数据权限固定门店会话';
