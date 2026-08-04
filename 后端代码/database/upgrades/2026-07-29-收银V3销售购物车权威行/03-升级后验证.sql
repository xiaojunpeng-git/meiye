-- upgrade_key: 20260729-008-cashier-v3-sale-cart-authority
SET NAMES utf8mb4;
SET @c2s_db := DATABASE();
SET @c2s_failures := 0;

SELECT COUNT(*) INTO @c2s_exact_columns
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@c2s_db AND TABLE_NAME='eb_cashier_v3_workspace_line' AND (
  (COLUMN_NAME IN ('catalog_product_id','catalog_sku_id','unit_price_cents','original_unit_price_cents')
    AND COLUMN_TYPE='bigint(20) unsigned' AND IS_NULLABLE='NO' AND COLUMN_DEFAULT='0') OR
  (COLUMN_NAME='catalog_product_type' AND COLUMN_TYPE='tinyint(3) unsigned'
    AND IS_NULLABLE='NO' AND COLUMN_DEFAULT='0') OR
  (COLUMN_NAME='authority_fingerprint' AND COLUMN_TYPE='char(64)' AND IS_NULLABLE='NO'
    AND COLUMN_DEFAULT='' AND CHARACTER_SET_NAME='ascii' AND COLLATION_NAME='ascii_bin') OR
  (COLUMN_NAME='authority_snapshot_json' AND COLUMN_TYPE='mediumtext' AND IS_NULLABLE='YES')
);
SET @c2s_failures := @c2s_failures + IF(@c2s_exact_columns=7,0,1);

SELECT COUNT(*) INTO @c2s_exact_index
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA=@c2s_db AND TABLE_NAME='eb_cashier_v3_workspace_line'
  AND INDEX_NAME='idx_catalog_source' AND NON_UNIQUE=1 AND INDEX_TYPE='BTREE' AND SUB_PART IS NULL
  AND CONCAT(SEQ_IN_INDEX,':',COLUMN_NAME) IN (
    '1:catalog_product_id','2:catalog_sku_id','3:line_role','4:id'
  );
SET @c2s_failures := @c2s_failures + IF(@c2s_exact_index=4,0,1);

SELECT COUNT(DISTINCT INDEX_NAME) INTO @c2s_relation_leading_index
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA=@c2s_db AND TABLE_NAME='eb_store_card_related'
  AND SEQ_IN_INDEX=1 AND COLUMN_NAME='card_product_id';
SET @c2s_failures := @c2s_failures + IF(@c2s_relation_leading_index>=1,0,1);

SELECT COUNT(DISTINCT INDEX_NAME) INTO @c2s_component_leading_index
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA=@c2s_db AND TABLE_NAME='eb_store_card_related'
  AND SEQ_IN_INDEX=1 AND COLUMN_NAME='product_id';
SET @c2s_failures := @c2s_failures + IF(@c2s_component_leading_index>=1,0,1);

SELECT COUNT(*) INTO @c2s_bad_rows
FROM eb_cashier_v3_workspace_line
WHERE
  (line_role='entitlement_service' AND (
    catalog_product_id<>0 OR catalog_sku_id<>0 OR unit_price_cents<>0
    OR original_unit_price_cents<>0 OR authority_fingerprint<>'' OR authority_snapshot_json IS NOT NULL
  ))
  OR
  (line_role='sale' AND (
    catalog_product_id=0 OR catalog_sku_id=0 OR catalog_product_type NOT IN (0,4,5,6)
    OR original_unit_price_cents<unit_price_cents
    OR authority_fingerprint NOT REGEXP '^[a-f0-9]{64}$'
    OR authority_snapshot_json IS NULL OR authority_snapshot_json=''
    OR holder_id<>0 OR source_detail_id<>0
    OR (catalog_product_type=6 AND project_id<>catalog_product_id)
    OR (catalog_product_type<>6 AND project_id<>0)
  ));
SET @c2s_failures := @c2s_failures + IF(@c2s_bad_rows=0,0,1);

SELECT IF(@c2s_failures=0,'POSTCHECK_OK','POSTCHECK_FAILED') AS postcheck_result,
  @c2s_failures AS failure_count,
  @c2s_bad_rows AS invalid_workspace_rows,
  @c2s_relation_leading_index AS card_definition_leading_indexes,
  @c2s_component_leading_index AS component_reverse_leading_indexes;
SET @c2s_abort_sql := IF(
  @c2s_failures=0,
  'SELECT ''POSTCHECK_CONTINUE'' AS gate',
  'SELECT * FROM STOP_C2_SALE_CART_AUTHORITY_POSTCHECK_FAILED'
);
PREPARE c2s_abort_stmt FROM @c2s_abort_sql;
EXECUTE c2s_abort_stmt;
DEALLOCATE PREPARE c2s_abort_stmt;
