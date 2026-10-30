-- 门店对账单独立授权叶子：只补菜单，不改现有岗位、角色或业务数据。
-- 前置：已有基础报表“门店统计”分组。无此前置时必须由安装检查拒绝，不能挂到其他报表。
INSERT INTO eb_system_menus
(pid,type,icon,menu_name,module,controller,action,api_url,methods,params,sort,is_show,is_show_path,access,menu_path,path,auth_type,header,is_header,unique_auth,is_del)
SELECT p.id,1,'','门店对账单','admin','','','','[]','[]',110,1,0,1,
       '/report/basic-reports/basic_store_reconciliation',CONCAT(p.path,'/',p.id),1,'',0,'admin-report-basic_store_reconciliation',0
FROM eb_system_menus p
WHERE p.unique_auth='admin-report-basic-group-store' AND p.type=1 AND p.is_del=0
AND NOT EXISTS (SELECT 1 FROM eb_system_menus m WHERE m.unique_auth='admin-report-basic_store_reconciliation' AND m.is_del=0);
