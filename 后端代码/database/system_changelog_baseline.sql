-- upgrade_key: 20260715-009-system-changelog
-- 02-正式升级.sql（可重复执行）

SET @db := DATABASE();

-- ========== 1) 主表 ==========
CREATE TABLE IF NOT EXISTS `eb_system_changelog` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `release_key` varchar(64) DEFAULT NULL COMMENT '部署批次幂等键，空表示草稿未绑定',
  `title` varchar(150) NOT NULL DEFAULT '' COMMENT '标题',
  `version` varchar(50) NOT NULL DEFAULT '' COMMENT '版本号',
  `summary` varchar(500) NOT NULL DEFAULT '' COMMENT '摘要',
  `publish_date` varchar(10) NOT NULL DEFAULT '' COMMENT '发布归属日期YYYY-MM-DD',
  `publish_time` int(11) NOT NULL DEFAULT '0' COMMENT '生效/发布时间',
  `status` tinyint(1) NOT NULL DEFAULT '0' COMMENT '0草稿1待发布2已发布3已下架',
  `platforms` varchar(100) NOT NULL DEFAULT '' COMMENT '展示端mini,admin,store,cashier',
  `is_important` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否重要',
  `is_popup` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否首页提示',
  `sort` int(11) NOT NULL DEFAULT '0' COMMENT '同日排序越大越前',
  `internal_note` varchar(500) NOT NULL DEFAULT '' COMMENT '内部备注',
  `created_by` bigint(20) unsigned NOT NULL DEFAULT '0',
  `updated_by` bigint(20) unsigned NOT NULL DEFAULT '0',
  `published_by` bigint(20) unsigned NOT NULL DEFAULT '0',
  `create_time` int(11) NOT NULL DEFAULT '0',
  `update_time` int(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_release_key` (`release_key`),
  KEY `idx_status_pub` (`status`,`publish_time`,`publish_date`,`sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='系统更新日志';

-- ========== 2) 明细表 ==========
CREATE TABLE IF NOT EXISTS `eb_system_changelog_item` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `changelog_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `change_type` varchar(20) NOT NULL DEFAULT '' COMMENT 'add|optimize|fix|adjust|offline',
  `module_name` varchar(50) NOT NULL DEFAULT '' COMMENT '所属模块',
  `content` varchar(500) NOT NULL DEFAULT '' COMMENT '产品语言内容',
  `sort` int(11) NOT NULL DEFAULT '0',
  `create_time` int(11) NOT NULL DEFAULT '0',
  `update_time` int(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_changelog_sort` (`changelog_id`,`sort`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='系统更新日志明细';

-- ========== 3) 审计表 ==========
CREATE TABLE IF NOT EXISTS `eb_system_changelog_audit` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `changelog_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `action` varchar(30) NOT NULL DEFAULT '' COMMENT 'create|update|publish|offline|delete|copy|upsert',
  `operator_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `operator_name` varchar(64) NOT NULL DEFAULT '',
  `before_summary` varchar(1000) NOT NULL DEFAULT '',
  `after_summary` varchar(1000) NOT NULL DEFAULT '',
  `reason` varchar(255) NOT NULL DEFAULT '' COMMENT '下架等原因',
  `create_time` int(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_changelog_id` (`changelog_id`),
  KEY `idx_create_time` (`create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='系统更新日志审计';

-- ========== 4) 平台菜单：挂在「系统设置」(/admin/setting/base) 下 ==========
SET NAMES utf8mb4;

-- 修正已存在但 menu_name 乱码的菜单
UPDATE `eb_system_menus` SET `menu_name` = '更新日志' WHERE `unique_auth` = 'setting-system-changelog' AND `is_del` = 0;
UPDATE `eb_system_menus` SET `menu_name` = '新增更新日志' WHERE `unique_auth` = 'setting-system-changelog-add' AND `is_del` = 0;
UPDATE `eb_system_menus` SET `menu_name` = '编辑更新日志' WHERE `unique_auth` = 'setting-system-changelog-edit' AND `is_del` = 0;
UPDATE `eb_system_menus` SET `menu_name` = '发布更新日志' WHERE `unique_auth` = 'setting-system-changelog-publish' AND `is_del` = 0;
UPDATE `eb_system_menus` SET `menu_name` = '下架更新日志' WHERE `unique_auth` = 'setting-system-changelog-offline' AND `is_del` = 0;
UPDATE `eb_system_menus` SET `menu_name` = '删除更新日志草稿' WHERE `unique_auth` = 'setting-system-changelog-delete' AND `is_del` = 0;
UPDATE `eb_system_menus` SET `menu_name` = '复制更新日志' WHERE `unique_auth` = 'setting-system-changelog-copy' AND `is_del` = 0;
UPDATE `eb_system_menus` SET `menu_name` = '更新日志' WHERE `unique_auth` = 'store-set-changelog' AND `is_del` = 0;

INSERT INTO `eb_system_menus`
(`pid`, `type`, `icon`, `menu_name`, `module`, `controller`, `action`, `api_url`, `methods`, `params`, `sort`, `is_show`, `is_show_path`, `access`, `menu_path`, `path`, `auth_type`, `header`, `is_header`, `unique_auth`, `is_del`)
SELECT p.`id`, 1, '', '更新日志', 'admin', '', '', '', '', '[]', 8, 1, 0, 1,
  '/admin/setting/changelog',
  CASE WHEN IFNULL(p.`path`, '') = '' THEN CAST(p.`id` AS CHAR) ELSE CONCAT(p.`path`, '/', p.`id`) END,
  1, 'setting', 1, 'setting-system-changelog', 0
FROM `eb_system_menus` p
WHERE p.`menu_path` = '/admin/setting/base' AND p.`type` = 1 AND p.`is_del` = 0
  AND NOT EXISTS (SELECT 1 FROM `eb_system_menus` x WHERE x.`unique_auth` = 'setting-system-changelog' AND x.`is_del` = 0 LIMIT 1)
LIMIT 1;

-- 若系统设置入口不存在，回退挂到 admin-setting
INSERT INTO `eb_system_menus`
(`pid`, `type`, `icon`, `menu_name`, `module`, `controller`, `action`, `api_url`, `methods`, `params`, `sort`, `is_show`, `is_show_path`, `access`, `menu_path`, `path`, `auth_type`, `header`, `is_header`, `unique_auth`, `is_del`)
SELECT p.`id`, 1, '', '更新日志', 'admin', '', '', '', '', '[]', 8, 1, 0, 1,
  '/admin/setting/changelog',
  CASE WHEN IFNULL(p.`path`, '') = '' THEN CAST(p.`id` AS CHAR) ELSE CONCAT(p.`path`, '/', p.`id`) END,
  1, 'setting', 1, 'setting-system-changelog', 0
FROM `eb_system_menus` p
WHERE p.`unique_auth` = 'admin-setting' AND p.`is_del` = 0
  AND NOT EXISTS (SELECT 1 FROM `eb_system_menus` x WHERE x.`unique_auth` = 'setting-system-changelog' AND x.`is_del` = 0 LIMIT 1)
LIMIT 1;

-- 按钮权限
INSERT INTO `eb_system_menus`
(`pid`, `type`, `icon`, `menu_name`, `module`, `controller`, `action`, `api_url`, `methods`, `params`, `sort`, `is_show`, `is_show_path`, `access`, `menu_path`, `path`, `auth_type`, `header`, `is_header`, `unique_auth`, `is_del`)
SELECT p.`id`, 1, '', '新增更新日志', 'admin', '', '', 'setting/changelog', 'POST', '[]', 0, 0, 0, 1, '',
  CONCAT(CASE WHEN IFNULL(p.`path`, '') = '' THEN CAST(p.`id` AS CHAR) ELSE p.`path` END, '/', p.`id`),
  2, '', 0, 'setting-system-changelog-add', 0
FROM `eb_system_menus` p
WHERE p.`unique_auth` = 'setting-system-changelog' AND p.`is_del` = 0
  AND NOT EXISTS (SELECT 1 FROM `eb_system_menus` x WHERE x.`unique_auth` = 'setting-system-changelog-add' AND x.`is_del` = 0 LIMIT 1)
LIMIT 1;

INSERT INTO `eb_system_menus`
(`pid`, `type`, `icon`, `menu_name`, `module`, `controller`, `action`, `api_url`, `methods`, `params`, `sort`, `is_show`, `is_show_path`, `access`, `menu_path`, `path`, `auth_type`, `header`, `is_header`, `unique_auth`, `is_del`)
SELECT p.`id`, 1, '', '编辑更新日志', 'admin', '', '', 'setting/changelog/<id>', 'PUT', '[]', 0, 0, 0, 1, '', CONCAT(CASE WHEN IFNULL(p.`path`, '') = '' THEN CAST(p.`id` AS CHAR) ELSE p.`path` END, '/', p.`id`), 2, '', 0, 'setting-system-changelog-edit', 0
FROM `eb_system_menus` p
WHERE p.`unique_auth` = 'setting-system-changelog' AND p.`is_del` = 0
  AND NOT EXISTS (SELECT 1 FROM `eb_system_menus` x WHERE x.`unique_auth` = 'setting-system-changelog-edit' AND x.`is_del` = 0 LIMIT 1)
LIMIT 1;

INSERT INTO `eb_system_menus`
(`pid`, `type`, `icon`, `menu_name`, `module`, `controller`, `action`, `api_url`, `methods`, `params`, `sort`, `is_show`, `is_show_path`, `access`, `menu_path`, `path`, `auth_type`, `header`, `is_header`, `unique_auth`, `is_del`)
SELECT p.`id`, 1, '', '发布更新日志', 'admin', '', '', 'setting/changelog/publish/<id>', 'PUT', '[]', 0, 0, 0, 1, '', CONCAT(CASE WHEN IFNULL(p.`path`, '') = '' THEN CAST(p.`id` AS CHAR) ELSE p.`path` END, '/', p.`id`), 2, '', 0, 'setting-system-changelog-publish', 0
FROM `eb_system_menus` p
WHERE p.`unique_auth` = 'setting-system-changelog' AND p.`is_del` = 0
  AND NOT EXISTS (SELECT 1 FROM `eb_system_menus` x WHERE x.`unique_auth` = 'setting-system-changelog-publish' AND x.`is_del` = 0 LIMIT 1)
LIMIT 1;

INSERT INTO `eb_system_menus`
(`pid`, `type`, `icon`, `menu_name`, `module`, `controller`, `action`, `api_url`, `methods`, `params`, `sort`, `is_show`, `is_show_path`, `access`, `menu_path`, `path`, `auth_type`, `header`, `is_header`, `unique_auth`, `is_del`)
SELECT p.`id`, 1, '', '下架更新日志', 'admin', '', '', 'setting/changelog/offline/<id>', 'PUT', '[]', 0, 0, 0, 1, '', CONCAT(CASE WHEN IFNULL(p.`path`, '') = '' THEN CAST(p.`id` AS CHAR) ELSE p.`path` END, '/', p.`id`), 2, '', 0, 'setting-system-changelog-offline', 0
FROM `eb_system_menus` p
WHERE p.`unique_auth` = 'setting-system-changelog' AND p.`is_del` = 0
  AND NOT EXISTS (SELECT 1 FROM `eb_system_menus` x WHERE x.`unique_auth` = 'setting-system-changelog-offline' AND x.`is_del` = 0 LIMIT 1)
LIMIT 1;

INSERT INTO `eb_system_menus`
(`pid`, `type`, `icon`, `menu_name`, `module`, `controller`, `action`, `api_url`, `methods`, `params`, `sort`, `is_show`, `is_show_path`, `access`, `menu_path`, `path`, `auth_type`, `header`, `is_header`, `unique_auth`, `is_del`)
SELECT p.`id`, 1, '', '删除更新日志草稿', 'admin', '', '', 'setting/changelog/<id>', 'DELETE', '[]', 0, 0, 0, 1, '', CONCAT(CASE WHEN IFNULL(p.`path`, '') = '' THEN CAST(p.`id` AS CHAR) ELSE p.`path` END, '/', p.`id`), 2, '', 0, 'setting-system-changelog-delete', 0
FROM `eb_system_menus` p
WHERE p.`unique_auth` = 'setting-system-changelog' AND p.`is_del` = 0
  AND NOT EXISTS (SELECT 1 FROM `eb_system_menus` x WHERE x.`unique_auth` = 'setting-system-changelog-delete' AND x.`is_del` = 0 LIMIT 1)
LIMIT 1;

INSERT INTO `eb_system_menus`
(`pid`, `type`, `icon`, `menu_name`, `module`, `controller`, `action`, `api_url`, `methods`, `params`, `sort`, `is_show`, `is_show_path`, `access`, `menu_path`, `path`, `auth_type`, `header`, `is_header`, `unique_auth`, `is_del`)
SELECT p.`id`, 1, '', '复制更新日志', 'admin', '', '', 'setting/changelog/copy/<id>', 'POST', '[]', 0, 0, 0, 1, '', CONCAT(CASE WHEN IFNULL(p.`path`, '') = '' THEN CAST(p.`id` AS CHAR) ELSE p.`path` END, '/', p.`id`), 2, '', 0, 'setting-system-changelog-copy', 0
FROM `eb_system_menus` p
WHERE p.`unique_auth` = 'setting-system-changelog' AND p.`is_del` = 0
  AND NOT EXISTS (SELECT 1 FROM `eb_system_menus` x WHERE x.`unique_auth` = 'setting-system-changelog-copy' AND x.`is_del` = 0 LIMIT 1)
LIMIT 1;

-- ========== 5) 门店只读菜单 ==========
INSERT INTO `eb_system_menus`
(`pid`, `type`, `icon`, `menu_name`, `module`, `controller`, `action`, `api_url`, `methods`, `params`, `sort`, `is_show`, `is_show_path`, `access`, `menu_path`, `path`, `auth_type`, `header`, `is_header`, `unique_auth`, `is_del`)
SELECT p.`id`, 2, 'ios-paper', '更新日志', 'admin', '', '', '', '', '[]', 5, 1, 0, 1,
  '/store/set/changelog',
  CASE WHEN IFNULL(p.`path`, '') = '' THEN CAST(p.`id` AS CHAR) ELSE CONCAT(p.`path`, '/', p.`id`) END,
  1, 'set', 0, 'store-set-changelog', 0
FROM `eb_system_menus` p
WHERE p.`unique_auth` = 'store-set' AND p.`is_del` = 0
  AND NOT EXISTS (SELECT 1 FROM `eb_system_menus` x WHERE x.`unique_auth` = 'store-set-changelog' AND x.`is_del` = 0 LIMIT 1)
LIMIT 1;
