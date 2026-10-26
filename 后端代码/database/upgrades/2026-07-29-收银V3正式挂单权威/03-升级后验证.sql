-- upgrade_key: 20260729-017-cashier-v3-hang-order-authority-v1
-- Read-only postcheck. MySQL 5.6.51 compatible.
SET NAMES utf8mb4;
SET @ho_db := DATABASE();
SET @ho_failures := 0;

SELECT COUNT(*) INTO @ho_tables
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@ho_db
  AND TABLE_NAME IN ('eb_cashier_v3_hang_order','eb_cashier_v3_hang_order_line')
  AND ENGINE='InnoDB' AND TABLE_COLLATION='utf8mb4_general_ci';

SELECT COUNT(*) INTO @ho_invalid_headers
FROM eb_cashier_v3_hang_order
WHERE contract_version<>'cashier-v3-hang-order-authority-v1'
   OR hang_mode NOT IN ('normal','start_service')
   OR hang_status NOT IN ('pending_checkout','service_in_progress')
   OR hang_version<>1 OR line_count=0 OR total_quantity=0
   OR immutable_fingerprint NOT REGEXP '^[0-9a-f]{64}$'
   OR workspace_line_fingerprint NOT REGEXP '^[0-9a-f]{64}$'
   OR preparation_token NOT REGEXP '^[0-9a-f]{64}$'
   OR (hang_mode='start_service' AND (
       room_id=0 OR room_version=0 OR room_time_slot_id=''
       OR room_time_slot_version=0 OR room_guard_fingerprint NOT REGEXP '^[0-9a-f]{64}$'
   ));

SELECT COUNT(*) INTO @ho_invalid_lines
FROM eb_cashier_v3_hang_order_line
WHERE line_role NOT IN ('sale','entitlement_service')
   OR quantity=0 OR source_version=0 OR detail_version=0
   OR line_status<>'held' OR line_version<>1
   OR is_experience NOT IN (0,1)
   OR immutable_fingerprint NOT REGEXP '^[0-9a-f]{64}$';

SELECT COUNT(*) INTO @ho_orphans
FROM eb_cashier_v3_hang_order_line l
LEFT JOIN eb_cashier_v3_hang_order h
  ON h.tenant_id=l.tenant_id AND h.hang_order_id=l.hang_order_id
WHERE h.id IS NULL OR h.store_id<>l.store_id OR h.member_id<>l.member_id;

SELECT COUNT(*) INTO @ho_mismatches
FROM eb_cashier_v3_hang_order h
LEFT JOIN (
  SELECT tenant_id,hang_order_id,COUNT(*) line_count,SUM(quantity) total_quantity,
    SUM(sale_amount_cents) sale_amount_cents,
    SUM(entitlement_actual_amount_cents) entitlement_actual_amount_cents
  FROM eb_cashier_v3_hang_order_line GROUP BY tenant_id,hang_order_id
) t ON t.tenant_id=h.tenant_id AND t.hang_order_id=h.hang_order_id
WHERE h.line_count<>IFNULL(t.line_count,0)
   OR h.total_quantity<>IFNULL(t.total_quantity,0)
   OR h.sale_amount_cents<>IFNULL(t.sale_amount_cents,0)
   OR h.entitlement_actual_amount_cents<>IFNULL(t.entitlement_actual_amount_cents,0);

SET @ho_failures := @ho_failures + IF(@ho_tables=2,0,1)
  + IF(@ho_invalid_headers=0,0,1) + IF(@ho_invalid_lines=0,0,1)
  + IF(@ho_orphans=0,0,1) + IF(@ho_mismatches=0,0,1);
SELECT @ho_tables AS table_count, @ho_invalid_headers AS invalid_header_count,
  @ho_invalid_lines AS invalid_line_count, @ho_orphans AS orphan_count,
  @ho_mismatches AS aggregate_mismatch_count, @ho_failures AS postcheck_failure_count;

SET @ho_finish := IF(
  @ho_failures=0,
  'SELECT ''POSTCHECK_OK'' AS postcheck_result',
  'SELECT * FROM STOP_CASHIER_V3_HANG_ORDER_POSTCHECK_FAILED'
);
PREPARE ho_stmt FROM @ho_finish;
EXECUTE ho_stmt;
DEALLOCATE PREPARE ho_stmt;
