-- upgrade_key: 20260909-001-platform-store-order-center-menu
-- MySQL 5.6 compatible and idempotent. Run 01 first and require active_parent_count=1.
SET NAMES utf8mb4;

-- Add the eight configured child entries. The parent uniqueness predicate makes the
-- whole upgrade a no-op if a broken menu tree contains multiple active parents.
INSERT INTO eb_system_menus
(`pid`,`type`,`icon`,`menu_name`,`module`,`controller`,`action`,`api_url`,`methods`,`params`,`sort`,`is_show`,`is_show_path`,`access`,`menu_path`,`path`,`auth_type`,`header`,`is_header`,`unique_auth`,`is_del`)
SELECT p.id,1,'','销售订单','admin','','','','[]','[]',10,1,0,1,
       '/admin/store/order/center?tab=sales',CONCAT(p.path,'/',p.id),1,'',0,
       'admin-store-order-center-sales',0
FROM eb_system_menus p
WHERE p.unique_auth='admin-store-order' AND p.menu_name='门店订单' AND p.type=1 AND p.is_del=0
  AND (SELECT COUNT(*) FROM eb_system_menus q WHERE q.unique_auth='admin-store-order' AND q.menu_name='门店订单' AND q.type=1 AND q.is_del=0)=1
  AND NOT EXISTS (SELECT 1 FROM eb_system_menus e WHERE e.unique_auth='admin-store-order-center-sales' AND e.is_del=0);

INSERT INTO eb_system_menus
(`pid`,`type`,`icon`,`menu_name`,`module`,`controller`,`action`,`api_url`,`methods`,`params`,`sort`,`is_show`,`is_show_path`,`access`,`menu_path`,`path`,`auth_type`,`header`,`is_header`,`unique_auth`,`is_del`)
SELECT p.id,1,'','充值订单','admin','','','','[]','[]',20,1,0,1,
       '/admin/store/order/center?tab=recharge',CONCAT(p.path,'/',p.id),1,'',0,
       'admin-store-order-center-recharge',0
FROM eb_system_menus p
WHERE p.unique_auth='admin-store-order' AND p.menu_name='门店订单' AND p.type=1 AND p.is_del=0
  AND (SELECT COUNT(*) FROM eb_system_menus q WHERE q.unique_auth='admin-store-order' AND q.menu_name='门店订单' AND q.type=1 AND q.is_del=0)=1
  AND NOT EXISTS (SELECT 1 FROM eb_system_menus e WHERE e.unique_auth='admin-store-order-center-recharge' AND e.is_del=0);

INSERT INTO eb_system_menus
(`pid`,`type`,`icon`,`menu_name`,`module`,`controller`,`action`,`api_url`,`methods`,`params`,`sort`,`is_show`,`is_show_path`,`access`,`menu_path`,`path`,`auth_type`,`header`,`is_header`,`unique_auth`,`is_del`)
SELECT p.id,1,'','退款记录','admin','','','','[]','[]',30,1,0,1,
       '/admin/store/order/center?tab=refund',CONCAT(p.path,'/',p.id),1,'',0,
       'admin-store-order-center-refund',0
FROM eb_system_menus p
WHERE p.unique_auth='admin-store-order' AND p.menu_name='门店订单' AND p.type=1 AND p.is_del=0
  AND (SELECT COUNT(*) FROM eb_system_menus q WHERE q.unique_auth='admin-store-order' AND q.menu_name='门店订单' AND q.type=1 AND q.is_del=0)=1
  AND NOT EXISTS (SELECT 1 FROM eb_system_menus e WHERE e.unique_auth='admin-store-order-center-refund' AND e.is_del=0);

INSERT INTO eb_system_menus
(`pid`,`type`,`icon`,`menu_name`,`module`,`controller`,`action`,`api_url`,`methods`,`params`,`sort`,`is_show`,`is_show_path`,`access`,`menu_path`,`path`,`auth_type`,`header`,`is_header`,`unique_auth`,`is_del`)
SELECT p.id,1,'','欠款管理','admin','','','','[]','[]',40,1,0,1,
       '/admin/store/order/center?tab=debt',CONCAT(p.path,'/',p.id),1,'',0,
       'admin-store-order-center-debt',0
FROM eb_system_menus p
WHERE p.unique_auth='admin-store-order' AND p.menu_name='门店订单' AND p.type=1 AND p.is_del=0
  AND (SELECT COUNT(*) FROM eb_system_menus q WHERE q.unique_auth='admin-store-order' AND q.menu_name='门店订单' AND q.type=1 AND q.is_del=0)=1
  AND NOT EXISTS (SELECT 1 FROM eb_system_menus e WHERE e.unique_auth='admin-store-order-center-debt' AND e.is_del=0);

INSERT INTO eb_system_menus
(`pid`,`type`,`icon`,`menu_name`,`module`,`controller`,`action`,`api_url`,`methods`,`params`,`sort`,`is_show`,`is_show_path`,`access`,`menu_path`,`path`,`auth_type`,`header`,`is_header`,`unique_auth`,`is_del`)
SELECT p.id,1,'','服务记录','admin','','','','[]','[]',50,1,0,1,
       '/admin/store/order/center?tab=service',CONCAT(p.path,'/',p.id),1,'',0,
       'admin-store-order-center-service',0
FROM eb_system_menus p
WHERE p.unique_auth='admin-store-order' AND p.menu_name='门店订单' AND p.type=1 AND p.is_del=0
  AND (SELECT COUNT(*) FROM eb_system_menus q WHERE q.unique_auth='admin-store-order' AND q.menu_name='门店订单' AND q.type=1 AND q.is_del=0)=1
  AND NOT EXISTS (SELECT 1 FROM eb_system_menus e WHERE e.unique_auth='admin-store-order-center-service' AND e.is_del=0);

INSERT INTO eb_system_menus
(`pid`,`type`,`icon`,`menu_name`,`module`,`controller`,`action`,`api_url`,`methods`,`params`,`sort`,`is_show`,`is_show_path`,`access`,`menu_path`,`path`,`auth_type`,`header`,`is_header`,`unique_auth`,`is_del`)
SELECT p.id,1,'','补交记录','admin','','','','[]','[]',60,1,0,1,
       '/admin/store/order/center?tab=supplement',CONCAT(p.path,'/',p.id),1,'',0,
       'admin-store-order-center-supplement',0
FROM eb_system_menus p
WHERE p.unique_auth='admin-store-order' AND p.menu_name='门店订单' AND p.type=1 AND p.is_del=0
  AND (SELECT COUNT(*) FROM eb_system_menus q WHERE q.unique_auth='admin-store-order' AND q.menu_name='门店订单' AND q.type=1 AND q.is_del=0)=1
  AND NOT EXISTS (SELECT 1 FROM eb_system_menus e WHERE e.unique_auth='admin-store-order-center-supplement' AND e.is_del=0);

INSERT INTO eb_system_menus
(`pid`,`type`,`icon`,`menu_name`,`module`,`controller`,`action`,`api_url`,`methods`,`params`,`sort`,`is_show`,`is_show_path`,`access`,`menu_path`,`path`,`auth_type`,`header`,`is_header`,`unique_auth`,`is_del`)
SELECT p.id,1,'','赠送记录','admin','','','','[]','[]',70,1,0,1,
       '/admin/store/order/center?tab=gift',CONCAT(p.path,'/',p.id),1,'',0,
       'admin-store-order-center-gift',0
FROM eb_system_menus p
WHERE p.unique_auth='admin-store-order' AND p.menu_name='门店订单' AND p.type=1 AND p.is_del=0
  AND (SELECT COUNT(*) FROM eb_system_menus q WHERE q.unique_auth='admin-store-order' AND q.menu_name='门店订单' AND q.type=1 AND q.is_del=0)=1
  AND NOT EXISTS (SELECT 1 FROM eb_system_menus e WHERE e.unique_auth='admin-store-order-center-gift' AND e.is_del=0);

INSERT INTO eb_system_menus
(`pid`,`type`,`icon`,`menu_name`,`module`,`controller`,`action`,`api_url`,`methods`,`params`,`sort`,`is_show`,`is_show_path`,`access`,`menu_path`,`path`,`auth_type`,`header`,`is_header`,`unique_auth`,`is_del`)
SELECT p.id,1,'','卡操作记录','admin','','','','[]','[]',80,1,0,1,
       '/admin/store/order/center?tab=card_operation',CONCAT(p.path,'/',p.id),1,'',0,
       'admin-store-order-center-card-operation',0
FROM eb_system_menus p
WHERE p.unique_auth='admin-store-order' AND p.menu_name='门店订单' AND p.type=1 AND p.is_del=0
  AND (SELECT COUNT(*) FROM eb_system_menus q WHERE q.unique_auth='admin-store-order' AND q.menu_name='门店订单' AND q.type=1 AND q.is_del=0)=1
  AND NOT EXISTS (SELECT 1 FROM eb_system_menus e WHERE e.unique_auth='admin-store-order-center-card-operation' AND e.is_del=0);

-- Existing parent-menu roles receive all read-only tabs. Each statement is separate
-- so a partial prior execution self-heals on rerun without duplicating IDs.
UPDATE eb_system_role r
SET r.rules=CONCAT_WS(',',NULLIF(TRIM(BOTH ',' FROM r.rules),''),(
  SELECT id FROM eb_system_menus WHERE unique_auth='admin-store-order-center-sales' AND is_del=0 LIMIT 1
))
WHERE FIND_IN_SET((SELECT id FROM eb_system_menus WHERE unique_auth='admin-store-order' AND menu_name='门店订单' AND type=1 AND is_del=0 LIMIT 1),r.rules)>0
  AND FIND_IN_SET((SELECT id FROM eb_system_menus WHERE unique_auth='admin-store-order-center-sales' AND is_del=0 LIMIT 1),r.rules)=0
  AND (SELECT COUNT(*) FROM eb_system_menus WHERE unique_auth='admin-store-order' AND menu_name='门店订单' AND type=1 AND is_del=0)=1;

UPDATE eb_system_role r
SET r.rules=CONCAT_WS(',',NULLIF(TRIM(BOTH ',' FROM r.rules),''),(
  SELECT id FROM eb_system_menus WHERE unique_auth='admin-store-order-center-recharge' AND is_del=0 LIMIT 1
))
WHERE FIND_IN_SET((SELECT id FROM eb_system_menus WHERE unique_auth='admin-store-order' AND menu_name='门店订单' AND type=1 AND is_del=0 LIMIT 1),r.rules)>0
  AND FIND_IN_SET((SELECT id FROM eb_system_menus WHERE unique_auth='admin-store-order-center-recharge' AND is_del=0 LIMIT 1),r.rules)=0
  AND (SELECT COUNT(*) FROM eb_system_menus WHERE unique_auth='admin-store-order' AND menu_name='门店订单' AND type=1 AND is_del=0)=1;

UPDATE eb_system_role r
SET r.rules=CONCAT_WS(',',NULLIF(TRIM(BOTH ',' FROM r.rules),''),(
  SELECT id FROM eb_system_menus WHERE unique_auth='admin-store-order-center-refund' AND is_del=0 LIMIT 1
))
WHERE FIND_IN_SET((SELECT id FROM eb_system_menus WHERE unique_auth='admin-store-order' AND menu_name='门店订单' AND type=1 AND is_del=0 LIMIT 1),r.rules)>0
  AND FIND_IN_SET((SELECT id FROM eb_system_menus WHERE unique_auth='admin-store-order-center-refund' AND is_del=0 LIMIT 1),r.rules)=0
  AND (SELECT COUNT(*) FROM eb_system_menus WHERE unique_auth='admin-store-order' AND menu_name='门店订单' AND type=1 AND is_del=0)=1;

UPDATE eb_system_role r
SET r.rules=CONCAT_WS(',',NULLIF(TRIM(BOTH ',' FROM r.rules),''),(
  SELECT id FROM eb_system_menus WHERE unique_auth='admin-store-order-center-debt' AND is_del=0 LIMIT 1
))
WHERE FIND_IN_SET((SELECT id FROM eb_system_menus WHERE unique_auth='admin-store-order' AND menu_name='门店订单' AND type=1 AND is_del=0 LIMIT 1),r.rules)>0
  AND FIND_IN_SET((SELECT id FROM eb_system_menus WHERE unique_auth='admin-store-order-center-debt' AND is_del=0 LIMIT 1),r.rules)=0
  AND (SELECT COUNT(*) FROM eb_system_menus WHERE unique_auth='admin-store-order' AND menu_name='门店订单' AND type=1 AND is_del=0)=1;

UPDATE eb_system_role r
SET r.rules=CONCAT_WS(',',NULLIF(TRIM(BOTH ',' FROM r.rules),''),(
  SELECT id FROM eb_system_menus WHERE unique_auth='admin-store-order-center-service' AND is_del=0 LIMIT 1
))
WHERE FIND_IN_SET((SELECT id FROM eb_system_menus WHERE unique_auth='admin-store-order' AND menu_name='门店订单' AND type=1 AND is_del=0 LIMIT 1),r.rules)>0
  AND FIND_IN_SET((SELECT id FROM eb_system_menus WHERE unique_auth='admin-store-order-center-service' AND is_del=0 LIMIT 1),r.rules)=0
  AND (SELECT COUNT(*) FROM eb_system_menus WHERE unique_auth='admin-store-order' AND menu_name='门店订单' AND type=1 AND is_del=0)=1;

UPDATE eb_system_role r
SET r.rules=CONCAT_WS(',',NULLIF(TRIM(BOTH ',' FROM r.rules),''),(
  SELECT id FROM eb_system_menus WHERE unique_auth='admin-store-order-center-supplement' AND is_del=0 LIMIT 1
))
WHERE FIND_IN_SET((SELECT id FROM eb_system_menus WHERE unique_auth='admin-store-order' AND menu_name='门店订单' AND type=1 AND is_del=0 LIMIT 1),r.rules)>0
  AND FIND_IN_SET((SELECT id FROM eb_system_menus WHERE unique_auth='admin-store-order-center-supplement' AND is_del=0 LIMIT 1),r.rules)=0
  AND (SELECT COUNT(*) FROM eb_system_menus WHERE unique_auth='admin-store-order' AND menu_name='门店订单' AND type=1 AND is_del=0)=1;

UPDATE eb_system_role r
SET r.rules=CONCAT_WS(',',NULLIF(TRIM(BOTH ',' FROM r.rules),''),(
  SELECT id FROM eb_system_menus WHERE unique_auth='admin-store-order-center-gift' AND is_del=0 LIMIT 1
))
WHERE FIND_IN_SET((SELECT id FROM eb_system_menus WHERE unique_auth='admin-store-order' AND menu_name='门店订单' AND type=1 AND is_del=0 LIMIT 1),r.rules)>0
  AND FIND_IN_SET((SELECT id FROM eb_system_menus WHERE unique_auth='admin-store-order-center-gift' AND is_del=0 LIMIT 1),r.rules)=0
  AND (SELECT COUNT(*) FROM eb_system_menus WHERE unique_auth='admin-store-order' AND menu_name='门店订单' AND type=1 AND is_del=0)=1;

UPDATE eb_system_role r
SET r.rules=CONCAT_WS(',',NULLIF(TRIM(BOTH ',' FROM r.rules),''),(
  SELECT id FROM eb_system_menus WHERE unique_auth='admin-store-order-center-card-operation' AND is_del=0 LIMIT 1
))
WHERE FIND_IN_SET((SELECT id FROM eb_system_menus WHERE unique_auth='admin-store-order' AND menu_name='门店订单' AND type=1 AND is_del=0 LIMIT 1),r.rules)>0
  AND FIND_IN_SET((SELECT id FROM eb_system_menus WHERE unique_auth='admin-store-order-center-card-operation' AND is_del=0 LIMIT 1),r.rules)=0
  AND (SELECT COUNT(*) FROM eb_system_menus WHERE unique_auth='admin-store-order' AND menu_name='门店订单' AND type=1 AND is_del=0)=1;

-- Preserve legacy targets but remove the three obsolete child entries from menu render.
UPDATE eb_system_menus legacy
JOIN eb_system_menus p ON p.id=legacy.pid
JOIN (
  SELECT active_parent_count
  FROM (
    SELECT COUNT(*) AS active_parent_count
    FROM eb_system_menus
    WHERE unique_auth='admin-store-order' AND menu_name='门店订单' AND type=1 AND is_del=0
  ) AS parent_count_snapshot
) AS guard ON guard.active_parent_count=1
SET legacy.is_show=0
WHERE p.unique_auth='admin-store-order' AND p.menu_name='门店订单' AND p.type=1 AND p.is_del=0
  AND legacy.unique_auth IN ('admin-store-store_order','admin-store-refund-order','admin-store-writeoff')
  AND legacy.is_del=0;

INSERT INTO eb_database_upgrade_log
(`upgrade_key`,`title`,`task_file`,`sql_checksum`,`code_version`,`executed_at`,`executed_by`,`result_note`)
SELECT '20260909-001-platform-store-order-center-menu','平台门店订单中心菜单',
       '2026-09-09-平台门店订单中心菜单/02-正式升级.sql','', '', NOW(), 'codex-release',
       'registered eight read-only store order-center tabs; hid legacy child menu entries; inherited only from admin-store-order parent roles'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM eb_database_upgrade_log WHERE upgrade_key='20260909-001-platform-store-order-center-menu');
