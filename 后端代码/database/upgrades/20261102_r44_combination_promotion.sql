-- 第44轮：本次分享归属只在新订单创建事务冻结，不回填历史推广人、不改员工表。
CREATE TABLE IF NOT EXISTS `eb_combination_promotion_order` (
  `order_id` bigint unsigned NOT NULL,
  `activity_id` bigint unsigned NOT NULL,
  `share_uid` bigint unsigned NOT NULL DEFAULT 0,
  `store_id` bigint unsigned NOT NULL DEFAULT 0,
  `promoter_name` varchar(100) NOT NULL DEFAULT '',
  `store_name` varchar(100) NOT NULL DEFAULT '',
  `activity_name` varchar(255) NOT NULL DEFAULT '',
  `attribution_reason` varchar(40) NOT NULL DEFAULT 'no_share',
  `created_at` int unsigned NOT NULL,
  PRIMARY KEY (`order_id`),
  KEY `idx_promoter_activity_order` (`share_uid`,`activity_id`,`order_id`),
  KEY `idx_store_activity_order` (`store_id`,`activity_id`,`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='拼团订单创建时的本次分享归属快照';
CREATE TABLE IF NOT EXISTS `eb_combination_dashboard_setting` (
  `admin_id` bigint unsigned NOT NULL,
  `activity_ids` text NOT NULL,
  `updated_at` int unsigned NOT NULL,
  PRIMARY KEY (`admin_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='拼团大屏活动选择，不扩大管理员数据范围';

-- 以订单为单位查询真实名额；日期候选索引让大屏刷新不扫描全部历史拼团。
SET @r44_index_sql = IF((SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='eb_store_pink' AND index_name='idx_promotion_order_seat')=0,
 'ALTER TABLE eb_store_pink ADD INDEX idx_promotion_order_seat (order_id_key,is_virtual,uid,k_id)', 'SELECT 1');
PREPARE r44_index_stmt FROM @r44_index_sql;
EXECUTE r44_index_stmt;
DEALLOCATE PREPARE r44_index_stmt;
SET @r44_index_sql = IF((SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='eb_store_order' AND index_name='idx_combination_paid_date')=0,
 'ALTER TABLE eb_store_order ADD INDEX idx_combination_paid_date (type,paid,pay_time,id)', 'SELECT 1');
PREPARE r44_index_stmt FROM @r44_index_sql;
EXECUTE r44_index_stmt;
DEALLOCATE PREPARE r44_index_stmt;

-- 只注册新功能菜单，沿用现有角色勾选授权，不自动给其他账号扩权。
INSERT INTO eb_system_menus
(`pid`,`type`,`icon`,`menu_name`,`module`,`controller`,`action`,`api_url`,`methods`,`params`,`sort`,`is_show`,`is_show_path`,`access`,`menu_path`,`path`,`auth_type`,`header`,`is_header`,`unique_auth`,`is_del`)
SELECT p.id,1,'','拼团大屏','admin','marketing.store_combination','dashboard','','[]','[]',1,1,0,1,
 '/marketing/store_combination/dashboard',CONCAT_WS('/',NULLIF(p.path,''),p.id),1,'marketing',0,'marketing-store_combination-dashboard',0
FROM eb_system_menus p
WHERE p.unique_auth='marketing-store_combination-index' AND p.is_del=0
 AND NOT EXISTS (SELECT 1 FROM eb_system_menus e WHERE e.unique_auth='marketing-store_combination-dashboard' AND e.is_del=0)
 AND (SELECT COUNT(*) FROM eb_system_menus c WHERE c.unique_auth='marketing-store_combination-index' AND c.is_del=0)=1;
