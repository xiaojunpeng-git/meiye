-- 区域管理人员：关联架构区域、管理员管辖门店（多对多）
ALTER TABLE `eb_system_region_agent`
  ADD COLUMN `manage_region_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '区域架构ID' AFTER `pid`,
  ADD KEY `idx_manage_region_id` (`manage_region_id`,`is_del`);

CREATE TABLE IF NOT EXISTS `eb_system_region_agent_store` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `agent_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '区域管理人员ID',
  `store_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '门店ID',
  `add_time` int(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_agent_store` (`agent_id`,`store_id`),
  KEY `idx_store_id` (`store_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='区域管理人员管辖门店';

-- 已有管理人员：保存/编辑一次后会写入 manage_region_id；或按 name 与架构表手工 UPDATE
