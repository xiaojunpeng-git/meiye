-- 组织架构（由区域管理迁移升级，正式上线对账通过后可删除旧 region 表）
CREATE TABLE IF NOT EXISTS `eb_organization` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `pid` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '上级组织ID',
  `name` varchar(64) NOT NULL DEFAULT '' COMMENT '组织名称',
  `sort` smallint(5) NOT NULL DEFAULT '0' COMMENT '排序',
  `legacy_manage_region_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '迁移自 system_region_manage.id',
  `is_del` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否删除',
  `add_time` int(11) NOT NULL DEFAULT '0',
  `update_time` int(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_pid` (`pid`,`is_del`),
  KEY `idx_legacy_manage_region` (`legacy_manage_region_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='组织架构表';

CREATE TABLE IF NOT EXISTS `eb_organization_store` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `org_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '组织ID',
  `store_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '门店ID',
  `add_time` int(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_store_id` (`store_id`),
  KEY `idx_org_id` (`org_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='门店组织归属（一店一组织）';

CREATE TABLE IF NOT EXISTS `eb_organization_admin` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `org_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '组织ID',
  `name` varchar(64) NOT NULL DEFAULT '' COMMENT '管理员姓名',
  `phone` varchar(32) NOT NULL DEFAULT '' COMMENT '联系电话',
  `admin_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '后台账号 system_admin.id',
  `uid` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '商城用户 uid',
  `legacy_agent_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '迁移自 system_region_agent.id',
  `is_del` tinyint(1) NOT NULL DEFAULT '0',
  `add_time` int(11) NOT NULL DEFAULT '0',
  `update_time` int(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_org_admin` (`org_id`,`is_del`),
  KEY `idx_legacy_agent` (`legacy_agent_id`),
  KEY `idx_uid` (`uid`),
  KEY `idx_phone` (`phone`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='组织管理员';

CREATE TABLE IF NOT EXISTS `eb_organization_admin_store_exclude` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `org_admin_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '组织管理员ID',
  `store_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '排除门店ID',
  `add_time` int(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_admin_store` (`org_admin_id`,`store_id`),
  KEY `idx_store_id` (`store_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='组织管理员排除门店';

CREATE TABLE IF NOT EXISTS `eb_organization_change_log` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `org_id` int(10) unsigned NOT NULL DEFAULT '0',
  `action` varchar(32) NOT NULL DEFAULT '' COMMENT '动作',
  `target_type` varchar(32) NOT NULL DEFAULT '' COMMENT '对象类型',
  `target_id` int(10) unsigned NOT NULL DEFAULT '0',
  `before_data` text COMMENT '变更前',
  `after_data` text COMMENT '变更后',
  `remark` varchar(255) NOT NULL DEFAULT '',
  `operator_id` int(10) unsigned NOT NULL DEFAULT '0',
  `operator_name` varchar(64) NOT NULL DEFAULT '',
  `add_time` int(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_org_time` (`org_id`,`add_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='组织架构操作审计';
