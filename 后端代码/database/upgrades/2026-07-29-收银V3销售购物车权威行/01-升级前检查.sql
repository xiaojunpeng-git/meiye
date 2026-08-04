-- upgrade_key: 20260729-008-cashier-v3-sale-cart-authority
-- Read-only, MySQL 5.6 compatible.
SET NAMES utf8mb4;
SET @c2s_db := DATABASE();
SET @c2s_failures := 0;

SELECT COUNT(*) INTO @c2s_table_ok
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@c2s_db AND TABLE_NAME='eb_cashier_v3_workspace_line' AND ENGINE='InnoDB';
SET @c2s_failures := @c2s_failures + IF(@c2s_table_ok=1,0,1);

SELECT COUNT(*) INTO @c2s_legacy_tables
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@c2s_db AND ENGINE='InnoDB' AND TABLE_NAME IN (
  'eb_store_product','eb_store_product_attr_value','eb_store_product_category','eb_store_card_related'
);
SET @c2s_failures := @c2s_failures + IF(@c2s_legacy_tables=4,0,1);

SELECT COUNT(*) INTO @c2s_legacy_columns
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@c2s_db AND (
  (TABLE_NAME='eb_store_product' AND COLUMN_NAME IN (
    'id','pid','type','relation_id','product_type','store_name','cate_id','keyword','unit_name','sort',
    'is_show','is_del','is_verify','is_inventory','allow_negative_stock','card_num','card_num_type'
  )) OR
  (TABLE_NAME='eb_store_product_attr_value' AND COLUMN_NAME IN (
    'id','product_id','product_type','unique','suk','price','ot_price','stock','code','bar_code',
    'is_show','type','write_times','write_valid','write_days','write_start','write_end'
  )) OR
  (TABLE_NAME='eb_store_product_category' AND COLUMN_NAME IN ('id','cate_name','type','relation_id','is_show')) OR
  (TABLE_NAME='eb_store_card_related' AND COLUMN_NAME IN (
    'id','card_product_id','product_id','product_type','product_attr_unique','cost','price','write_times','status'
  ))
);
SET @c2s_failures := @c2s_failures + IF(@c2s_legacy_columns=48,0,1);

SELECT COUNT(*) INTO @c2s_named_columns
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@c2s_db AND TABLE_NAME='eb_cashier_v3_workspace_line'
  AND COLUMN_NAME IN (
    'catalog_product_id','catalog_sku_id','catalog_product_type','unit_price_cents',
    'original_unit_price_cents','authority_fingerprint','authority_snapshot_json'
  );
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
SET @c2s_failures := @c2s_failures + IF(@c2s_named_columns=@c2s_exact_columns,0,1);

SELECT COUNT(*) INTO @c2s_named_index
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA=@c2s_db AND TABLE_NAME='eb_cashier_v3_workspace_line'
  AND INDEX_NAME='idx_catalog_source';
SELECT COUNT(*) INTO @c2s_exact_index
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA=@c2s_db AND TABLE_NAME='eb_cashier_v3_workspace_line'
  AND INDEX_NAME='idx_catalog_source' AND NON_UNIQUE=1 AND INDEX_TYPE='BTREE' AND SUB_PART IS NULL
  AND CONCAT(SEQ_IN_INDEX,':',COLUMN_NAME) IN (
    '1:catalog_product_id','2:catalog_sku_id','3:line_role','4:id'
  );
SET @c2s_failures := @c2s_failures + IF(
  @c2s_named_index=0 OR (@c2s_named_index=4 AND @c2s_exact_index=4),0,1
);

SELECT COUNT(*) INTO @c2s_relation_named_index
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA=@c2s_db AND TABLE_NAME='eb_store_card_related'
  AND INDEX_NAME='idx_c2_card_definition';
SELECT COUNT(*) INTO @c2s_relation_exact_index
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA=@c2s_db AND TABLE_NAME='eb_store_card_related'
  AND INDEX_NAME='idx_c2_card_definition' AND NON_UNIQUE=1 AND INDEX_TYPE='BTREE'
  AND SUB_PART IS NULL AND CONCAT(SEQ_IN_INDEX,':',COLUMN_NAME) IN (
    '1:card_product_id','2:id'
  );
SET @c2s_failures := @c2s_failures + IF(
  @c2s_relation_named_index=0
    OR (@c2s_relation_named_index=2 AND @c2s_relation_exact_index=2),
  0,
  1
);

SELECT COUNT(*) INTO @c2s_reverse_named_index
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA=@c2s_db AND TABLE_NAME='eb_store_card_related'
  AND INDEX_NAME='idx_c2_component_reverse';
SELECT COUNT(*) INTO @c2s_reverse_exact_index
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA=@c2s_db AND TABLE_NAME='eb_store_card_related'
  AND INDEX_NAME='idx_c2_component_reverse' AND NON_UNIQUE=1 AND INDEX_TYPE='BTREE'
  AND SUB_PART IS NULL AND CONCAT(SEQ_IN_INDEX,':',COLUMN_NAME) IN (
    '1:product_id','2:card_product_id','3:id'
  );
SET @c2s_failures := @c2s_failures + IF(
  @c2s_reverse_named_index=0
    OR (@c2s_reverse_named_index=3 AND @c2s_reverse_exact_index=3),
  0,
  1
);

SELECT COUNT(*) INTO @c2s_log_exists
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@c2s_db AND TABLE_NAME='eb_database_upgrade_log';
SET @c2s_key_used := 0;
SET @c2s_log_sql := IF(
  @c2s_log_exists=1,
  'SELECT COUNT(*) INTO @c2s_key_used FROM `eb_database_upgrade_log` WHERE `upgrade_key`=''20260729-008-cashier-v3-sale-cart-authority''',
  'SET @c2s_key_used:=0'
);
PREPARE c2s_log_stmt FROM @c2s_log_sql;
EXECUTE c2s_log_stmt;
DEALLOCATE PREPARE c2s_log_stmt;
SET @c2s_failures := @c2s_failures + IF(@c2s_log_exists=1,0,1) + IF(@c2s_key_used=0,0,1);

SELECT IF(@c2s_failures=0,'PRECHECK_OK','PRECHECK_FAILED') AS precheck_result,
  @c2s_failures AS failure_count,
  @c2s_named_columns AS existing_new_columns,
  @c2s_named_index AS existing_index_rows,
  @c2s_relation_named_index AS existing_relation_index_rows,
  @c2s_reverse_named_index AS existing_reverse_index_rows;
SET @c2s_abort_sql := IF(
  @c2s_failures=0,
  'SELECT ''PRECHECK_CONTINUE'' AS gate',
  'SELECT * FROM STOP_C2_SALE_CART_AUTHORITY_PRECHECK_FAILED'
);
PREPARE c2s_abort_stmt FROM @c2s_abort_sql;
EXECUTE c2s_abort_stmt;
DEALLOCATE PREPARE c2s_abort_stmt;
