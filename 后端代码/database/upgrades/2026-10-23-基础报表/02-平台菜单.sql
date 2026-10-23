-- 只新增独立基础报表菜单及接口权限，不搬动、重命名或删除任何旧报表。
INSERT INTO eb_system_menus
(pid,type,icon,menu_name,module,controller,action,api_url,methods,params,sort,is_show,is_show_path,access,menu_path,path,auth_type,header,is_header,unique_auth,is_del)
SELECT p.id,1,'ios-stats-outline','基础报表','admin','','','','[]','[]',60,1,0,1,
       '/report/basic-reports',CAST(p.id AS CHAR),1,'',0,'admin-report-basic',0
FROM eb_system_menus p WHERE p.unique_auth='admin-report' AND p.type=1 AND p.is_del=0
AND NOT EXISTS (SELECT 1 FROM eb_system_menus m WHERE m.unique_auth='admin-report-basic' AND m.is_del=0);

INSERT INTO eb_system_menus
(pid,type,icon,menu_name,module,controller,action,api_url,methods,params,sort,is_show,is_show_path,access,menu_path,path,auth_type,header,is_header,unique_auth,is_del)
SELECT p.id,1,'','基础报表目录','admin','','','report/basic/catalog','GET','[]',0,0,0,1,'',CONCAT(p.path,'/',p.id),1,'',0,'admin-report-basic-catalog',0
FROM eb_system_menus p WHERE p.unique_auth='admin-report-basic' AND p.is_del=0
AND NOT EXISTS (SELECT 1 FROM eb_system_menus m WHERE m.unique_auth='admin-report-basic-catalog' AND m.is_del=0);

INSERT INTO eb_system_menus
(pid,type,icon,menu_name,module,controller,action,api_url,methods,params,sort,is_show,is_show_path,access,menu_path,path,auth_type,header,is_header,unique_auth,is_del)
SELECT p.id,1,'','基础报表查询','admin','','','report/basic/query','GET','[]',1,0,0,1,'',CONCAT(p.path,'/',p.id),1,'',0,'admin-report-basic-query',0
FROM eb_system_menus p WHERE p.unique_auth='admin-report-basic' AND p.is_del=0
AND NOT EXISTS (SELECT 1 FROM eb_system_menus m WHERE m.unique_auth='admin-report-basic-query' AND m.is_del=0);

INSERT INTO eb_system_menus
(pid,type,icon,menu_name,module,controller,action,api_url,methods,params,sort,is_show,is_show_path,access,menu_path,path,auth_type,header,is_header,unique_auth,is_del)
SELECT p.id,1,'','编辑刷单登记','admin','','','report/basic/save','POST','[]',2,0,0,1,'',CONCAT(p.path,'/',p.id),1,'',0,'admin-report-basic-write',0
FROM eb_system_menus p WHERE p.unique_auth='admin-report-basic' AND p.is_del=0
AND NOT EXISTS (SELECT 1 FROM eb_system_menus m WHERE m.unique_auth='admin-report-basic-write' AND m.is_del=0);
