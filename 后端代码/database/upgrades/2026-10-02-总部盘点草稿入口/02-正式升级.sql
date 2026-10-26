-- upgrade_key: 20261002-002-hq-count-draft-api
-- 登记后仍由平台仓库管理能力与服务端创建人/仓库范围限制，不授予新的角色权限。
SET NAMES utf8mb4;
INSERT INTO eb_system_menus
(pid,type,icon,menu_name,module,controller,action,api_url,methods,params,sort,is_show,is_show_path,access,menu_path,path,auth_type,header,is_header,unique_auth,is_del)
SELECT 0,1,'','总部盘点草稿保存','','','','product/inventory/v3/hq/count/draft','POST','[]',0,0,0,1,'','',2,'',0,'inventory-v3-platform-warehouse-manage',0
WHERE NOT EXISTS (SELECT 1 FROM eb_system_menus WHERE api_url='product/inventory/v3/hq/count/draft' AND methods='POST' AND type=1 AND is_del=0);
INSERT INTO eb_system_menus
(pid,type,icon,menu_name,module,controller,action,api_url,methods,params,sort,is_show,is_show_path,access,menu_path,path,auth_type,header,is_header,unique_auth,is_del)
SELECT 0,1,'','总部盘点草稿详情','','','','product/inventory/v3/hq/count/draft/:id','GET','[]',0,0,0,1,'','',2,'',0,'inventory-v3-platform-warehouse-manage',0
WHERE NOT EXISTS (SELECT 1 FROM eb_system_menus WHERE api_url='product/inventory/v3/hq/count/draft/:id' AND methods='GET' AND type=1 AND is_del=0);
INSERT INTO eb_system_menus
(pid,type,icon,menu_name,module,controller,action,api_url,methods,params,sort,is_show,is_show_path,access,menu_path,path,auth_type,header,is_header,unique_auth,is_del)
SELECT 0,1,'','总部盘点草稿删除','','','','product/inventory/v3/hq/count/draft/:id/delete','POST','[]',0,0,0,1,'','',2,'',0,'inventory-v3-platform-warehouse-manage',0
WHERE NOT EXISTS (SELECT 1 FROM eb_system_menus WHERE api_url='product/inventory/v3/hq/count/draft/:id/delete' AND methods='POST' AND type=1 AND is_del=0);
