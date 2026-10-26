-- upgrade_key: 20260928-001-inventory-stock-count-draft
-- 草稿不属于已确认盘点事实；独立保存编辑快照，确认时才通过原盘点事务写库存和事实。
CREATE TABLE IF NOT EXISTS `eb_inventory_stock_count_draft` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `store_id` bigint(20) unsigned NOT NULL,
  `location_id` bigint(20) unsigned NOT NULL,
  `operator_id` bigint(20) unsigned NOT NULL,
  `document_status` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'DRAFT',
  `version` int(10) unsigned NOT NULL DEFAULT '1',
  `business_date` date NOT NULL,
  `remark` varchar(500) NOT NULL DEFAULT '',
  `lines_json` longtext NOT NULL,
  `line_count` int(10) unsigned NOT NULL DEFAULT '0',
  `confirmed_document_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `created_at` int(11) unsigned NOT NULL,
  `updated_at` int(11) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_scope_status_updated` (`tenant_id`,`store_id`,`location_id`,`operator_id`,`document_status`,`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='editable inventory count draft; no stock or facts';
