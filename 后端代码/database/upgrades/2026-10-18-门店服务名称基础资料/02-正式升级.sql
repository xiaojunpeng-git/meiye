-- upgrade_key: 20261018-001-store-service-name-master
-- MySQL 5.7 compatible and idempotent.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `eb_cashier_v3_service_name` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT COMMENT '稳定服务名称ID',
  `store_id` bigint unsigned NOT NULL COMMENT '会话门店ID',
  `service_name` varchar(64) NOT NULL COMMENT '仅作基础资料保存的服务名称',
  `sort_order` int unsigned NOT NULL DEFAULT '0' COMMENT '门店内显示顺序',
  `is_enabled` tinyint unsigned NOT NULL DEFAULT '1' COMMENT '1启用0停用',
  `version` bigint unsigned NOT NULL DEFAULT '1' COMMENT '乐观锁版本',
  `created_by` bigint unsigned NOT NULL DEFAULT '0' COMMENT '创建员工ID',
  `updated_by` bigint unsigned NOT NULL DEFAULT '0' COMMENT '最后修改员工ID',
  `created_at` bigint unsigned NOT NULL DEFAULT '0' COMMENT '创建Unix时间',
  `updated_at` bigint unsigned NOT NULL DEFAULT '0' COMMENT '更新Unix时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_store_service_name` (`store_id`,`service_name`),
  KEY `idx_store_status_sort` (`store_id`,`is_enabled`,`sort_order`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='门店独立服务名称基础资料';

CREATE TABLE IF NOT EXISTS `eb_cashier_v3_service_name_setting` (
  `store_id` bigint unsigned NOT NULL COMMENT '会话门店ID兼稳定设置行键',
  `is_enabled` tinyint unsigned NOT NULL DEFAULT '1' COMMENT '1开启0关闭，仅独立保存',
  `version` bigint unsigned NOT NULL DEFAULT '1' COMMENT '乐观锁版本',
  `created_by` bigint unsigned NOT NULL DEFAULT '0' COMMENT '创建员工ID',
  `updated_by` bigint unsigned NOT NULL DEFAULT '0' COMMENT '最后修改员工ID',
  `created_at` bigint unsigned NOT NULL DEFAULT '0' COMMENT '创建Unix时间',
  `updated_at` bigint unsigned NOT NULL DEFAULT '0' COMMENT '更新Unix时间',
  PRIMARY KEY (`store_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='门店服务名称独立总开关';

CREATE TABLE IF NOT EXISTS `eb_cashier_v3_service_name_audit` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT COMMENT '审计ID',
  `store_id` bigint unsigned NOT NULL COMMENT '会话门店ID',
  `service_name_id` bigint unsigned NOT NULL COMMENT '服务名称ID，排序或总开关写0',
  `action` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'create/update/status/sort/switch',
  `request_token` varchar(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '请求幂等键',
  `request_fingerprint` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '请求内容摘要',
  `expected_version` bigint unsigned NOT NULL DEFAULT '0' COMMENT '请求期望版本',
  `result_version` bigint unsigned NOT NULL DEFAULT '0' COMMENT '写入结果版本',
  `old_snapshot_json` text NOT NULL COMMENT '写入前快照',
  `new_snapshot_json` text NOT NULL COMMENT '写入后快照',
  `operator_id` bigint unsigned NOT NULL DEFAULT '0' COMMENT '操作员工ID',
  `operator_name_snapshot` varchar(128) NOT NULL DEFAULT '' COMMENT '操作员工名称快照',
  `created_at` bigint unsigned NOT NULL DEFAULT '0' COMMENT '创建Unix时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_store_request_token` (`store_id`,`request_token`),
  KEY `idx_store_service_audit` (`store_id`,`service_name_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='门店服务名称写入审计';

INSERT INTO eb_database_upgrade_log
(`upgrade_key`,`title`,`task_file`,`sql_checksum`,`code_version`,`executed_at`,`executed_by`,`result_note`)
SELECT '20261018-001-store-service-name-master','门店服务名称基础资料',
       '2026-10-18-门店服务名称基础资料/02-正式升级.sql','', '', NOW(), 'codex-local',
       'created isolated per-store service name master, independent switch and same-transaction audit tables'
FROM DUAL
WHERE NOT EXISTS (
  SELECT 1 FROM eb_database_upgrade_log
  WHERE upgrade_key = '20261018-001-store-service-name-master'
);
