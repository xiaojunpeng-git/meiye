-- 区域架构表（左侧区域树，与区域代理商/管理员表分离）
CREATE TABLE IF NOT EXISTS `eb_system_region_manage` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `pid` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '上级区域ID',
  `name` varchar(64) NOT NULL DEFAULT '' COMMENT '区域名称',
  `sort` smallint(5) NOT NULL DEFAULT '0' COMMENT '排序',
  `is_del` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否删除',
  `add_time` int(11) NOT NULL DEFAULT '0' COMMENT '添加时间',
  PRIMARY KEY (`id`),
  KEY `idx_pid` (`pid`,`is_del`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='区域架构表';
