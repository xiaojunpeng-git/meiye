-- upgrade_key: 20260816-002-phase-three-six-dimension-report-foundation
-- MySQL 5.6 compatible and re-runnable. No business fact is overwritten.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `eb_cashier_v3_report_organization_dimension` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '0',
  `organization_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `organization_name_snapshot` varchar(128) NOT NULL DEFAULT '',
  `dimension_code` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `display_order` int(10) unsigned NOT NULL DEFAULT '0',
  `valid_from` date NOT NULL,
  `valid_to` date DEFAULT NULL,
  `enabled` tinyint(1) unsigned NOT NULL DEFAULT '1',
  `deleted_at` int(10) unsigned DEFAULT NULL,
  `version` bigint(20) unsigned NOT NULL DEFAULT '1',
  `created_by` bigint(20) unsigned NOT NULL DEFAULT '0',
  `updated_by` bigint(20) unsigned NOT NULL DEFAULT '0',
  `created_at` int(10) unsigned NOT NULL,
  `updated_at` int(10) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_org_dimension_from` (`tenant_id`,`organization_id`,`dimension_code`,`valid_from`),
  KEY `idx_tenant_dimension_period` (`tenant_id`,`dimension_code`,`enabled`,`valid_from`,`valid_to`,`display_order`,`id`),
  KEY `idx_tenant_org_period` (`tenant_id`,`organization_id`,`valid_from`,`valid_to`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='phase three report organization statistic dimensions';

CREATE TABLE IF NOT EXISTS `eb_cashier_v3_report_consumption_tier` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '0',
  `tier_code` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `tier_name` varchar(128) NOT NULL DEFAULT '',
  `lower_bound_cents` bigint(20) NOT NULL DEFAULT '0',
  `upper_bound_cents` bigint(20) DEFAULT NULL,
  `sort_order` int(10) unsigned NOT NULL DEFAULT '0',
  `enabled` tinyint(1) unsigned NOT NULL DEFAULT '1',
  `deleted_at` int(10) unsigned DEFAULT NULL,
  `version` bigint(20) unsigned NOT NULL DEFAULT '1',
  `created_by` bigint(20) unsigned NOT NULL DEFAULT '0',
  `created_by_name_snapshot` varchar(128) NOT NULL DEFAULT '',
  `updated_by` bigint(20) unsigned NOT NULL DEFAULT '0',
  `updated_by_name_snapshot` varchar(128) NOT NULL DEFAULT '',
  `created_at` int(10) unsigned NOT NULL,
  `updated_at` int(10) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_tier_code` (`tenant_id`,`tier_code`),
  KEY `idx_tenant_enabled_order` (`tenant_id`,`deleted_at`,`enabled`,`sort_order`,`id`),
  KEY `idx_tenant_bounds` (`tenant_id`,`enabled`,`lower_bound_cents`,`upper_bound_cents`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='phase three cash consumption tier configuration';

SET @phase3_db := DATABASE();
SET @phase3_migration_at := UNIX_TIMESTAMP();
SET @phase3_tier_deleted_at_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=@phase3_db
    AND TABLE_NAME='eb_cashier_v3_report_consumption_tier'
    AND COLUMN_NAME='deleted_at'
);
SET @phase3_tier_deleted_at_compatible := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=@phase3_db
    AND TABLE_NAME='eb_cashier_v3_report_consumption_tier'
    AND COLUMN_NAME='deleted_at'
    AND COLUMN_TYPE='int(10) unsigned'
    AND IS_NULLABLE='YES'
);
SET @phase3_sql := CASE
  WHEN @phase3_tier_deleted_at_exists=0 THEN
    'ALTER TABLE `eb_cashier_v3_report_consumption_tier` ADD COLUMN `deleted_at` int(10) unsigned DEFAULT NULL AFTER `enabled`'
  WHEN @phase3_tier_deleted_at_compatible=0 THEN
    'ALTER TABLE `eb_cashier_v3_report_consumption_tier` MODIFY COLUMN `deleted_at` int(10) unsigned DEFAULT NULL AFTER `enabled`'
  ELSE 'SELECT ''TIER_DELETED_AT_ALREADY_COMPATIBLE'' AS apply_result'
END;
PREPARE phase3_stmt FROM @phase3_sql; EXECUTE phase3_stmt; DEALLOCATE PREPARE phase3_stmt;

-- Early development builds used 0 as the not-deleted sentinel. Normalize it to the final nullable contract.
UPDATE `eb_cashier_v3_report_consumption_tier`
SET `deleted_at`=NULL
WHERE `deleted_at`=0;

SET @phase3_tier_order_index_columns := (
  SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',')
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=@phase3_db
    AND TABLE_NAME='eb_cashier_v3_report_consumption_tier'
    AND INDEX_NAME='idx_tenant_enabled_order'
);
SET @phase3_sql := CASE
  WHEN @phase3_tier_order_index_columns IS NULL THEN
    'ALTER TABLE `eb_cashier_v3_report_consumption_tier` ADD KEY `idx_tenant_enabled_order` (`tenant_id`,`deleted_at`,`enabled`,`sort_order`,`id`)'
  WHEN @phase3_tier_order_index_columns <> 'tenant_id,deleted_at,enabled,sort_order,id' THEN
    'ALTER TABLE `eb_cashier_v3_report_consumption_tier` DROP KEY `idx_tenant_enabled_order`, ADD KEY `idx_tenant_enabled_order` (`tenant_id`,`deleted_at`,`enabled`,`sort_order`,`id`)'
  ELSE 'SELECT ''TIER_ORDER_INDEX_ALREADY_PRESENT'' AS apply_result'
END;
PREPARE phase3_stmt FROM @phase3_sql; EXECUTE phase3_stmt; DEALLOCATE PREPARE phase3_stmt;

SET @phase3_payment_date_index_exists := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=@phase3_db
    AND TABLE_NAME='eb_cashier_v3_payment_sale_allocation_fact'
    AND INDEX_NAME='idx_scope_date_status'
);
SET @phase3_sql := IF(@phase3_payment_date_index_exists=0,
  'ALTER TABLE `eb_cashier_v3_payment_sale_allocation_fact` ADD KEY `idx_scope_date_status` (`tenant_id`,`store_id`,`business_date`,`status`,`id`)',
  'SELECT ''PAYMENT_ALLOCATION_DATE_INDEX_ALREADY_PRESENT'' AS apply_result');
PREPARE phase3_stmt FROM @phase3_sql; EXECUTE phase3_stmt; DEALLOCATE PREPARE phase3_stmt;

SET @phase3_card_operation_date_index_columns := (
  SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',')
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=@phase3_db
    AND TABLE_NAME='eb_cashier_v3_card_operation'
    AND INDEX_NAME='idx_scope_business_date_status'
);
SET @phase3_sql := CASE
  WHEN @phase3_card_operation_date_index_columns IS NULL THEN
    'ALTER TABLE `eb_cashier_v3_card_operation` ADD KEY `idx_scope_business_date_status` (`tenant_id`,`store_id`,`business_date`,`operation_status`,`operation_type`,`id`)'
  WHEN @phase3_card_operation_date_index_columns <> 'tenant_id,store_id,business_date,operation_status,operation_type,id' THEN
    'ALTER TABLE `eb_cashier_v3_card_operation` DROP KEY `idx_scope_business_date_status`, ADD KEY `idx_scope_business_date_status` (`tenant_id`,`store_id`,`business_date`,`operation_status`,`operation_type`,`id`)'
  ELSE 'SELECT ''CARD_OPERATION_BUSINESS_DATE_INDEX_ALREADY_PRESENT'' AS apply_result'
END;
PREPARE phase3_stmt FROM @phase3_sql; EXECUTE phase3_stmt; DEALLOCATE PREPARE phase3_stmt;

CREATE TABLE IF NOT EXISTS `eb_cashier_v3_report_consumption_tier_audit` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '0',
  `tier_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `tier_code` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `action` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `idempotency_key` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `before_snapshot_json` mediumtext NOT NULL,
  `after_snapshot_json` mediumtext NOT NULL,
  `before_version` bigint(20) unsigned NOT NULL DEFAULT '0',
  `after_version` bigint(20) unsigned NOT NULL DEFAULT '0',
  `operator_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `operator_name_snapshot` varchar(128) NOT NULL DEFAULT '',
  `occurred_at` int(10) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_tier_idempotency` (`tenant_id`,`idempotency_key`),
  KEY `idx_tenant_tier_time` (`tenant_id`,`tier_code`,`occurred_at`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='immutable cash consumption tier configuration audit';

CREATE TABLE IF NOT EXISTS `eb_cashier_v3_report_member_origin_evidence` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '0',
  `member_id` bigint(20) unsigned NOT NULL,
  `origin_type` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `evidence_status` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'PROVEN',
  `evidence_source_type` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `evidence_source_id` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `member_created_at` int(10) unsigned NOT NULL DEFAULT '0',
  `cutover_at` int(10) unsigned NOT NULL,
  `version` bigint(20) unsigned NOT NULL DEFAULT '1',
  `idempotency_key` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `created_at` int(10) unsigned NOT NULL,
  `updated_at` int(10) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_member_origin` (`tenant_id`,`member_id`),
  UNIQUE KEY `uk_tenant_origin_idempotency` (`tenant_id`,`idempotency_key`),
  KEY `idx_tenant_origin_created` (`tenant_id`,`origin_type`,`member_created_at`,`member_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='member origin evidence for phase three new and returning classification';

CREATE TABLE IF NOT EXISTS `eb_cashier_v3_report_member_origin_evidence_audit` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '0',
  `member_id` bigint(20) unsigned NOT NULL,
  `action` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `idempotency_key` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `before_snapshot_json` mediumtext NOT NULL,
  `after_snapshot_json` mediumtext NOT NULL,
  `before_version` bigint(20) unsigned NOT NULL DEFAULT '0',
  `after_version` bigint(20) unsigned NOT NULL DEFAULT '0',
  `operator_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `occurred_at` int(10) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_origin_audit_idem` (`tenant_id`,`idempotency_key`),
  KEY `idx_tenant_member_origin_audit` (`tenant_id`,`member_id`,`occurred_at`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='immutable member origin evidence audit';

CREATE TABLE IF NOT EXISTS `eb_cashier_v3_report_member_store_assignment_period` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '0',
  `member_id` bigint(20) unsigned NOT NULL,
  `assigned` tinyint(1) unsigned NOT NULL DEFAULT '1',
  `store_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `store_name_snapshot` varchar(128) NOT NULL DEFAULT '',
  `valid_from_at` int(10) unsigned NOT NULL,
  `valid_to_at` int(10) unsigned DEFAULT NULL,
  `source_type` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `source_event_id` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `idempotency_key` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `version` bigint(20) unsigned NOT NULL DEFAULT '1',
  `immutable_fingerprint` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `created_at` int(10) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_assignment_source` (`tenant_id`,`source_type`,`source_event_id`),
  UNIQUE KEY `uk_tenant_assignment_idem` (`tenant_id`,`idempotency_key`),
  KEY `idx_tenant_member_period` (`tenant_id`,`member_id`,`valid_from_at`,`valid_to_at`,`id`),
  KEY `idx_tenant_store_period` (`tenant_id`,`store_id`,`valid_from_at`,`valid_to_at`,`member_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='member belonging-store effective periods for report cutoff attribution';

CREATE TABLE IF NOT EXISTS `eb_cashier_v3_card_sale_item_allocation_fact` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `allocation_fact_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `natural_key` varchar(180) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `contract_version` varchar(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `fact_version` int(10) unsigned NOT NULL DEFAULT '1',
  `status` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'effective',
  `reversal_of` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `tenant_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `organization_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `store_id` bigint(20) unsigned NOT NULL,
  `member_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `order_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `sale_fact_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `source_line_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `card_receipt_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `card_issue_no` int(10) unsigned NOT NULL,
  `component_product_id` bigint(20) unsigned NOT NULL,
  `item_name_snapshot` varchar(255) NOT NULL DEFAULT '',
  `component_count` int(10) unsigned NOT NULL DEFAULT '0',
  `category_id_snapshot` bigint(20) unsigned NOT NULL,
  `category_name_snapshot` varchar(128) NOT NULL DEFAULT '',
  `category_path_snapshot` varchar(512) NOT NULL DEFAULT '',
  `configured_amount_cents` bigint(20) unsigned NOT NULL,
  `sale_amount_cents` bigint(20) NOT NULL,
  `cash_performance_amount_cents` bigint(20) NOT NULL,
  `business_date` date NOT NULL,
  `occurred_at` bigint(20) unsigned NOT NULL,
  `settled_at` bigint(20) unsigned NOT NULL,
  `recorded_at` bigint(20) unsigned NOT NULL,
  `business_event_no` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `command_idempotency_key` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `immutable_fingerprint` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `add_time` bigint(20) unsigned NOT NULL,
  `update_time` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_item_natural` (`tenant_id`,`natural_key`),
  UNIQUE KEY `uk_tenant_receipt_item` (`tenant_id`,`card_receipt_id`,`component_product_id`),
  KEY `idx_scope_item` (`tenant_id`,`store_id`,`business_date`,`component_product_id`,`status`,`id`),
  KEY `idx_scope_category` (`tenant_id`,`store_id`,`business_date`,`category_id_snapshot`,`status`,`id`),
  KEY `idx_sale_fact` (`tenant_id`,`sale_fact_id`,`status`,`id`),
  KEY `idx_order_line` (`tenant_id`,`order_id`,`source_line_id`,`status`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='immutable card sale contained-project allocations';

-- Default tiers use cents and left-closed/right-open intervals. The open top tier has no upper bound.
INSERT IGNORE INTO `eb_cashier_v3_report_consumption_tier`
(`tenant_id`,`tier_code`,`tier_name`,`lower_bound_cents`,`upper_bound_cents`,`sort_order`,`enabled`,`version`,`created_by`,`created_by_name_snapshot`,`updated_by`,`updated_by_name_snapshot`,`created_at`,`updated_at`) VALUES
('0','gte_100000','100000以上',10000000,NULL,10,1,1,0,'migration',0,'migration',@phase3_migration_at,@phase3_migration_at),
('0','50000_100000','50000-100000',5000000,10000000,20,1,1,0,'migration',0,'migration',@phase3_migration_at,@phase3_migration_at),
('0','30000_50000','30000-50000',3000000,5000000,30,1,1,0,'migration',0,'migration',@phase3_migration_at,@phase3_migration_at),
('0','10000_30000','10000-30000',1000000,3000000,40,1,1,0,'migration',0,'migration',@phase3_migration_at,@phase3_migration_at),
('0','5000_10000','5000-10000',500000,1000000,50,1,1,0,'migration',0,'migration',@phase3_migration_at,@phase3_migration_at),
('0','0_5000','0-5000',0,500000,60,1,1,0,'migration',0,'migration',@phase3_migration_at,@phase3_migration_at);

INSERT IGNORE INTO `eb_cashier_v3_report_consumption_tier_audit`
(`tenant_id`,`tier_id`,`tier_code`,`action`,`idempotency_key`,`before_snapshot_json`,`after_snapshot_json`,`before_version`,`after_version`,`operator_id`,`operator_name_snapshot`,`occurred_at`)
SELECT t.tenant_id,t.id,t.tier_code,'SEEDED',CONCAT('phase3-tier-seed:',t.tier_code),'{}',
       CONCAT('{"tier_code":"',t.tier_code,'","tier_name":"',t.tier_name,'","lower_bound_cents":',t.lower_bound_cents,',"upper_bound_cents":',IFNULL(t.upper_bound_cents,'null'),',"sort_order":',t.sort_order,',"enabled":',t.enabled,',"version":',t.version,'}'),
       0,t.version,0,'migration',@phase3_migration_at
FROM eb_cashier_v3_report_consumption_tier t
WHERE t.tenant_id='0'
  AND t.created_by_name_snapshot='migration'
  AND t.tier_code IN ('gte_100000','50000_100000','30000_50000','10000_30000','5000_10000','0_5000');

-- Existing members are classified from persisted creation evidence. Imported and pre-cutover members are always returning customers.
INSERT IGNORE INTO `eb_cashier_v3_report_member_origin_evidence`
(`tenant_id`,`member_id`,`origin_type`,`evidence_status`,`evidence_source_type`,`evidence_source_id`,`member_created_at`,`cutover_at`,`version`,`idempotency_key`,`created_at`,`updated_at`)
SELECT '0',u.uid,
       CASE
         WHEN u.add_time < 1786291200 THEN 'PRE_CUTOVER'
         WHEN u.user_type='import' OR u.login_type='import' THEN 'IMPORTED'
         WHEN u.add_time >= 1786291200 THEN 'SYSTEM_CREATED'
         ELSE 'UNKNOWN'
       END,
       CASE WHEN u.add_time > 0 THEN 'DERIVED' ELSE 'UNKNOWN' END,
       'USER_CREATION_SNAPSHOT',CONCAT('user:',u.uid),IFNULL(u.add_time,0),1786291200,1,
       CONCAT('phase3-origin-seed:',u.uid),@phase3_migration_at,@phase3_migration_at
FROM eb_user u;

INSERT IGNORE INTO `eb_cashier_v3_report_member_origin_evidence_audit`
(`tenant_id`,`member_id`,`action`,`idempotency_key`,`before_snapshot_json`,`after_snapshot_json`,`before_version`,`after_version`,`operator_id`,`occurred_at`)
SELECT e.tenant_id,e.member_id,'SEEDED',CONCAT('phase3-origin-audit-seed:',e.member_id),'{}',
       CONCAT('{"origin_type":"',e.origin_type,'","evidence_status":"',e.evidence_status,'","member_created_at":',e.member_created_at,',"cutover_at":',e.cutover_at,',"version":',e.version,'}'),
       0,e.version,0,@phase3_migration_at
FROM eb_cashier_v3_report_member_origin_evidence e
WHERE e.tenant_id='0'
  AND e.idempotency_key=CONCAT('phase3-origin-seed:',e.member_id);

-- Persist every historical bind/switch/unbind event as an effective period. valid_to_at is exclusive.
INSERT IGNORE INTO `eb_cashier_v3_report_member_store_assignment_period`
(`tenant_id`,`member_id`,`assigned`,`store_id`,`store_name_snapshot`,`valid_from_at`,`valid_to_at`,`source_type`,`source_event_id`,`idempotency_key`,`version`,`immutable_fingerprint`,`created_at`)
SELECT '0',h.uid,IF(h.`group`=3 OR h.store_id=0,0,1),IF(h.`group`=3,0,h.store_id),IF(h.`group`=3,'',IFNULL(s.name,'')),
       h.add_time,
       (SELECT MIN(n.add_time) FROM eb_user_belong_store n
        WHERE n.uid=h.uid AND n.is_store=1
          AND (n.add_time>h.add_time OR (n.add_time=h.add_time AND n.id>h.id))),
       'USER_BELONG_STORE_HISTORY',CAST(h.id AS CHAR),CONCAT('phase3-member-store-history:',h.id),1,
       SHA2(CONCAT_WS('|','0',h.uid,h.`group`,h.store_id,IFNULL(s.name,''),h.add_time,IFNULL((SELECT MIN(n2.add_time) FROM eb_user_belong_store n2 WHERE n2.uid=h.uid AND n2.is_store=1 AND (n2.add_time>h.add_time OR (n2.add_time=h.add_time AND n2.id>h.id))),0),h.id),256),
       @phase3_migration_at
FROM eb_user_belong_store h
LEFT JOIN eb_system_store s ON s.id=h.store_id
WHERE h.is_store=1;

-- If no history exists, the current relation is only trustworthy from migration time onward.
INSERT IGNORE INTO `eb_cashier_v3_report_member_store_assignment_period`
(`tenant_id`,`member_id`,`assigned`,`store_id`,`store_name_snapshot`,`valid_from_at`,`valid_to_at`,`source_type`,`source_event_id`,`idempotency_key`,`version`,`immutable_fingerprint`,`created_at`)
SELECT '0',u.uid,1,u.belong_store_id,IFNULL(s.name,''),@phase3_migration_at,NULL,
       'LEGACY_CURRENT_ASSIGNMENT',CAST(u.uid AS CHAR),CONCAT('phase3-member-store-current:',u.uid),1,
       SHA2(CONCAT_WS('|','0',u.uid,u.belong_store_id,IFNULL(s.name,''),@phase3_migration_at),256),@phase3_migration_at
FROM eb_user u
LEFT JOIN eb_system_store s ON s.id=u.belong_store_id
WHERE u.belong_store_id>0
  AND NOT EXISTS (SELECT 1 FROM eb_user_belong_store h WHERE h.uid=u.uid AND h.is_store=1);

-- Platform-only Data > Six Dimension Data Center and six independent page permissions.
INSERT INTO `eb_system_menus`
(`pid`,`type`,`icon`,`menu_name`,`module`,`controller`,`action`,`api_url`,`methods`,`params`,`sort`,`is_show`,`is_show_path`,`access`,`menu_path`,`path`,`auth_type`,`header`,`is_header`,`unique_auth`,`is_del`)
SELECT data_menu.id,1,'ios-analytics-outline','六维数据中心','admin','','','','[]','[]',4,1,0,1,
       '/report/six-dimension-center',CAST(data_menu.id AS CHAR),1,'',0,'admin-report-six-dimension',0
FROM eb_system_menus data_menu
WHERE data_menu.unique_auth='admin-report' AND data_menu.type=1 AND data_menu.is_del=0
  AND NOT EXISTS (SELECT 1 FROM eb_system_menus m WHERE m.unique_auth='admin-report-six-dimension' AND m.is_del=0);

INSERT INTO `eb_system_menus`
(`pid`,`type`,`icon`,`menu_name`,`module`,`controller`,`action`,`api_url`,`methods`,`params`,`sort`,`is_show`,`is_show_path`,`access`,`menu_path`,`path`,`auth_type`,`header`,`is_header`,`unique_auth`,`is_del`)
SELECT parent_menu.id,1,'ios-stats-outline',r.menu_name,'admin','','','','[]','[]',10-r.sort_order,1,0,1,
       CONCAT('/report/six-dimension-center/',r.report_code),CONCAT(data_menu.id,'/',parent_menu.id),1,'',0,
       CONCAT('admin-report-six-dimension-',r.report_code),0
FROM (
  SELECT 1 AS sort_order,'six_dimension_item_deal_analysis' AS report_code,'品项成交分析表' AS menu_name UNION ALL
  SELECT 2,'six_dimension_cash_consumption_analysis','现金消费分析表' UNION ALL
  SELECT 3,'six_dimension_consumption_refund_detail','消耗及退款明细' UNION ALL
  SELECT 4,'six_dimension_performance_deal','业绩成交表' UNION ALL
  SELECT 5,'six_dimension_performance_distribution','业绩分布表' UNION ALL
  SELECT 6,'six_dimension_performance_market_distribution','业绩市场分布表'
) r
JOIN eb_system_menus parent_menu ON parent_menu.unique_auth='admin-report-six-dimension' AND parent_menu.type=1 AND parent_menu.is_del=0
JOIN eb_system_menus data_menu ON data_menu.id=parent_menu.pid AND data_menu.unique_auth='admin-report' AND data_menu.is_del=0
WHERE NOT EXISTS (
  SELECT 1 FROM eb_system_menus m
  WHERE m.unique_auth=CONCAT('admin-report-six-dimension-',r.report_code) AND m.is_del=0
);

-- Consumption tiers remain a dedicated platform setting and are not exposed to the store client.
INSERT INTO `eb_system_menus`
(`pid`,`type`,`icon`,`menu_name`,`module`,`controller`,`action`,`api_url`,`methods`,`params`,`sort`,`is_show`,`is_show_path`,`access`,`menu_path`,`path`,`auth_type`,`header`,`is_header`,`unique_auth`,`is_del`)
SELECT setting_menu.id,1,'','消费分级设置','admin','','','','[]','[]',1,1,0,1,
       '/admin/setting/shop/six-dimension-consumption-tier',
       IF(setting_menu.path IS NULL OR setting_menu.path='',CAST(setting_menu.id AS CHAR),CONCAT(setting_menu.path,'/',setting_menu.id)),1,'',0,
       'setting-shop-six-dimension-consumption-tier',0
FROM eb_system_menus setting_menu
WHERE setting_menu.unique_auth='admin-setting-shop' AND setting_menu.type=1 AND setting_menu.is_del=0
  AND NOT EXISTS (
    SELECT 1 FROM eb_system_menus m
    WHERE m.unique_auth='setting-shop-six-dimension-consumption-tier' AND m.is_del=0
  );

INSERT INTO `eb_database_upgrade_log`
(`upgrade_key`,`title`,`task_file`,`sql_checksum`,`code_version`,`executed_at`,`executed_by`,`result_note`)
SELECT '20260816-002-phase-three-six-dimension-report-foundation','第三阶段六维报表数据底座',
       '2026-08-16-第三阶段六维报表数据底座/02-正式升级.sql','','',NOW(),'codex-local',
       'six report dimensions, member and card-item projections, tiers and platform-only permissions'
FROM DUAL
WHERE NOT EXISTS (
  SELECT 1 FROM eb_database_upgrade_log
  WHERE upgrade_key='20260816-002-phase-three-six-dimension-report-foundation'
);

SELECT 'APPLY_OK' AS apply_result;
