SET NAMES utf8mb4;

-- 客户分析统一挂到“会员”一级菜单下的“看板”二级菜单；全部语句幂等。
INSERT INTO eb_system_menus
  (pid,type,icon,menu_name,module,controller,action,api_url,methods,params,sort,is_show,is_show_path,access,menu_path,path,auth_type,header,is_header,unique_auth,is_del)
SELECT id,1,'ios-analytics-outline','看板','admin','report','customer-analytics','','','[]',120,1,0,1,
       '/admin/user/customer-analytics',
       CONCAT(COALESCE(NULLIF(path,''),''),CASE WHEN COALESCE(NULLIF(path,''),'') = '' THEN '' ELSE '/' END,id),
       1,'crm',0,'admin-customer-analytics-dashboard',0
FROM eb_system_menus p
WHERE p.unique_auth='admin-crm' AND p.is_del=0
  AND NOT EXISTS (SELECT 1 FROM eb_system_menus e WHERE e.unique_auth='admin-customer-analytics-dashboard' AND e.is_del=0)
LIMIT 1;

INSERT INTO eb_system_menus
  (pid,type,icon,menu_name,module,controller,action,api_url,methods,params,sort,is_show,is_show_path,access,menu_path,path,auth_type,header,is_header,unique_auth,is_del)
SELECT p.id,1,'ios-analytics-outline',v.menu_name,'admin','report','customer-analytics','','','[]',v.sort,1,0,1,v.menu_path,
       CONCAT(COALESCE(NULLIF(p.path,''),''),CASE WHEN COALESCE(NULLIF(p.path,''),'') = '' THEN '' ELSE '/' END,p.id),
       1,'crm',0,v.unique_auth,0
FROM eb_system_menus p
JOIN (
  SELECT '客户概况' AS menu_name, 1 AS sort, '/admin/user/customer-analytics/overview' AS menu_path, 'admin-customer-analytics-customer_overview' AS unique_auth
  UNION ALL SELECT '客户开源分析', 2, '/admin/user/customer-analytics/source-analysis', 'admin-customer-analytics-customer_source_analysis'
  UNION ALL SELECT '到店数据分析', 3, '/admin/user/customer-analytics/visit-analysis', 'admin-customer-analytics-customer_visit_analysis'
  UNION ALL SELECT '门店健康数据分析', 4, '/admin/user/customer-analytics/store-health', 'admin-customer-analytics-customer_store_health'
  UNION ALL SELECT '消费分级分析', 5, '/admin/user/customer-analytics/consumption-tier', 'admin-customer-analytics-customer_consumption_tier'
  UNION ALL SELECT '现金业绩分析', 6, '/admin/user/customer-analytics/cash-performance', 'admin-customer-analytics-customer_cash_performance'
  UNION ALL SELECT '退货业绩分析', 7, '/admin/user/customer-analytics/refund-performance', 'admin-customer-analytics-customer_refund_performance'
  UNION ALL SELECT '客户品相分析', 8, '/admin/user/customer-analytics/item-analysis', 'admin-customer-analytics-customer_item_analysis'
  UNION ALL SELECT '客户未耗分析', 9, '/admin/user/customer-analytics/unconsumed-analysis', 'admin-customer-analytics-customer_unconsumed_analysis'
) v
WHERE p.unique_auth='admin-customer-analytics-dashboard' AND p.is_del=0
  AND NOT EXISTS (SELECT 1 FROM eb_system_menus e WHERE e.unique_auth=v.unique_auth AND e.is_del=0);

-- 早期执行过前端补造版本的环境，统一修正名称、父级和路径。
UPDATE eb_system_menus SET menu_name='看板', menu_path='/admin/user/customer-analytics', header='crm', is_header=0, is_show=1
WHERE unique_auth='admin-customer-analytics-dashboard' AND is_del=0;

-- 继承已有会员菜单权限；超级管理员由后端与前端统一放行。
-- 使用显式菜单 ID 追加，避免 UPDATE JOIN 多子菜单时只写入其中一个 ID。
UPDATE eb_system_role r
SET r.rules=IF(
  FIND_IN_SET((SELECT id FROM eb_system_menus WHERE unique_auth='admin-customer-analytics-dashboard' AND is_del=0 LIMIT 1),r.rules),
  r.rules,
  CONCAT_WS(',',NULLIF(r.rules,''),(SELECT id FROM eb_system_menus WHERE unique_auth='admin-customer-analytics-dashboard' AND is_del=0 LIMIT 1))
)
WHERE r.status=1 AND (r.level=0 OR FIND_IN_SET((SELECT id FROM eb_system_menus WHERE unique_auth='admin-crm' AND is_del=0 LIMIT 1),r.rules));

UPDATE eb_system_role r
SET r.rules=CONCAT_WS(',',NULLIF(r.rules,''),
  (SELECT id FROM eb_system_menus WHERE unique_auth='admin-customer-analytics-customer_overview' AND is_del=0 LIMIT 1),
  (SELECT id FROM eb_system_menus WHERE unique_auth='admin-customer-analytics-customer_source_analysis' AND is_del=0 LIMIT 1),
  (SELECT id FROM eb_system_menus WHERE unique_auth='admin-customer-analytics-customer_visit_analysis' AND is_del=0 LIMIT 1),
  (SELECT id FROM eb_system_menus WHERE unique_auth='admin-customer-analytics-customer_store_health' AND is_del=0 LIMIT 1),
  (SELECT id FROM eb_system_menus WHERE unique_auth='admin-customer-analytics-customer_consumption_tier' AND is_del=0 LIMIT 1),
  (SELECT id FROM eb_system_menus WHERE unique_auth='admin-customer-analytics-customer_cash_performance' AND is_del=0 LIMIT 1),
  (SELECT id FROM eb_system_menus WHERE unique_auth='admin-customer-analytics-customer_refund_performance' AND is_del=0 LIMIT 1),
  (SELECT id FROM eb_system_menus WHERE unique_auth='admin-customer-analytics-customer_item_analysis' AND is_del=0 LIMIT 1),
  (SELECT id FROM eb_system_menus WHERE unique_auth='admin-customer-analytics-customer_unconsumed_analysis' AND is_del=0 LIMIT 1)
)
WHERE r.status=1 AND (r.level=0 OR FIND_IN_SET((SELECT id FROM eb_system_menus WHERE unique_auth='admin-crm' AND is_del=0 LIMIT 1),r.rules));
