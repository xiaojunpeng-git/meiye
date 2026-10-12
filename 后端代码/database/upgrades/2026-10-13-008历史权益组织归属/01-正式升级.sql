-- 历史权益的旧门店只供审计，不作为新销售或营业门店归属。
-- 仅成功导入且绑定真实 holder 的行可授权；普通卡不进入此表。
CREATE TABLE IF NOT EXISTS `eb_migration_entitlement_scope` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '0',
  `source_system` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `source_detail_id` bigint(20) unsigned NOT NULL,
  `source_order_id` bigint(20) unsigned NOT NULL,
  `source_uid` int(10) unsigned NOT NULL,
  `source_store_id` int(10) unsigned NOT NULL DEFAULT '0',
  `target_uid` int(10) unsigned NOT NULL,
  `card_holder_id` bigint(20) unsigned NOT NULL,
  `owner_org_id` bigint(20) unsigned NOT NULL,
  `status` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'active',
  `source_snapshot_json` longtext NOT NULL,
  `created_at` int(10) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_source` (`tenant_id`,`source_system`,`source_detail_id`),
  UNIQUE KEY `uk_holder` (`tenant_id`,`card_holder_id`),
  KEY `idx_owner_status` (`tenant_id`,`owner_org_id`,`status`),
  KEY `idx_source_uid` (`source_system`,`source_uid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
