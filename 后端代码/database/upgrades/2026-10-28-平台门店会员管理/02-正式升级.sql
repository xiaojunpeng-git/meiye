-- 本轮管理命令的幂等回执。只保留请求HMAC、非敏感结果；不保存明文密码或密码哈希。
CREATE TABLE IF NOT EXISTS `eb_member_management_receipt` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT,
 `actor_type` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 `actor_id` bigint unsigned NOT NULL,
 `request_key` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 `request_hmac` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 `action` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 `member_id` bigint unsigned NOT NULL DEFAULT 0,
 `response_json` longtext NOT NULL,
 `created_at` int unsigned NOT NULL,
 PRIMARY KEY (`id`), UNIQUE KEY `uk_actor_request` (`actor_type`,`actor_id`,`request_key`),
 KEY `idx_member_time` (`member_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='平台门店会员管理命令回执，不存密码';
