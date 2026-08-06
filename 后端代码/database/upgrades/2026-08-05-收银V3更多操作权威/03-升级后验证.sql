-- upgrade_key: 20260805-001-cashier-v3-more-actions-authority
SET NAMES utf8mb4;
SET @cma_db := DATABASE();
SET @cma_failures := 0;

SELECT COUNT(*) INTO @cma_columns
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@cma_db AND (
  (TABLE_NAME='eb_cashier_v3_workspace_draft' AND COLUMN_NAME IN (
    'order_note','supplement_enabled','supplement_business_date','supplement_reason',
    'supplement_operator_id','supplement_operator_name_snapshot','supplement_operated_at'
  )) OR
  (TABLE_NAME IN (
    'eb_cashier_v3_checkout_request','eb_cashier_v3_sales_order'
  ) AND COLUMN_NAME IN (
    'order_note','supplement_enabled','supplement_reason','supplement_operator_id',
    'supplement_operator_name_snapshot','supplement_operated_at'
  )) OR
  (TABLE_NAME IN (
    'eb_cashier_v3_workspace_line','eb_cashier_v3_checkout_line_draft',
    'eb_cashier_v3_sales_order_line'
  ) AND COLUMN_NAME IN (
    'configured_cost_cents','debt_amount_cents','price_change_reason','price_changed_by',
    'price_changed_by_name_snapshot','price_changed_at'
  )) OR
  (TABLE_NAME='eb_cashier_v3_sale_fact' AND COLUMN_NAME='debt_amount_cents')
);

SELECT COUNT(*) INTO @cma_invalid_workspace_headers
FROM eb_cashier_v3_workspace_draft
WHERE supplement_enabled NOT IN (0,1)
   OR (supplement_enabled=0 AND (
     supplement_business_date IS NOT NULL OR supplement_reason<>''
     OR supplement_operator_id<>0 OR supplement_operator_name_snapshot<>''
     OR supplement_operated_at<>0
   ))
   OR (supplement_enabled=1 AND (
     supplement_business_date IS NULL OR supplement_reason=''
     OR supplement_operator_id=0 OR supplement_operator_name_snapshot=''
     OR supplement_operated_at=0
   ));

SELECT COUNT(*) INTO @cma_invalid_workspace_price_audits
FROM eb_cashier_v3_workspace_line
WHERE (price_changed_at=0 AND (
        price_change_reason<>'' OR price_changed_by<>0
        OR price_changed_by_name_snapshot<>''
      ))
   OR (price_changed_at>0 AND (
        price_change_reason='' OR price_changed_by=0 OR price_changed_by_name_snapshot=''
      ));

SET @cma_failures := @cma_failures
  + IF(@cma_columns=38,0,1)
  + IF(@cma_invalid_workspace_headers=0,0,1)
  + IF(@cma_invalid_workspace_price_audits=0,0,1);

SELECT @cma_columns AS expected_column_count,
       @cma_invalid_workspace_headers AS invalid_workspace_headers,
       @cma_invalid_workspace_price_audits AS invalid_workspace_price_audits,
       @cma_failures AS postcheck_failure_count;

SET @cma_finish_sql := IF(@cma_failures=0,
  'SELECT ''POSTCHECK_OK'' AS postcheck_result',
  'SELECT * FROM STOP_CASHIER_MORE_ACTIONS_POSTCHECK_FAILED');
PREPARE cma_finish_stmt FROM @cma_finish_sql;
EXECUTE cma_finish_stmt;
DEALLOCATE PREPARE cma_finish_stmt;
