-- upgrade_key: 20260806-002-cashier-v3-business-source-accounting-config
-- MySQL 5.6 compatible. Configuration is global inside each independent customer database.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `eb_cashier_v3_business_source` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `parent_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `name` varchar(64) NOT NULL,
  `status` tinyint(1) unsigned NOT NULL DEFAULT '1',
  `sort` smallint(5) unsigned NOT NULL DEFAULT '0',
  `require_secondary` tinyint(1) unsigned NOT NULL DEFAULT '0',
  `version` bigint(20) unsigned NOT NULL DEFAULT '1',
  `created_at` bigint(20) unsigned NOT NULL,
  `updated_at` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_parent_name` (`parent_id`,`name`),
  KEY `idx_parent_status_sort` (`parent_id`,`status`,`sort`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='cashier v3 authoritative two-level business sources';

CREATE TABLE IF NOT EXISTS `eb_cashier_v3_payment_method_config` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `default_name` varchar(64) NOT NULL,
  `display_name` varchar(64) NOT NULL,
  `status` tinyint(1) unsigned NOT NULL DEFAULT '1',
  `sort` smallint(5) unsigned NOT NULL DEFAULT '0',
  `version` bigint(20) unsigned NOT NULL DEFAULT '1',
  `created_at` bigint(20) unsigned NOT NULL,
  `updated_at` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_code` (`code`),
  KEY `idx_status_sort` (`status`,`sort`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='cashier v3 canonical accounting method display configuration';

CREATE TABLE IF NOT EXISTS `eb_cashier_v3_business_config_audit` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `action` varchar(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `entity_type` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `entity_key` varchar(64) NOT NULL,
  `idempotency_key` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `operator_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `before_snapshot_json` mediumtext NOT NULL,
  `after_snapshot_json` mediumtext NOT NULL,
  `occurred_at` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_idempotency_key` (`idempotency_key`),
  KEY `idx_entity_time` (`entity_type`,`entity_key`,`occurred_at`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='cashier v3 business config immutable audit';

-- Preserve the existing source catalog as level-one initial configuration.
INSERT INTO `eb_cashier_v3_business_source`
  (`parent_id`,`name`,`status`,`sort`,`require_secondary`,`version`,`created_at`,`updated_at`)
SELECT 0,`legacy`.`source_name`,MAX(IF(`legacy`.`status`=1,1,0)),LEAST(MIN(`legacy`.`id`),65535),0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM (
  SELECT `id`,LEFT(TRIM(`name`),64) AS `source_name`,`status`
  FROM `eb_cash_source`
) AS `legacy`
WHERE `legacy`.`source_name`<>''
  AND NOT EXISTS (
    SELECT 1 FROM `eb_cashier_v3_business_source` AS `current`
    WHERE `current`.`parent_id`=0 AND `current`.`name`=`legacy`.`source_name`
  )
GROUP BY `legacy`.`source_name`;

INSERT INTO `eb_cashier_v3_payment_method_config`
  (`code`,`default_name`,`display_name`,`status`,`sort`,`version`,`created_at`,`updated_at`)
VALUES
  ('unionpay','银联','银联',1,10,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
  ('wechat','微信','微信',1,20,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
  ('alipay','支付宝','支付宝',1,30,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
  ('dianping_voucher','大众验券','大众验券',1,40,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
  ('douyin_voucher','抖音验券','抖音验券',1,50,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
  ('partner_collection','合作方收款','合作方收款',1,60,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
  ('other_collection','其他收款','其他收款',1,70,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `default_name`=VALUES(`default_name`);

SET @business_config_parent_id := COALESCE((
  SELECT id FROM eb_system_menus
  WHERE type=1 AND is_del=0 AND unique_auth='admin-setting-shop'
  ORDER BY id LIMIT 1
),0);

INSERT INTO `eb_system_menus`
  (`pid`,`type`,`icon`,`menu_name`,`module`,`controller`,`action`,`api_url`,`methods`,`params`,`sort`,`is_show`,`is_show_path`,`access`,`menu_path`,`path`,`auth_type`,`header`,`is_header`,`unique_auth`,`is_del`)
SELECT @business_config_parent_id,1,'','读取收银来源设置','','','','product/business-config/sources','GET','[]',0,0,0,1,'','',2,'',0,'cashier-v3-business-config-manage',0
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM eb_system_menus WHERE type=1 AND is_del=0 AND api_url='product/business-config/sources' AND methods='GET');
INSERT INTO `eb_system_menus` (`pid`,`type`,`icon`,`menu_name`,`module`,`controller`,`action`,`api_url`,`methods`,`params`,`sort`,`is_show`,`is_show_path`,`access`,`menu_path`,`path`,`auth_type`,`header`,`is_header`,`unique_auth`,`is_del`)
SELECT @business_config_parent_id,1,'','新增收银来源','','','','product/business-config/sources','POST','[]',0,0,0,1,'','',2,'',0,'cashier-v3-business-config-manage',0
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM eb_system_menus WHERE type=1 AND is_del=0 AND api_url='product/business-config/sources' AND methods='POST');
INSERT INTO `eb_system_menus` (`pid`,`type`,`icon`,`menu_name`,`module`,`controller`,`action`,`api_url`,`methods`,`params`,`sort`,`is_show`,`is_show_path`,`access`,`menu_path`,`path`,`auth_type`,`header`,`is_header`,`unique_auth`,`is_del`)
SELECT @business_config_parent_id,1,'','修改收银来源','','','','product/business-config/sources/:id','PUT','[]',0,0,0,1,'','',2,'',0,'cashier-v3-business-config-manage',0
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM eb_system_menus WHERE type=1 AND is_del=0 AND api_url='product/business-config/sources/:id' AND methods='PUT');
INSERT INTO `eb_system_menus` (`pid`,`type`,`icon`,`menu_name`,`module`,`controller`,`action`,`api_url`,`methods`,`params`,`sort`,`is_show`,`is_show_path`,`access`,`menu_path`,`path`,`auth_type`,`header`,`is_header`,`unique_auth`,`is_del`)
SELECT @business_config_parent_id,1,'','读取记账设置','','','','product/business-config/accounting-methods','GET','[]',0,0,0,1,'','',2,'',0,'cashier-v3-business-config-manage',0
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM eb_system_menus WHERE type=1 AND is_del=0 AND api_url='product/business-config/accounting-methods' AND methods='GET');
INSERT INTO `eb_system_menus` (`pid`,`type`,`icon`,`menu_name`,`module`,`controller`,`action`,`api_url`,`methods`,`params`,`sort`,`is_show`,`is_show_path`,`access`,`menu_path`,`path`,`auth_type`,`header`,`is_header`,`unique_auth`,`is_del`)
SELECT @business_config_parent_id,1,'','修改记账设置','','','','product/business-config/accounting-methods/:code','PUT','[]',0,0,0,1,'','',2,'',0,'cashier-v3-business-config-manage',0
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM eb_system_menus WHERE type=1 AND is_del=0 AND api_url='product/business-config/accounting-methods/:code' AND methods='PUT');
INSERT INTO `eb_system_menus` (`pid`,`type`,`icon`,`menu_name`,`module`,`controller`,`action`,`api_url`,`methods`,`params`,`sort`,`is_show`,`is_show_path`,`access`,`menu_path`,`path`,`auth_type`,`header`,`is_header`,`unique_auth`,`is_del`)
SELECT @business_config_parent_id,1,'','恢复记账默认名称','','','','product/business-config/accounting-methods/restore-defaults','POST','[]',0,0,0,1,'','',2,'',0,'cashier-v3-business-config-manage',0
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM eb_system_menus WHERE type=1 AND is_del=0 AND api_url='product/business-config/accounting-methods/restore-defaults' AND methods='POST');

-- Roles which already had the 商品设置 page keep access to its newly split API capabilities.
SET @business_config_api_ids := (
  SELECT GROUP_CONCAT(id ORDER BY id SEPARATOR ',') FROM eb_system_menus
  WHERE type=1 AND is_del=0 AND unique_auth='cashier-v3-business-config-manage'
);
SET @business_config_first_api_id := (
  SELECT MIN(id) FROM eb_system_menus
  WHERE type=1 AND is_del=0 AND unique_auth='cashier-v3-business-config-manage'
);
UPDATE eb_system_role
SET rules=CONCAT(
  TRIM(BOTH ',' FROM COALESCE(rules,'')),
  IF(TRIM(BOTH ',' FROM COALESCE(rules,''))='','',','),
  @business_config_api_ids
)
WHERE status=1
  AND @business_config_parent_id>0
  AND @business_config_first_api_id IS NOT NULL
  AND FIND_IN_SET(@business_config_parent_id,rules)>0
  AND FIND_IN_SET(@business_config_first_api_id,rules)=0;

SELECT 'APPLY_OK' AS apply_result;
