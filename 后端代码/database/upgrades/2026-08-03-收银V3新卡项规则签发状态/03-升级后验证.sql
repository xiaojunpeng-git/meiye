-- upgrade_key: 20260803-005-cashier-v3-issued-card-rule-state-v1
SET NAMES utf8mb4;
SET @crs_db := DATABASE();
SET @crs_failures := 0;

SELECT COUNT(*) INTO @crs_tables FROM information_schema.TABLES
 WHERE TABLE_SCHEMA=@crs_db AND ENGINE='InnoDB'
   AND TABLE_NAME IN ('eb_cashier_v3_card_rule_state','eb_cashier_v3_card_rule_component');
SELECT COUNT(*) INTO @crs_state_columns FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA=@crs_db AND TABLE_NAME='eb_cashier_v3_card_rule_state';
SELECT COUNT(*) INTO @crs_component_columns FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA=@crs_db AND TABLE_NAME='eb_cashier_v3_card_rule_component';
SELECT COUNT(*) INTO @crs_state_indexes FROM information_schema.STATISTICS
 WHERE TABLE_SCHEMA=@crs_db AND TABLE_NAME='eb_cashier_v3_card_rule_state'
   AND INDEX_NAME IN ('uk_tenant_state','uk_tenant_receipt','uk_tenant_holder','idx_member_status','idx_issue_store','idx_sales_line');
SELECT COUNT(*) INTO @crs_component_indexes FROM information_schema.STATISTICS
 WHERE TABLE_SCHEMA=@crs_db AND TABLE_NAME='eb_cashier_v3_card_rule_component'
   AND INDEX_NAME IN ('uk_tenant_component','uk_state_relation','uk_tenant_legacy_detail','idx_holder_status','idx_project_status','idx_state_selection');
SELECT COUNT(*) INTO @crs_invalid_states FROM eb_cashier_v3_card_rule_state
 WHERE rule_type NOT IN ('normal','choice_kind','choice_count','time')
    OR rule_version=0 OR definition_version=0 OR state_version=0
    OR status NOT IN ('active','disabled','expired','exhausted')
    OR selected_kind_count>choice_limit
    OR (rule_type='choice_kind' AND choice_limit=0)
    OR (rule_type<>'choice_kind' AND (choice_limit<>0 OR selected_kind_count<>0))
    OR (rule_type='choice_count' AND (shared_total_times=0 OR shared_remaining_times>shared_total_times))
    OR (rule_type<>'choice_count' AND (shared_total_times<>0 OR shared_remaining_times<>0))
    OR validity_mode NOT IN (1,2,3)
    OR (rule_type='time' AND validity_mode=1)
    OR (validity_mode=1 AND (valid_from=0 OR valid_through<>0))
    OR (validity_mode IN (2,3) AND (valid_from=0 OR valid_through<=valid_from))
    OR immutable_fingerprint NOT REGEXP '^[0-9a-f]{64}$';
SELECT COUNT(*) INTO @crs_invalid_components
 FROM eb_cashier_v3_card_rule_component c
 LEFT JOIN eb_cashier_v3_card_rule_state s ON s.id=c.rule_state_id AND s.tenant_id=c.tenant_id
 WHERE s.id IS NULL OR c.card_holder_id<>s.card_holder_id OR c.state_version=0
    OR c.status NOT IN ('active','disabled','exhausted')
    OR c.remaining_times>c.total_times
    OR c.selection_status NOT IN ('candidate','selected','not_applicable')
    OR (s.rule_type='choice_kind' AND c.selection_status='not_applicable')
    OR (s.rule_type<>'choice_kind' AND c.selection_status<>'not_applicable')
    OR (s.rule_type IN ('choice_count','time') AND (c.total_times<>0 OR c.remaining_times<>0))
    OR (s.rule_type IN ('normal','choice_kind') AND c.total_times=0)
    OR (s.rule_type<>'time' AND c.writeoff_amount_cents<>0)
    OR c.immutable_fingerprint NOT REGEXP '^[0-9a-f]{64}$';

SET @crs_failures := @crs_failures
  + IF(@crs_tables=2,0,1)
  + IF(@crs_state_columns=29,0,1)
  + IF(@crs_component_columns=26,0,1)
  + IF(@crs_state_indexes=17,0,1)
  + IF(@crs_component_indexes=17,0,1)
  + IF(@crs_invalid_states=0,0,1)
  + IF(@crs_invalid_components=0,0,1);

SELECT @crs_tables AS exact_tables,
       @crs_state_columns AS state_columns,
       @crs_component_columns AS component_columns,
       @crs_state_indexes AS state_index_columns,
       @crs_component_indexes AS component_index_columns,
       @crs_invalid_states AS invalid_states,
       @crs_invalid_components AS invalid_components,
       @crs_failures AS postcheck_failure_count;
SET @crs_finish_sql := IF(@crs_failures=0,
  'SELECT ''POSTCHECK_OK'' AS postcheck_result',
  'SELECT * FROM STOP_ISSUED_CARD_RULE_STATE_POSTCHECK_FAILED');
PREPARE crs_finish_stmt FROM @crs_finish_sql;
EXECUTE crs_finish_stmt;
DEALLOCATE PREPARE crs_finish_stmt;
