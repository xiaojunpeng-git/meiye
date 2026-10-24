-- MySQL 5.6+。一次性升级：暂停目标方案写入后执行，先完成独立恢复点与前后逐行对账。
-- 不使用 IF NOT EXISTS/INSERT IGNORE：重复执行必须失败，避免把已撤销目标重新激活。
CREATE TABLE `eb_target_report_line_binding` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `report_code` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `store_id` bigint unsigned NOT NULL,
  `month_key` char(7) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `metric_code` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `scope_type` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `scope_id` bigint unsigned NOT NULL DEFAULT 0,
  `scheme_id` bigint unsigned NOT NULL,
  `line_id` bigint unsigned NOT NULL,
  `version` int unsigned NOT NULL DEFAULT 1,
  `updated_by` bigint unsigned NOT NULL DEFAULT 0,
  `updated_at` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_report_target` (`tenant_id`,`report_code`,`store_id`,`month_key`,`metric_code`,`scope_type`,`scope_id`),
  KEY `idx_scheme` (`tenant_id`,`scheme_id`,`line_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='目标方案按报表及完整目标身份生效的唯一投影';

-- 只迁移当下真实有效的引用，不恢复草稿/作废或历史上被替换的方案。
INSERT INTO `eb_target_report_line_binding`
 (`tenant_id`,`report_code`,`store_id`,`month_key`,`metric_code`,`scope_type`,`scope_id`,`scheme_id`,`line_id`,`version`,`updated_by`,`updated_at`)
SELECT b.tenant_id,b.report_code,l.store_id,l.month_key,l.metric_code,l.scope_type,l.scope_id,l.scheme_id,l.id,b.version,b.updated_by,b.updated_at
FROM eb_target_report_binding b
JOIN eb_target_effective_line e ON e.tenant_id=b.tenant_id AND e.scheme_id=b.scheme_id AND e.store_id=b.store_id
JOIN eb_target_scheme_line l ON l.id=e.line_id AND l.tenant_id=e.tenant_id AND l.scheme_id=e.scheme_id
JOIN eb_target_scheme s ON s.id=l.scheme_id AND s.tenant_id=l.tenant_id AND s.status='active';

-- 3000 行目标可引用 7 张报表；完整冲突清单与审计不得被 TEXT 的 64KB 上限截断。
ALTER TABLE eb_target_scheme_command MODIFY response_json MEDIUMTEXT NOT NULL;
ALTER TABLE eb_target_scheme_audit MODIFY before_json MEDIUMTEXT NOT NULL, MODIFY after_json MEDIUMTEXT NOT NULL;
