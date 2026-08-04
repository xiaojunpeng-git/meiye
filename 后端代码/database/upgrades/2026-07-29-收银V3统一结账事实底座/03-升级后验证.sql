-- upgrade_key: 20260729-007-cashier-v3-checkout-facts-v1
-- Exact behavioral verification. MySQL 5.6.51 compatible.
SET NAMES utf8mb4;
SET SESSION group_concat_max_len=1048576;
SET @cf_db := DATABASE();
SET @cf_failures := 0;

SELECT COUNT(*) INTO @cf_target_tables
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@cf_db
  AND TABLE_NAME IN (
    'eb_cashier_v3_sale_fact','eb_cashier_v3_payment_fact',
    'eb_cashier_v3_balance_fact','eb_cashier_v3_performance_fact'
  )
  AND ENGINE='InnoDB' AND TABLE_COLLATION='utf8mb4_general_ci';

SELECT COUNT(*) INTO @cf_target_columns
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@cf_db
  AND TABLE_NAME IN (
    'eb_cashier_v3_sale_fact','eb_cashier_v3_payment_fact',
    'eb_cashier_v3_balance_fact','eb_cashier_v3_performance_fact'
  );

SELECT COUNT(*) INTO @cf_target_indexes
FROM (
  SELECT TABLE_NAME,INDEX_NAME
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=@cf_db
    AND TABLE_NAME IN (
      'eb_cashier_v3_sale_fact','eb_cashier_v3_payment_fact',
      'eb_cashier_v3_balance_fact','eb_cashier_v3_performance_fact'
    )
  GROUP BY TABLE_NAME,INDEX_NAME
) cf_indexes;

SELECT COUNT(*) INTO @cf_common_contract_columns
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@cf_db
  AND TABLE_NAME IN (
    'eb_cashier_v3_sale_fact','eb_cashier_v3_payment_fact',
    'eb_cashier_v3_balance_fact','eb_cashier_v3_performance_fact'
  )
  AND COLUMN_NAME IN (
    'fact_id','business_event_no','fact_type','fact_direction','natural_key',
    'command_idempotency_key','immutable_fingerprint','fact_version',
    'reversal_of','status','tenant_id','tenant_name_snapshot',
    'organization_id','organization_name_snapshot','organization_path_snapshot',
    'store_id','store_name_snapshot','member_id','member_name_snapshot',
    'operator_id','operator_name_snapshot','business_date','business_timezone',
    'occurred_at','settled_at','recorded_at','checkout_request_id','order_id',
    'order_no_snapshot','source_document_type','source_line_id'
  );

SELECT COUNT(*) INTO @cf_critical_types
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@cf_db
  AND TABLE_NAME IN (
    'eb_cashier_v3_sale_fact','eb_cashier_v3_payment_fact',
    'eb_cashier_v3_balance_fact','eb_cashier_v3_performance_fact'
  ) AND (
    (COLUMN_NAME='natural_key' AND COLUMN_TYPE='varchar(160)'
      AND CHARACTER_SET_NAME='ascii' AND COLLATION_NAME='ascii_bin')
    OR (COLUMN_NAME='immutable_fingerprint' AND COLUMN_TYPE='char(64)'
      AND CHARACTER_SET_NAME='ascii' AND COLLATION_NAME='ascii_bin')
    OR (COLUMN_NAME='business_date' AND DATA_TYPE='date' AND IS_NULLABLE='NO')
    OR (COLUMN_NAME='occurred_at' AND DATA_TYPE='bigint' AND COLUMN_TYPE LIKE '%unsigned%')
    OR (COLUMN_NAME='settled_at' AND DATA_TYPE='bigint' AND COLUMN_TYPE LIKE '%unsigned%')
    OR (COLUMN_NAME='recorded_at' AND DATA_TYPE='bigint' AND COLUMN_TYPE LIKE '%unsigned%')
  );

SELECT COUNT(*) INTO @cf_invalid_sale
FROM eb_cashier_v3_sale_fact
WHERE fact_type<>'sale_completed'
   OR fact_direction NOT IN ('forward','reversal')
   OR status<>'effective'
   OR immutable_fingerprint NOT REGEXP '^[0-9a-f]{64}$'
   OR occurred_at=0 OR settled_at<occurred_at OR recorded_at<occurred_at
   OR original_amount_cents-discount_amount_cents<>sale_amount_cents
   OR (fact_direction='forward' AND (sale_amount_cents<=0 OR reversal_of<>''))
   OR (fact_direction='reversal' AND (sale_amount_cents>=0 OR reversal_of=''));

SELECT COUNT(*) INTO @cf_invalid_payment
FROM eb_cashier_v3_payment_fact
WHERE fact_type<>'payment_collected'
   OR payment_method NOT IN (
     'unionpay','wechat','alipay','dianping_voucher','douyin_voucher',
     'partner_collection','other_collection'
   )
   OR payment_method='old_card_entry'
   OR fact_direction NOT IN ('forward','reversal')
   OR status<>'effective'
   OR (fact_direction='forward' AND (amount_cents<=0 OR reversal_of<>''))
   OR (fact_direction='reversal' AND (amount_cents>=0 OR reversal_of=''));

SELECT COUNT(*) INTO @cf_invalid_balance
FROM eb_cashier_v3_balance_fact
WHERE fact_type<>'balance_changed'
   OR fact_direction NOT IN ('forward','reversal')
   OR status<>'effective'
   OR account_version=0
   OR (principal_delta_cents=0 AND bonus_delta_cents=0)
   OR (fact_direction='forward' AND reversal_of<>'')
   OR (fact_direction='reversal' AND reversal_of='');

SELECT COUNT(*) INTO @cf_invalid_performance
FROM eb_cashier_v3_performance_fact
WHERE fact_type<>performance_type
   OR performance_type NOT IN (
     'sales_performance_allocated','actual_performance_recorded',
     'consumption_performance_recorded','labor_performance_allocated'
   )
   OR fact_direction NOT IN ('forward','reversal')
   OR status<>'effective'
   OR allocation_weight_denominator=0
   OR allocation_weight_numerator>allocation_weight_denominator
   OR (performance_type IN ('sales_performance_allocated','labor_performance_allocated') AND (
     employee_id=0 OR employee_name_snapshot='' OR
     employee_type_snapshot NOT IN ('internal','partner','outsourced') OR
     employee_type_authority_version=0
   ))
   OR (performance_type IN ('actual_performance_recorded','consumption_performance_recorded') AND (
     employee_id<>0 OR employee_type_snapshot<>'' OR employee_type_authority_version<>0
   ))
   OR (fact_direction='forward' AND (amount_cents<0 OR reversal_of<>''))
   OR (fact_direction='reversal' AND (amount_cents>0 OR reversal_of=''));

SELECT COUNT(*) INTO @cf_orphan_reversals
FROM (
  SELECT reversal.fact_id
  FROM eb_cashier_v3_sale_fact reversal
  LEFT JOIN eb_cashier_v3_sale_fact original
    ON original.tenant_id=reversal.tenant_id
   AND original.store_id=reversal.store_id
   AND original.fact_id=reversal.reversal_of
   AND original.fact_direction='forward'
   AND original.fact_type=reversal.fact_type
   AND original.source_line_id=reversal.source_line_id
  WHERE reversal.fact_direction='reversal' AND original.id IS NULL
  UNION ALL
  SELECT reversal.fact_id
  FROM eb_cashier_v3_payment_fact reversal
  LEFT JOIN eb_cashier_v3_payment_fact original
    ON original.tenant_id=reversal.tenant_id
   AND original.store_id=reversal.store_id
   AND original.fact_id=reversal.reversal_of
   AND original.fact_direction='forward'
   AND original.fact_type=reversal.fact_type
   AND original.source_line_id=reversal.source_line_id
  WHERE reversal.fact_direction='reversal' AND original.id IS NULL
  UNION ALL
  SELECT reversal.fact_id
  FROM eb_cashier_v3_balance_fact reversal
  LEFT JOIN eb_cashier_v3_balance_fact original
    ON original.tenant_id=reversal.tenant_id
   AND original.store_id=reversal.store_id
   AND original.fact_id=reversal.reversal_of
   AND original.fact_direction='forward'
   AND original.fact_type=reversal.fact_type
   AND original.source_line_id=reversal.source_line_id
  WHERE reversal.fact_direction='reversal' AND original.id IS NULL
  UNION ALL
  SELECT reversal.fact_id
  FROM eb_cashier_v3_performance_fact reversal
  LEFT JOIN eb_cashier_v3_performance_fact original
    ON original.tenant_id=reversal.tenant_id
   AND original.store_id=reversal.store_id
   AND original.fact_id=reversal.reversal_of
   AND original.fact_direction='forward'
   AND original.fact_type=reversal.fact_type
   AND original.source_line_id=reversal.source_line_id
  WHERE reversal.fact_direction='reversal' AND original.id IS NULL
) cf_orphans;

SELECT COUNT(*) INTO @cf_actual_mismatch
FROM (
  SELECT facts.tenant_id,facts.business_event_no,facts.fact_direction,
    SUM(facts.cash_amount) AS cash_amount,
    SUM(facts.external_amount) AS external_amount,
    SUM(facts.actual_amount) AS actual_amount,
    SUM(facts.actual_rows) AS actual_rows
  FROM (
    SELECT tenant_id,business_event_no,fact_direction,
      amount_cents AS cash_amount,0 AS external_amount,0 AS actual_amount,0 AS actual_rows
    FROM eb_cashier_v3_payment_fact
    UNION ALL
    SELECT tenant_id,business_event_no,fact_direction,0,
      IF(performance_type='sales_performance_allocated'
        AND employee_type_snapshot IN ('partner','outsourced'),amount_cents,0),
      IF(performance_type='actual_performance_recorded',amount_cents,0),
      IF(performance_type='actual_performance_recorded',1,0)
    FROM eb_cashier_v3_performance_fact
    WHERE performance_type IN ('sales_performance_allocated','actual_performance_recorded')
  ) facts
  GROUP BY facts.tenant_id,facts.business_event_no,facts.fact_direction
  HAVING actual_rows=0 OR actual_amount<>cash_amount-external_amount
) cf_actual_bad;

SELECT COUNT(*) INTO @cf_event_mismatch
FROM (
  SELECT business_event_no,command_idempotency_key,tenant_id,organization_id,
    store_id,member_id,operator_id,business_date,occurred_at,settled_at,recorded_at
  FROM eb_cashier_v3_sale_fact
  UNION ALL
  SELECT business_event_no,command_idempotency_key,tenant_id,organization_id,
    store_id,member_id,operator_id,business_date,occurred_at,settled_at,recorded_at
  FROM eb_cashier_v3_payment_fact
  UNION ALL
  SELECT business_event_no,command_idempotency_key,tenant_id,organization_id,
    store_id,member_id,operator_id,business_date,occurred_at,settled_at,recorded_at
  FROM eb_cashier_v3_balance_fact
  UNION ALL
  SELECT business_event_no,command_idempotency_key,tenant_id,organization_id,
    store_id,member_id,operator_id,business_date,occurred_at,settled_at,recorded_at
  FROM eb_cashier_v3_performance_fact
) fact
LEFT JOIN eb_cashier_v3_business_event event_row
  ON event_row.event_no=fact.business_event_no
 AND event_row.tenant_id=fact.tenant_id
 AND event_row.store_id=fact.store_id
WHERE event_row.event_no IS NULL
   OR event_row.command_idempotency_key<>fact.command_idempotency_key
   OR event_row.organization_id<>fact.organization_id
   OR event_row.member_id<>fact.member_id
   OR event_row.operator_id<>fact.operator_id
   OR event_row.business_date<>fact.business_date
   OR event_row.occurred_at<>fact.occurred_at
   OR event_row.settled_at<>fact.settled_at
   OR event_row.recorded_at<>fact.recorded_at;

SET @cf_failures := @cf_failures
  + IF(@cf_target_tables=4,0,1)
  + IF(@cf_target_columns=162,0,1)
  + IF(@cf_target_indexes=40,0,1)
  + IF(@cf_common_contract_columns=124,0,1)
  + IF(@cf_critical_types=24,0,1)
  + IF(@cf_invalid_sale=0,0,1)
  + IF(@cf_invalid_payment=0,0,1)
  + IF(@cf_invalid_balance=0,0,1)
  + IF(@cf_invalid_performance=0,0,1)
  + IF(@cf_orphan_reversals=0,0,1)
  + IF(@cf_actual_mismatch=0,0,1)
  + IF(@cf_event_mismatch=0,0,1);

SELECT
  @cf_target_tables AS target_table_count,
  @cf_target_columns AS target_column_count,
  @cf_target_indexes AS target_index_count,
  @cf_common_contract_columns AS common_contract_column_count,
  @cf_critical_types AS critical_type_count,
  @cf_invalid_sale AS invalid_sale_count,
  @cf_invalid_payment AS invalid_payment_count,
  @cf_invalid_balance AS invalid_balance_count,
  @cf_invalid_performance AS invalid_performance_count,
  @cf_orphan_reversals AS orphan_reversal_count,
  @cf_actual_mismatch AS actual_performance_mismatch_count,
  @cf_event_mismatch AS business_event_mismatch_count,
  @cf_failures AS verification_failure_count;

SET @cf_finish_sql := IF(
  @cf_failures=0,
  'SELECT ''VERIFY_OK'' AS verify_result',
  'SELECT * FROM STOP_CASHIER_V3_CHECKOUT_FACT_VERIFY_FAILED'
);
PREPARE cf_finish_stmt FROM @cf_finish_sql;
EXECUTE cf_finish_stmt;
DEALLOCATE PREPARE cf_finish_stmt;
