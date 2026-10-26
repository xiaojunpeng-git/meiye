-- 服务与消耗业绩统计按来源行排除已作废的销售订单。
-- 现有订单优先索引无法利用外层服务来源行，导致每条服务事实重复扫描作废操作。
-- 只增加定位索引，不改变事实、作废判定或历史数据。
SET @index_exists := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'eb_cashier_v3_sale_fact'
    AND INDEX_NAME = 'idx_tenant_source_line_order'
);
SET @ddl := IF(
  @index_exists = 0,
  'ALTER TABLE `eb_cashier_v3_sale_fact` ADD INDEX `idx_tenant_source_line_order` (`tenant_id`,`source_line_id`,`order_id`)',
  'SELECT 1'
);
PREPARE mohe_service_index_stmt FROM @ddl;
EXECUTE mohe_service_index_stmt;
DEALLOCATE PREPARE mohe_service_index_stmt;
