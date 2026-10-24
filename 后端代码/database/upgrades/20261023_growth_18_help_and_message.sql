-- MYDX-P1-18：答疑独立问题保留发生时归属；消息引用原业务而不靠标题猜测入口。
CREATE TABLE IF NOT EXISTS `eb_growth_help_scope` (
 `question_id` bigint unsigned NOT NULL PRIMARY KEY,
 `employee_id` int unsigned NOT NULL, `store_id` int unsigned NOT NULL,
 `organization_id` int unsigned NOT NULL,
 KEY `ix_scope` (`store_id`,`organization_id`,`question_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `eb_growth_message_target` (
 `message_id` bigint unsigned NOT NULL PRIMARY KEY,
 `target_type` varchar(24) NOT NULL, `target_id` bigint unsigned NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
