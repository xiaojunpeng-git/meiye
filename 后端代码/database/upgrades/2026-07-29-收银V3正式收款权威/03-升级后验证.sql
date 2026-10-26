-- upgrade_key: 20260729-012-cashier-v3-payment-collection-authority-v1
-- Read-only exact schema and data verification. MySQL 5.6.51 compatible.
SET NAMES utf8mb4;
SET @pc_db := DATABASE();
SET @pc_failures := 0;

SELECT COUNT(*) INTO @pc_target_tables
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@pc_db
  AND TABLE_NAME IN (
    'eb_cashier_v3_payment_collection_batch',
    'eb_cashier_v3_payment_collection'
  )
  AND ENGINE='InnoDB' AND TABLE_COLLATION='utf8mb4_general_ci';

SELECT COUNT(*) INTO @pc_batch_columns
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@pc_db AND TABLE_NAME='eb_cashier_v3_payment_collection_batch';
SELECT COUNT(*) INTO @pc_collection_columns
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@pc_db AND TABLE_NAME='eb_cashier_v3_payment_collection';

SELECT COUNT(*) INTO @pc_batch_indexes FROM (
  SELECT INDEX_NAME FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=@pc_db AND TABLE_NAME='eb_cashier_v3_payment_collection_batch'
  GROUP BY INDEX_NAME
) pc_batch_index_names;
SELECT COUNT(*) INTO @pc_collection_indexes FROM (
  SELECT INDEX_NAME FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=@pc_db AND TABLE_NAME='eb_cashier_v3_payment_collection'
  GROUP BY INDEX_NAME
) pc_collection_index_names;

SELECT COUNT(*) INTO @pc_critical_columns
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@pc_db AND (
  (TABLE_NAME='eb_cashier_v3_payment_collection_batch' AND (
    (COLUMN_NAME='batch_id' AND COLUMN_TYPE='varchar(64)' AND CHARACTER_SET_NAME='ascii' AND COLLATION_NAME='ascii_bin')
    OR (COLUMN_NAME='natural_key' AND COLUMN_TYPE='varchar(160)' AND CHARACTER_SET_NAME='ascii' AND COLLATION_NAME='ascii_bin')
    OR (COLUMN_NAME='immutable_fingerprint' AND COLUMN_TYPE='char(64)' AND CHARACTER_SET_NAME='ascii' AND COLLATION_NAME='ascii_bin')
    OR (COLUMN_NAME='member_id' AND COLUMN_TYPE='bigint(20) unsigned' AND COLUMN_DEFAULT='0')
    OR (COLUMN_NAME='collection_count' AND COLUMN_TYPE='int(10) unsigned')
    OR (COLUMN_NAME='collected_amount_cents' AND COLUMN_TYPE='bigint(20) unsigned')
    OR (COLUMN_NAME='business_date' AND DATA_TYPE='date' AND IS_NULLABLE='NO')
    OR (COLUMN_NAME='settled_at' AND COLUMN_TYPE='bigint(20) unsigned')
  )) OR (TABLE_NAME='eb_cashier_v3_payment_collection' AND (
    (COLUMN_NAME='collection_id' AND COLUMN_TYPE='varchar(64)' AND CHARACTER_SET_NAME='ascii' AND COLLATION_NAME='ascii_bin')
    OR (COLUMN_NAME='natural_key' AND COLUMN_TYPE='varchar(191)' AND CHARACTER_SET_NAME='ascii' AND COLLATION_NAME='ascii_bin')
    OR (COLUMN_NAME='immutable_fingerprint' AND COLUMN_TYPE='char(64)' AND CHARACTER_SET_NAME='ascii' AND COLLATION_NAME='ascii_bin')
    OR (COLUMN_NAME='checkout_payment_draft_id' AND COLUMN_TYPE='varchar(64)' AND CHARACTER_SET_NAME='ascii' AND COLLATION_NAME='ascii_bin')
    OR (COLUMN_NAME='payment_method' AND COLUMN_TYPE='varchar(32)' AND CHARACTER_SET_NAME='ascii' AND COLLATION_NAME='ascii_bin')
    OR (COLUMN_NAME='amount_cents' AND COLUMN_TYPE='bigint(20) unsigned')
    OR (COLUMN_NAME='member_id' AND COLUMN_TYPE='bigint(20) unsigned' AND COLUMN_DEFAULT='0')
    OR (COLUMN_NAME='settled_at' AND COLUMN_TYPE='bigint(20) unsigned')
  ))
);

SELECT COUNT(*) INTO @pc_invalid_batches
FROM eb_cashier_v3_payment_collection_batch
WHERE contract_version<>'cashier-v3-payment-collection-authority-v1'
   OR batch_id NOT REGEXP '^CPB-[0-9a-f]{40}$'
   OR immutable_fingerprint NOT REGEXP '^[0-9a-f]{64}$'
   OR sales_order_id NOT REGEXP '^CSO-[0-9a-f]{40}$'
   OR sales_order_fingerprint NOT REGEXP '^[0-9a-f]{64}$'
   OR checkout_authority_fingerprint NOT REGEXP '^[0-9a-f]{64}$'
   OR checkout_aggregate_fingerprint NOT REGEXP '^[0-9a-f]{64}$'
   OR batch_status<>'settled' OR batch_direction<>'forward' OR batch_version<>1
   OR reversal_of_batch_id<>''
   OR checkout_request_version=0 OR collection_count>7
   OR collected_amount_cents<>cash_performance_amount_cents
   OR collected_amount_cents<>receivable_amount_cents
   OR (collection_count=0 AND collected_amount_cents<>0)
   OR (collection_count>0 AND collected_amount_cents=0)
   OR business_timezone<>'Asia/Shanghai'
   OR occurred_at=0 OR settled_at<occurred_at OR recorded_at<settled_at;

SELECT COUNT(*) INTO @pc_invalid_collections
FROM eb_cashier_v3_payment_collection
WHERE contract_version<>'cashier-v3-payment-collection-authority-v1'
   OR collection_id NOT REGEXP '^CPC-[0-9a-f]{40}$'
   OR collection_no NOT REGEXP '^PC-[0-9]{8}-[0-9A-F]{20}$'
   OR immutable_fingerprint NOT REGEXP '^[0-9a-f]{64}$'
   OR checkout_payment_draft_id NOT REGEXP '^CKP-[0-9a-f]{40}$'
   OR checkout_payment_fingerprint NOT REGEXP '^[0-9a-f]{64}$'
   OR sales_order_fingerprint NOT REGEXP '^[0-9a-f]{64}$'
   OR payment_method NOT IN (
     'unionpay','wechat','alipay','dianping_voucher','douyin_voucher',
     'partner_collection','other_collection'
   )
   OR payment_method_name_snapshot<>CASE payment_method
     WHEN 'unionpay' THEN '银联'
     WHEN 'wechat' THEN '微信'
     WHEN 'alipay' THEN '支付宝'
     WHEN 'dianping_voucher' THEN '大众验券'
     WHEN 'douyin_voucher' THEN '抖音验券'
     WHEN 'partner_collection' THEN '合作方收款'
     WHEN 'other_collection' THEN '其他收款'
     ELSE '' END
   OR amount_cents=0 OR amount_cents<>cash_performance_amount_cents
   OR payment_line_no=0 OR checkout_payment_sort_no=0 OR checkout_request_version=0
   OR collection_status<>'settled' OR collection_direction<>'forward'
   OR collection_version<>1 OR reversal_of_collection_id<>''
   OR business_timezone<>'Asia/Shanghai'
   OR payment_business_time_snapshot=0 OR payment_recorded_at_snapshot=0
   OR payment_recorded_at_snapshot<payment_business_time_snapshot
   OR occurred_at<payment_business_time_snapshot
   OR occurred_at<payment_recorded_at_snapshot
   OR settled_at<occurred_at OR recorded_at<settled_at;

SELECT COUNT(*) INTO @pc_orphan_batches
FROM eb_cashier_v3_payment_collection_batch batch_row
LEFT JOIN eb_cashier_v3_sales_order sales_order
  ON sales_order.tenant_id=batch_row.tenant_id
 AND sales_order.order_id=batch_row.sales_order_id
WHERE sales_order.id IS NULL
   OR sales_order.order_no<>batch_row.sales_order_no_snapshot
   OR sales_order.immutable_fingerprint<>batch_row.sales_order_fingerprint
   OR sales_order.checkout_request_id<>batch_row.checkout_request_id
   OR sales_order.checkout_request_version<>batch_row.checkout_request_version
   OR sales_order.store_id<>batch_row.store_id
   OR sales_order.organization_id<>batch_row.organization_id
   OR sales_order.member_id<>batch_row.member_id
   OR sales_order.operator_id<>batch_row.operator_id
   OR sales_order.command_idempotency_key<>batch_row.command_idempotency_key
   OR sales_order.composition<>'sale_only'
   OR sales_order.business_date<>batch_row.business_date
   OR sales_order.sale_amount_cents<>batch_row.receivable_amount_cents
   OR sales_order.order_status<>'settled'
   OR sales_order.order_direction<>'forward';

SELECT COUNT(*) INTO @pc_orphan_collections
FROM eb_cashier_v3_payment_collection collection_row
LEFT JOIN eb_cashier_v3_payment_collection_batch batch_row
  ON batch_row.tenant_id=collection_row.tenant_id
 AND batch_row.batch_id=collection_row.batch_id
WHERE batch_row.id IS NULL
   OR batch_row.sales_order_id<>collection_row.sales_order_id
   OR batch_row.sales_order_no_snapshot<>collection_row.sales_order_no_snapshot
   OR batch_row.sales_order_fingerprint<>collection_row.sales_order_fingerprint
   OR batch_row.checkout_request_id<>collection_row.checkout_request_id
   OR batch_row.checkout_request_version<>collection_row.checkout_request_version
   OR batch_row.organization_id<>collection_row.organization_id
   OR batch_row.store_id<>collection_row.store_id
   OR batch_row.member_id<>collection_row.member_id
   OR batch_row.operator_id<>collection_row.operator_id
   OR batch_row.business_date<>collection_row.business_date
   OR batch_row.business_timezone<>collection_row.business_timezone
   OR batch_row.occurred_at<>collection_row.occurred_at
   OR batch_row.settled_at<>collection_row.settled_at
   OR batch_row.recorded_at<>collection_row.recorded_at
   OR batch_row.source_document_type<>collection_row.source_document_type
   OR batch_row.source_document_id<>collection_row.source_document_id
   OR batch_row.source_document_no_snapshot<>collection_row.source_document_no_snapshot;

SELECT COUNT(*) INTO @pc_batch_total_mismatches
FROM eb_cashier_v3_payment_collection_batch batch_row
LEFT JOIN (
  SELECT tenant_id,batch_id,COUNT(*) AS collection_count,
    SUM(amount_cents) AS collected_amount_cents,
    SUM(cash_performance_amount_cents) AS cash_performance_amount_cents
  FROM eb_cashier_v3_payment_collection
  GROUP BY tenant_id,batch_id
) totals ON totals.tenant_id=batch_row.tenant_id AND totals.batch_id=batch_row.batch_id
WHERE batch_row.collection_count<>IFNULL(totals.collection_count,0)
   OR batch_row.collected_amount_cents<>IFNULL(totals.collected_amount_cents,0)
   OR batch_row.cash_performance_amount_cents<>
      IFNULL(totals.cash_performance_amount_cents,0);

SELECT COUNT(*) INTO @pc_duplicate_methods FROM (
  SELECT tenant_id,batch_id,payment_method,COUNT(*) AS duplicate_count
  FROM eb_cashier_v3_payment_collection
  GROUP BY tenant_id,batch_id,payment_method
  HAVING COUNT(*)>1
) duplicate_methods;

SET @pc_failures := @pc_failures
  + IF(@pc_target_tables=2,0,1)
  + IF(@pc_batch_columns=43,0,1)
  + IF(@pc_collection_columns=51,0,1)
  + IF(@pc_batch_indexes=11,0,1)
  + IF(@pc_collection_indexes=15,0,1)
  + IF(@pc_critical_columns=16,0,1)
  + IF(@pc_invalid_batches=0,0,1)
  + IF(@pc_invalid_collections=0,0,1)
  + IF(@pc_orphan_batches=0,0,1)
  + IF(@pc_orphan_collections=0,0,1)
  + IF(@pc_batch_total_mismatches=0,0,1)
  + IF(@pc_duplicate_methods=0,0,1);

SELECT
  @pc_target_tables AS exact_target_table_count,
  @pc_batch_columns AS batch_column_count,
  @pc_collection_columns AS collection_column_count,
  @pc_batch_indexes AS batch_index_count,
  @pc_collection_indexes AS collection_index_count,
  @pc_critical_columns AS exact_critical_column_count,
  @pc_invalid_batches AS invalid_batch_count,
  @pc_invalid_collections AS invalid_collection_count,
  @pc_orphan_batches AS orphan_batch_count,
  @pc_orphan_collections AS orphan_collection_count,
  @pc_batch_total_mismatches AS batch_total_mismatch_count,
  @pc_duplicate_methods AS duplicate_method_count,
  @pc_failures AS postcheck_failure_count;

SET @pc_finish_sql := IF(
  @pc_failures=0,
  'SELECT ''POSTCHECK_OK'' AS postcheck_result',
  'SELECT * FROM STOP_CASHIER_V3_PAYMENT_COLLECTION_POSTCHECK_FAILED'
);
PREPARE pc_finish_stmt FROM @pc_finish_sql;
EXECUTE pc_finish_stmt;
DEALLOCATE PREPARE pc_finish_stmt;
