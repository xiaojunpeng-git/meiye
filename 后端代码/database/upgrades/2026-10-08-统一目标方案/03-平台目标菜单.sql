-- Place target management at the bottom of Data, keep the three published URLs.
SET NAMES utf8mb4;
START TRANSACTION;

INSERT INTO eb_system_menus
(`pid`,`type`,`icon`,`menu_name`,`module`,`controller`,`action`,`api_url`,`methods`,`params`,`sort`,`is_show`,`is_show_path`,`access`,`menu_path`,`path`,`auth_type`,`header`,`is_header`,`unique_auth`,`is_del`)
SELECT p.id,1,'md-analytics','目标管理','admin','','','','','[]',0,1,0,1,'/admin/target',CAST(p.id AS CHAR),1,'report',0,'admin-target',0
FROM eb_system_menus p WHERE p.unique_auth='admin-report' AND p.is_del=0
AND NOT EXISTS (SELECT 1 FROM eb_system_menus m WHERE m.unique_auth='admin-target' AND m.is_del=0) LIMIT 1;

UPDATE eb_system_menus m JOIN eb_system_menus p ON p.unique_auth='admin-report' AND p.is_del=0
SET m.pid=p.id,m.path=CAST(p.id AS CHAR),m.header='report',m.is_header=0,m.sort=-1,m.is_show=1,m.menu_name='目标管理',m.menu_path='/admin/target'
WHERE m.unique_auth='admin-target' AND m.is_del=0;

INSERT INTO eb_system_menus
(`pid`,`type`,`icon`,`menu_name`,`module`,`controller`,`action`,`api_url`,`methods`,`params`,`sort`,`is_show`,`is_show_path`,`access`,`menu_path`,`path`,`auth_type`,`header`,`is_header`,`unique_auth`,`is_del`)
SELECT p.id,1,'','目标方案','admin','','','','','[]',40,1,0,1,'/admin/target/scheme',CONCAT(p.path,'/',p.id),1,'report',0,'admin-target-scheme',0
FROM eb_system_menus p WHERE p.unique_auth='admin-target' AND p.is_del=0
AND NOT EXISTS (SELECT 1 FROM eb_system_menus m WHERE m.unique_auth='admin-target-scheme' AND m.is_del=0) LIMIT 1;

UPDATE eb_system_menus m JOIN eb_system_menus p ON p.unique_auth='admin-target' AND p.is_del=0
SET m.pid=p.id,m.path=CONCAT(p.path,'/',p.id),m.header='report',m.is_header=0,m.sort=40,m.menu_path='/admin/target/scheme'
WHERE m.unique_auth='admin-target-scheme' AND m.is_del=0;

UPDATE eb_system_menus m JOIN eb_system_menus p ON p.unique_auth='admin-target' AND p.is_del=0
SET m.pid=p.id,m.path=CONCAT(p.path,'/',p.id),m.header='report',m.is_header=0,m.sort=30,m.menu_path='/admin/target/data_analysis'
WHERE m.unique_auth='admin-target-data-analysis' AND m.is_del=0;

INSERT INTO eb_system_menus
(`pid`,`type`,`icon`,`menu_name`,`module`,`controller`,`action`,`api_url`,`methods`,`params`,`sort`,`is_show`,`is_show_path`,`access`,`menu_path`,`path`,`auth_type`,`header`,`is_header`,`unique_auth`,`is_del`)
SELECT p.id,1,'','月度目标完成明细','admin','','','','','[]',20,1,0,1,'/admin/target/monthly_detail',CONCAT(p.path,'/',p.id),1,'report',0,'admin-target-monthly-detail',0
FROM eb_system_menus p WHERE p.unique_auth='admin-target' AND p.is_del=0
AND NOT EXISTS (SELECT 1 FROM eb_system_menus m WHERE m.unique_auth='admin-target-monthly-detail' AND m.is_del=0) LIMIT 1;

INSERT INTO eb_system_menus
(`pid`,`type`,`icon`,`menu_name`,`module`,`controller`,`action`,`api_url`,`methods`,`params`,`sort`,`is_show`,`is_show_path`,`access`,`menu_path`,`path`,`auth_type`,`header`,`is_header`,`unique_auth`,`is_del`)
SELECT p.id,1,'','门店目标完成明细','admin','','','','','[]',10,1,0,1,'/admin/target/store_detail',CONCAT(p.path,'/',p.id),1,'report',0,'admin-target-store-detail',0
FROM eb_system_menus p WHERE p.unique_auth='admin-target' AND p.is_del=0
AND NOT EXISTS (SELECT 1 FROM eb_system_menus m WHERE m.unique_auth='admin-target-store-detail' AND m.is_del=0) LIMIT 1;

-- Existing target roles receive the new goal entry and independent reports.
UPDATE eb_system_role r JOIN eb_system_menus parent ON parent.unique_auth='admin-target' AND parent.is_del=0
SET r.rules=CONCAT_WS(',',NULLIF(TRIM(BOTH ',' FROM r.rules),''),
  (SELECT GROUP_CONCAT(child.id ORDER BY child.id SEPARATOR ',') FROM eb_system_menus child
   WHERE child.unique_auth IN ('admin-target-scheme','admin-target-monthly-detail','admin-target-store-detail')
     AND child.is_del=0 AND FIND_IN_SET(child.id,r.rules)=0))
WHERE FIND_IN_SET(parent.id,r.rules)>0;
COMMIT;
