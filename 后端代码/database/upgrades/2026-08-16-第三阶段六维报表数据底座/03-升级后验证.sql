-- 仅验证，不修改数据。
SET NAMES utf8mb4;

SELECT TABLE_NAME, ENGINE, TABLE_COLLATION
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=DATABASE()
  AND TABLE_NAME IN (
    'eb_cashier_v3_report_organization_dimension',
    'eb_cashier_v3_report_consumption_tier',
    'eb_cashier_v3_report_consumption_tier_audit',
    'eb_cashier_v3_report_member_origin_evidence',
    'eb_cashier_v3_report_member_origin_evidence_audit',
    'eb_cashier_v3_report_member_store_assignment_period',
    'eb_cashier_v3_card_sale_item_allocation_fact',
    'eb_cashier_v3_report_annotation',
    'eb_cashier_v3_report_annotation_audit'
  )
ORDER BY TABLE_NAME;

SELECT tenant_id,tier_code,tier_name,lower_bound_cents,upper_bound_cents,sort_order,enabled,deleted_at,version
FROM eb_cashier_v3_report_consumption_tier
WHERE tenant_id='0'
ORDER BY sort_order,id;

SELECT COUNT(*) AS tier_count,
       SUM(deleted_at IS NULL AND enabled=1) AS active_tier_count,
       SUM(deleted_at IS NULL AND enabled=0) AS disabled_tier_count,
       SUM(deleted_at IS NOT NULL) AS soft_deleted_tier_count,
       SUM(lower_bound_cents<0) AS invalid_lower_bound_count,
       SUM(upper_bound_cents IS NOT NULL AND upper_bound_cents<=lower_bound_cents) AS invalid_range_count,
       SUM(deleted_at IS NOT NULL AND enabled<>0) AS invalid_soft_delete_state_count
FROM eb_cashier_v3_report_consumption_tier
WHERE tenant_id='0';

SELECT TABLE_NAME,COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=DATABASE()
  AND TABLE_NAME IN (
    'eb_cashier_v3_report_organization_dimension',
    'eb_cashier_v3_report_consumption_tier'
  )
  AND COLUMN_NAME='deleted_at'
ORDER BY TABLE_NAME,COLUMN_NAME;

SELECT TABLE_NAME,INDEX_NAME,
       GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') AS index_columns
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA=DATABASE()
  AND (TABLE_NAME='eb_cashier_v3_report_consumption_tier' AND INDEX_NAME='idx_tenant_enabled_order'
       OR TABLE_NAME='eb_cashier_v3_payment_sale_allocation_fact' AND INDEX_NAME='idx_scope_date_status'
       OR TABLE_NAME='eb_cashier_v3_card_operation' AND INDEX_NAME='idx_scope_business_date_status')
GROUP BY TABLE_NAME,INDEX_NAME
ORDER BY TABLE_NAME,INDEX_NAME;

SELECT COUNT(*) AS members_without_origin_evidence
FROM eb_user u
LEFT JOIN eb_cashier_v3_report_member_origin_evidence e
  ON e.tenant_id='0' AND e.member_id=u.uid
WHERE e.id IS NULL;

SELECT origin_type,evidence_status,COUNT(*) AS row_count
FROM eb_cashier_v3_report_member_origin_evidence
WHERE tenant_id='0'
GROUP BY origin_type,evidence_status
ORDER BY origin_type,evidence_status;

SELECT COUNT(*) AS invalid_assignment_period_count
FROM eb_cashier_v3_report_member_store_assignment_period
WHERE tenant_id='0'
  AND (valid_to_at IS NOT NULL AND valid_to_at<valid_from_at
       OR assigned=1 AND store_id=0
       OR assigned=0 AND store_id<>0);

SELECT COUNT(*) AS projected_staff_binding_rows_should_be_zero
FROM eb_cashier_v3_report_member_store_assignment_period p
JOIN eb_user_belong_store h
  ON p.source_type='USER_BELONG_STORE_HISTORY' AND p.source_event_id=CAST(h.id AS CHAR)
WHERE h.is_store=0;

SELECT COUNT(*) AS invalid_card_item_fact_count
FROM eb_cashier_v3_card_sale_item_allocation_fact
WHERE component_product_id=0 OR item_name_snapshot='' OR category_id_snapshot=0
   OR sale_amount_cents<0 OR cash_performance_amount_cents<0
   OR immutable_fingerprint='';

SELECT menu_name,menu_path,unique_auth,is_show,is_del
FROM eb_system_menus
WHERE unique_auth='admin-report-six-dimension'
   OR unique_auth LIKE 'admin-report-six-dimension-%'
   OR unique_auth='setting-shop-six-dimension-consumption-tier'
ORDER BY unique_auth;

SELECT COUNT(*) AS report_page_permission_count
FROM eb_system_menus
WHERE type=1 AND is_del=0 AND is_show=1
  AND unique_auth LIKE 'admin-report-six-dimension-six_dimension_%';

SELECT upgrade_key,title,task_file,executed_at,result_note
FROM eb_database_upgrade_log
WHERE upgrade_key='20260816-002-phase-three-six-dimension-report-foundation';

SET @phase3_report_table_count := (
  SELECT COUNT(*) FROM information_schema.TABLES
  WHERE TABLE_SCHEMA=DATABASE()
    AND TABLE_NAME IN (
      'eb_cashier_v3_report_organization_dimension',
      'eb_cashier_v3_report_consumption_tier',
      'eb_cashier_v3_report_consumption_tier_audit',
      'eb_cashier_v3_report_member_origin_evidence',
      'eb_cashier_v3_report_member_origin_evidence_audit',
      'eb_cashier_v3_report_member_store_assignment_period',
      'eb_cashier_v3_card_sale_item_allocation_fact'
    )
);
SET @phase3_manual_input_table_count := (
  SELECT COUNT(*) FROM information_schema.TABLES
  WHERE TABLE_SCHEMA=DATABASE()
    AND TABLE_NAME IN (
      'eb_cashier_v3_report_annotation',
      'eb_cashier_v3_report_annotation_audit'
    )
);
SET @phase3_tier_deleted_column_count := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE()
    AND TABLE_NAME='eb_cashier_v3_report_consumption_tier'
    AND COLUMN_NAME='deleted_at'
    AND COLUMN_TYPE='int(10) unsigned'
    AND IS_NULLABLE='YES'
);
SET @phase3_tier_order_index_count := (
  SELECT COUNT(*) FROM (
    SELECT INDEX_NAME
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA=DATABASE()
      AND TABLE_NAME='eb_cashier_v3_report_consumption_tier'
      AND INDEX_NAME='idx_tenant_enabled_order'
    GROUP BY INDEX_NAME
    HAVING GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',')='tenant_id,deleted_at,enabled,sort_order,id'
  ) phase3_tier_index
);
SET @phase3_payment_date_index_count := (
  SELECT COUNT(*) FROM (
    SELECT INDEX_NAME
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA=DATABASE()
      AND TABLE_NAME='eb_cashier_v3_payment_sale_allocation_fact'
      AND INDEX_NAME='idx_scope_date_status'
    GROUP BY INDEX_NAME
    HAVING GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',')='tenant_id,store_id,business_date,status,id'
  ) phase3_payment_index
);
SET @phase3_card_operation_date_index_count := (
  SELECT COUNT(*) FROM (
    SELECT INDEX_NAME
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA=DATABASE()
      AND TABLE_NAME='eb_cashier_v3_card_operation'
      AND INDEX_NAME='idx_scope_business_date_status'
    GROUP BY INDEX_NAME
    HAVING GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',')='tenant_id,store_id,business_date,operation_status,operation_type,id'
  ) phase3_card_operation_index
);
SET @phase3_default_tier_count := (
  SELECT COUNT(*) FROM eb_cashier_v3_report_consumption_tier
  WHERE tenant_id='0'
    AND tier_code IN ('gte_100000','50000_100000','30000_50000','10000_30000','5000_10000','0_5000')
);
SET @phase3_default_tier_audit_count := (
  SELECT COUNT(*) FROM eb_cashier_v3_report_consumption_tier_audit
  WHERE tenant_id='0' AND action='SEEDED'
    AND idempotency_key IN (
      'phase3-tier-seed:gte_100000','phase3-tier-seed:50000_100000',
      'phase3-tier-seed:30000_50000','phase3-tier-seed:10000_30000',
      'phase3-tier-seed:5000_10000','phase3-tier-seed:0_5000'
    )
);
SET @phase3_unexpected_seed_audit_count := (
  SELECT COUNT(*) FROM eb_cashier_v3_report_consumption_tier_audit
  WHERE tenant_id='0' AND action='SEEDED'
    AND idempotency_key NOT IN (
      'phase3-tier-seed:gte_100000','phase3-tier-seed:50000_100000',
      'phase3-tier-seed:30000_50000','phase3-tier-seed:10000_30000',
      'phase3-tier-seed:5000_10000','phase3-tier-seed:0_5000'
    )
);
SET @phase3_invalid_tier_count := (
  SELECT COUNT(*) FROM eb_cashier_v3_report_consumption_tier
  WHERE tenant_id='0'
    AND (lower_bound_cents<0
      OR (upper_bound_cents IS NOT NULL AND upper_bound_cents<=lower_bound_cents)
      OR (deleted_at IS NOT NULL AND enabled<>0))
);
SET @phase3_overlapping_tier_count := (
  SELECT COUNT(*)
  FROM eb_cashier_v3_report_consumption_tier a
  JOIN eb_cashier_v3_report_consumption_tier b
    ON b.tenant_id=a.tenant_id AND b.id>a.id
   AND b.lower_bound_cents<IFNULL(a.upper_bound_cents,9223372036854775807)
   AND a.lower_bound_cents<IFNULL(b.upper_bound_cents,9223372036854775807)
  WHERE a.tenant_id='0'
    AND a.deleted_at IS NULL AND a.enabled=1
    AND b.deleted_at IS NULL AND b.enabled=1
);
SET @phase3_deleted_without_audit_count := (
  SELECT COUNT(*)
  FROM eb_cashier_v3_report_consumption_tier t
  WHERE t.tenant_id='0' AND t.deleted_at IS NOT NULL
    AND NOT EXISTS (
      SELECT 1 FROM eb_cashier_v3_report_consumption_tier_audit a
      WHERE a.tenant_id=t.tenant_id AND a.tier_code=t.tier_code AND a.action='DELETED'
    )
);
SET @phase3_members_without_origin_count := (
  SELECT COUNT(*)
  FROM eb_user u
  LEFT JOIN eb_cashier_v3_report_member_origin_evidence e
    ON e.tenant_id='0' AND e.member_id=u.uid
  WHERE e.id IS NULL
);
SET @phase3_invalid_assignment_count := (
  SELECT COUNT(*) FROM eb_cashier_v3_report_member_store_assignment_period
  WHERE tenant_id='0'
    AND (valid_to_at IS NOT NULL AND valid_to_at<valid_from_at
      OR (assigned=1 AND store_id=0)
      OR (assigned=0 AND store_id<>0))
);
SET @phase3_projected_staff_binding_count := (
  SELECT COUNT(*)
  FROM eb_cashier_v3_report_member_store_assignment_period p
  JOIN eb_user_belong_store h
    ON p.source_type='USER_BELONG_STORE_HISTORY' AND p.source_event_id=CAST(h.id AS CHAR)
  WHERE h.is_store=0
);
SET @phase3_invalid_card_item_count := (
  SELECT COUNT(*) FROM eb_cashier_v3_card_sale_item_allocation_fact
  WHERE component_product_id=0 OR item_name_snapshot='' OR category_id_snapshot=0
    OR sale_amount_cents<0 OR cash_performance_amount_cents<0
    OR immutable_fingerprint=''
);
SET @phase3_report_permission_count := (
  SELECT COUNT(*) FROM eb_system_menus
  WHERE type=1 AND is_del=0 AND is_show=1
    AND unique_auth LIKE 'admin-report-six-dimension-six_dimension_%'
);
SET @phase3_parent_menu_count := (
  SELECT COUNT(*) FROM eb_system_menus
  WHERE type=1 AND is_del=0 AND is_show=1 AND unique_auth='admin-report-six-dimension'
);
SET @phase3_setting_menu_count := (
  SELECT COUNT(*) FROM eb_system_menus
  WHERE type=1 AND is_del=0 AND is_show=1 AND unique_auth='setting-shop-six-dimension-consumption-tier'
);
SET @phase3_upgrade_log_count := (
  SELECT COUNT(*) FROM eb_database_upgrade_log
  WHERE upgrade_key='20260816-002-phase-three-six-dimension-report-foundation'
);
SET @phase3_verification_failure_count :=
    IF(@phase3_report_table_count=7,0,1)
  + IF(@phase3_manual_input_table_count=2,0,1)
  + IF(@phase3_tier_deleted_column_count=1,0,1)
  + IF(@phase3_tier_order_index_count=1,0,1)
  + IF(@phase3_payment_date_index_count=1,0,1)
  + IF(@phase3_card_operation_date_index_count=1,0,1)
  + IF(@phase3_default_tier_count=6,0,1)
  + IF(@phase3_default_tier_audit_count=6,0,1)
  + IF(@phase3_unexpected_seed_audit_count=0,0,1)
  + IF(@phase3_invalid_tier_count=0,0,1)
  + IF(@phase3_overlapping_tier_count=0,0,1)
  + IF(@phase3_deleted_without_audit_count=0,0,1)
  + IF(@phase3_members_without_origin_count=0,0,1)
  + IF(@phase3_invalid_assignment_count=0,0,1)
  + IF(@phase3_projected_staff_binding_count=0,0,1)
  + IF(@phase3_invalid_card_item_count=0,0,1)
  + IF(@phase3_report_permission_count=6,0,1)
  + IF(@phase3_parent_menu_count=1,0,1)
  + IF(@phase3_setting_menu_count=1,0,1)
  + IF(@phase3_upgrade_log_count=1,0,1);

SELECT @phase3_report_table_count AS report_table_count,
       @phase3_manual_input_table_count AS manual_input_table_count,
       @phase3_tier_deleted_column_count AS tier_deleted_column_count,
       @phase3_tier_order_index_count AS tier_order_index_count,
       @phase3_payment_date_index_count AS payment_date_index_count,
       @phase3_card_operation_date_index_count AS card_operation_date_index_count,
       @phase3_default_tier_count AS default_tier_count,
       @phase3_default_tier_audit_count AS default_tier_audit_count,
       @phase3_unexpected_seed_audit_count AS unexpected_seed_audit_count,
       @phase3_invalid_tier_count AS invalid_tier_count,
       @phase3_overlapping_tier_count AS overlapping_tier_count,
       @phase3_deleted_without_audit_count AS deleted_without_audit_count,
       @phase3_members_without_origin_count AS members_without_origin_count,
       @phase3_invalid_assignment_count AS invalid_assignment_count,
       @phase3_projected_staff_binding_count AS projected_staff_binding_count,
       @phase3_invalid_card_item_count AS invalid_card_item_count,
       @phase3_report_permission_count AS report_permission_count,
       @phase3_parent_menu_count AS parent_menu_count,
       @phase3_setting_menu_count AS setting_menu_count,
       @phase3_upgrade_log_count AS upgrade_log_count,
       @phase3_verification_failure_count AS verification_failure_count;

SELECT IF(@phase3_verification_failure_count=0,'POSTCHECK_OK','POSTCHECK_FAILED') AS postcheck_result;
