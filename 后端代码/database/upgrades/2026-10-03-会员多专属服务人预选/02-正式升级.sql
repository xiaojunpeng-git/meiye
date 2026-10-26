-- 当前选择按会员和门店人员独立存储；旧单人关系保留为兼容首位人员。
CREATE TABLE IF NOT EXISTS `eb_member_exclusive_service_selection` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `member_id` int(11) unsigned NOT NULL,
  `staff_id` int(11) unsigned NOT NULL,
  `employee_id` int(11) unsigned NOT NULL,
  `store_id` int(11) unsigned NOT NULL,
  `staff_name` varchar(64) NOT NULL DEFAULT '',
  `sort_order` int(11) unsigned NOT NULL DEFAULT '0',
  `status` tinyint(3) unsigned NOT NULL DEFAULT '1',
  `version` bigint(20) unsigned NOT NULL DEFAULT '1',
  `created_at` int(11) unsigned NOT NULL DEFAULT '0',
  `updated_at` int(11) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_member_staff` (`member_id`,`staff_id`),
  KEY `idx_member_active` (`member_id`,`status`,`sort_order`),
  KEY `idx_store_staff` (`store_id`,`staff_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='member selected exclusive service people';

-- 一次会员保存只追加一条变更，保留完整前后人员快照及幂等键。
CREATE TABLE IF NOT EXISTS `eb_member_exclusive_service_selection_change` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `change_key` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `member_id` int(11) unsigned NOT NULL,
  `previous_json` text NOT NULL,
  `current_json` text NOT NULL,
  `operator_id` int(11) unsigned NOT NULL DEFAULT '0',
  `occurred_at` int(11) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_change_key` (`change_key`),
  KEY `idx_member_time` (`member_id`,`occurred_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='member selected exclusive people audit';
