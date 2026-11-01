-- R63: 平台订单中心在“当前组织 / 全部可见门店”范围不一定下发 store_id 条件。
-- 旧 idx_order_center_store_date 以 store_id 开头，在该场景无法用于月度正常订单筛选。
-- 本迁移只增加非唯一访问索引；不改业务数据、约束、默认值或任何订单状态。
-- MySQL 5.7 兼容；已有同前导列顺序时可重复执行。
-- 历史赠送按赠送标记定位订单行；升级记录按旧卡目标订单索引定位。
-- 这两个读取不得为了当前页反复扫描全部普通消费明细和全部旧订单。
SET @r63_sql := IF(EXISTS(SELECT 1 FROM information_schema.statistics
    WHERE table_schema=DATABASE() AND table_name='eb_store_order_cart_info'
    AND index_name='idx_order_center_gift_order'), 'SELECT 1',
    'ALTER TABLE `eb_store_order_cart_info` ADD INDEX `idx_order_center_gift_order` (`is_gift`,`oid`,`id`)');
PREPARE r63_stmt FROM @r63_sql;
EXECUTE r63_stmt;
DEALLOCATE PREPARE r63_stmt;
SET @r63_sql := IF(EXISTS(SELECT 1 FROM information_schema.statistics
    WHERE table_schema=DATABASE() AND table_name='eb_store_order'
    AND index_name='idx_order_center_upgrade_target'), 'SELECT 1',
    'ALTER TABLE `eb_store_order` ADD INDEX `idx_order_center_upgrade_target` (`card_upgrade_use_oid`,`id`)');
PREPARE r63_stmt FROM @r63_sql;
EXECUTE r63_stmt;
DEALLOCATE PREPARE r63_stmt;
SET @r63_sql := IF(EXISTS(
    SELECT index_name FROM information_schema.statistics
    WHERE table_schema=DATABASE() AND table_name='eb_store_order'
    GROUP BY index_name
    HAVING GROUP_CONCAT(column_name ORDER BY seq_in_index)=
        'order_type,paid,is_system_del,is_debt_repay,is_del,refund_status,pay_time'
       OR GROUP_CONCAT(column_name ORDER BY seq_in_index) LIKE
        'order_type,paid,is_system_del,is_debt_repay,is_del,refund_status,pay_time,%'
), 'SELECT 1',
    'ALTER TABLE `eb_store_order` ADD INDEX `idx_order_center_global_normal_date` '
    '(`order_type`,`paid`,`is_system_del`,`is_debt_repay`,`is_del`,`refund_status`,`pay_time`)');
PREPARE r63_stmt FROM @r63_sql;
EXECUTE r63_stmt;
DEALLOCATE PREPARE r63_stmt;

SET @r63_sql := IF(EXISTS(
    SELECT index_name FROM information_schema.statistics
    WHERE table_schema=DATABASE() AND table_name='eb_cashier_v3_entitlement_service_fact'
    GROUP BY index_name
    HAVING GROUP_CONCAT(column_name ORDER BY seq_in_index)=
        'tenant_id,business_date,service_status,checkout_request_id,settled_at,id'
), 'SELECT 1',
    'ALTER TABLE `eb_cashier_v3_entitlement_service_fact` ADD INDEX `idx_order_center_service_group` '
    '(`tenant_id`,`business_date`,`service_status`,`checkout_request_id`,`settled_at`,`id`)');
PREPARE r63_stmt FROM @r63_sql;
EXECUTE r63_stmt;
DEALLOCATE PREPARE r63_stmt;

-- V3 权威销售单与纯权益服务单在平台“全部门店”范围同样没有 store_id
-- 前导条件；补齐租户 + 日期 + 状态访问路径，避免对 UNION 两端整表排序。
SET @r63_sql := IF(EXISTS(
    SELECT index_name FROM information_schema.statistics
    WHERE table_schema=DATABASE() AND table_name='eb_cashier_v3_sales_order'
    GROUP BY index_name
    HAVING GROUP_CONCAT(column_name ORDER BY seq_in_index)=
        'tenant_id,business_date,order_status,order_direction,settled_at,id'
), 'SELECT 1',
    'ALTER TABLE `eb_cashier_v3_sales_order` ADD INDEX `idx_order_center_tenant_date` '
    '(`tenant_id`,`business_date`,`order_status`,`order_direction`,`settled_at`,`id`)');
PREPARE r63_stmt FROM @r63_sql;
EXECUTE r63_stmt;
DEALLOCATE PREPARE r63_stmt;

SET @r63_sql := IF(EXISTS(
    SELECT index_name FROM information_schema.statistics
    WHERE table_schema=DATABASE() AND table_name='eb_cashier_v3_entitlement_service_fact'
    GROUP BY index_name
    HAVING GROUP_CONCAT(column_name ORDER BY seq_in_index)=
        'tenant_id,business_date,service_status,settled_at,checkout_request_id,id'
), 'SELECT 1',
    'ALTER TABLE `eb_cashier_v3_entitlement_service_fact` ADD INDEX `idx_order_center_tenant_date` '
    '(`tenant_id`,`business_date`,`service_status`,`settled_at`,`checkout_request_id`,`id`)');
PREPARE r63_stmt FROM @r63_sql;
EXECUTE r63_stmt;
DEALLOCATE PREPARE r63_stmt;

-- 当前页20条的生命周期判断必须按租户和具体单据走索引；原索引以类型
-- 开头，平台混合列表会为 OR 条件扫描大量事件。
SET @r63_sql := IF(EXISTS(
    SELECT index_name FROM information_schema.statistics
    WHERE table_schema=DATABASE() AND table_name='eb_cashier_v3_business_event'
    GROUP BY index_name
    HAVING GROUP_CONCAT(column_name ORDER BY seq_in_index)=
        'tenant_id,source_id,event_type'
), 'SELECT 1',
    'ALTER TABLE `eb_cashier_v3_business_event` ADD INDEX `idx_order_center_tenant_source` '
    '(`tenant_id`,`source_id`,`event_type`)');
PREPARE r63_stmt FROM @r63_sql;
EXECUTE r63_stmt;
DEALLOCATE PREPARE r63_stmt;

SET @r63_sql := IF(EXISTS(
    SELECT index_name FROM information_schema.statistics
    WHERE table_schema=DATABASE() AND table_name='eb_cashier_v3_business_event'
    GROUP BY index_name
    HAVING GROUP_CONCAT(column_name ORDER BY seq_in_index)=
        'tenant_id,aggregate_id,event_type'
), 'SELECT 1',
    'ALTER TABLE `eb_cashier_v3_business_event` ADD INDEX `idx_order_center_tenant_aggregate` '
    '(`tenant_id`,`aggregate_id`,`event_type`)');
PREPARE r63_stmt FROM @r63_sql;
EXECUTE r63_stmt;
DEALLOCATE PREPARE r63_stmt;
