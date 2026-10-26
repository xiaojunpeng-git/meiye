<?php
declare(strict_types=1);

/** Clears only operational facts and their projections; masters/configuration/migration source maps are retained. */
$args = array_fill_keys(array_slice($argv, 1), true);
$execute = isset($args['--execute'], $args['--allow-ruihao'], $args['--clear-business-facts']);
$env = parse_ini_file(getenv('RH_ENV_FILE') ?: '/www/wwwroot/rh.cc3798.com/.env', true, INI_SCANNER_RAW);
$db = $env['DATABASE'] ?? [];
$target = getenv('RH_TARGET_DATABASE') ?: (string)($db['DATABASE'] ?? '');
if ($target !== 'ruihao') throw new RuntimeException("target guard failed: {$target}");
$pdo = new PDO('mysql:host='.$db['HOSTNAME'].';port='.$db['HOSTPORT'].';dbname=ruihao;charset=utf8mb4', $db['USERNAME'], $db['PASSWORD'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);

// Children appear before their facts/parents. Do not replace this explicit allow-list with a wildcard.
$tables = [
    'eb_cashier_v3_debt_repayment_collection','eb_cashier_v3_debt_repayment','eb_cashier_v3_debt_repayment_draft','eb_cashier_v3_debt_item_personnel_authority','eb_cashier_v3_debt_authority',
    'eb_cashier_v3_recharge_debt_repayment_payment','eb_cashier_v3_recharge_debt_repayment','eb_cashier_v3_recharge_debt_authority','eb_cashier_v3_recharge_checkout_payment_draft','eb_cashier_v3_recharge_checkout_request',
    'eb_cashier_v3_card_operation_settlement','eb_cashier_v3_card_operation_line','eb_cashier_v3_card_operation','eb_cashier_v3_card_purchase_receipt','eb_cashier_v3_card_rule_component','eb_cashier_v3_card_rule_state','eb_cashier_v3_card_state','eb_cashier_v3_multi_card_upgrade_source','eb_cashier_v3_multi_card_upgrade',
    'eb_cashier_v3_direct_gift_item','eb_cashier_v3_direct_gift_authority','eb_cashier_v3_recharge_gift_item','eb_cashier_v3_recharge_gift_authority','eb_cashier_v3_gift_fact',
    'eb_cashier_v3_presale_claim','eb_cashier_v3_presale_claim_batch','eb_cashier_v3_presale_claimable_line',
    'eb_cashier_v3_reservation_staff_schedule','eb_cashier_v3_reservation_entitlement_occupation','eb_cashier_v3_reservation_operation','eb_cashier_v3_reservation_line','eb_cashier_v3_reservation',
    'eb_cashier_v3_service_order_staff_assignment','eb_cashier_v3_service_order_entitlement_guard','eb_cashier_v3_service_order_operation','eb_cashier_v3_service_order_line','eb_cashier_v3_service_order','eb_cashier_v3_entitlement_completion_receipt','eb_cashier_v3_entitlement_writeoff_fact','eb_cashier_v3_entitlement_service_fact','eb_cashier_v3_entitlement_reversal_fact','eb_cashier_v3_entitlement_debt_guard','eb_cashier_v3_entitlement_debt_guard_mutation','eb_cashier_v3_entitlement_occupation_version','eb_cashier_v3_entitlement_resource_version','eb_cashier_v3_resource_version','eb_cashier_v3_service_record_adjustment_operation','eb_cashier_v3_service_record_void_operation',
    'eb_cashier_v3_order_lifecycle_benefit_reversal','eb_cashier_v3_order_lifecycle_debt_reversal','eb_cashier_v3_order_lifecycle_financial_reversal','eb_cashier_v3_order_lifecycle_refund_line','eb_cashier_v3_order_lifecycle_operation','eb_cashier_v3_order_center_void_operation','eb_cashier_v3_order_reopen_draft',
    'eb_cashier_v3_payment_sale_allocation_fact','eb_cashier_v3_card_sale_category_allocation_fact','eb_cashier_v3_card_sale_item_allocation_fact','eb_cashier_v3_payment_collection_batch','eb_cashier_v3_payment_collection','eb_cashier_v3_payment_fact','eb_cashier_v3_sale_inventory_receipt','eb_cashier_v3_sales_order_line','eb_cashier_v3_sales_order','eb_cashier_v3_sale_fact','eb_cashier_v3_balance_fact','eb_cashier_v3_performance_fact','eb_cashier_v3_sales_manager_fact',
    'eb_cashier_v3_checkout_resource_plan_row','eb_cashier_v3_checkout_resource_plan','eb_cashier_v3_checkout_source_reference','eb_cashier_v3_checkout_business_source_selection','eb_cashier_v3_checkout_payment_draft','eb_cashier_v3_checkout_line_draft','eb_cashier_v3_checkout_request','eb_cashier_v3_hang_order_line','eb_cashier_v3_hang_order','eb_cashier_v3_workspace_line','eb_cashier_v3_workspace_draft','eb_cashier_v3_store_session',
    'eb_cashier_v3_customer_guide_round_fact','eb_cashier_v3_customer_lifecycle_fact','eb_cashier_v3_customer_lifecycle_projection','eb_cashier_v3_report_sale_dimension_fact','eb_cashier_v3_report_member_store_assignment_period','eb_cashier_v3_group_dashboard_daily_aggregate','eb_cashier_v3_state_context',
    'eb_cashier_v3_outbox_attempt','eb_cashier_v3_outbox','eb_cashier_v3_consumer_once','eb_cashier_v3_command_receipt','eb_cashier_v3_business_event','eb_cashier_v3_business_document_no','eb_cashier_create_side_effect_task','eb_cashier_create_side_effect_idem','eb_cashier_create_side_effect',
    'eb_inventory_batch_consumption_fact','eb_inventory_batch_movement_fact','eb_inventory_consumption_receipt','eb_inventory_batch_transfer_line','eb_inventory_batch_transfer_document','eb_inventory_cross_transfer_batch_allocation','eb_inventory_cross_transfer_line','eb_inventory_cross_transfer_document','eb_inventory_cross_transfer_reversal_operation','eb_inventory_stock_count_line','eb_inventory_stock_count_document','eb_inventory_stock_request_revision','eb_inventory_stock_request_fulfillment_reversal','eb_inventory_stock_request_fulfillment','eb_inventory_stock_request_line','eb_inventory_stock_request_lifecycle_operation','eb_inventory_stock_request_document','eb_inventory_salon_usage_line','eb_inventory_salon_usage_document','eb_inventory_shortage_cost_adjustment','eb_inventory_shortage_cost_cursor','eb_inventory_shortage_fact','eb_inventory_batch','eb_inventory_stock','eb_inventory_unified_query_command_receipt','eb_inventory_v3_import_error','eb_inventory_v3_import_record',
    'eb_store_salon_stock_usage_detail','eb_store_salon_stock_usage','eb_store_product_stock_detail','eb_store_product_stock_order','eb_store_product_stock_record','eb_store_product_stock_count','eb_store_stock_request_notice','eb_store_stock_request_detail','eb_store_stock_request','eb_store_stock_transfer_detail','eb_store_stock_transfer',
    'eb_store_service_feedback','eb_store_service_log','eb_store_service_record','eb_store_service','eb_store_reservation_order','eb_store_writeoff_staff_change_log','eb_store_writeoff_batch','eb_store_order_writeoff','eb_store_order_terminal_operation','eb_store_order_reopen_draft','eb_store_order_refund','eb_store_order_promotions','eb_store_order_economize','eb_store_order_invoice','eb_store_order_status',
    'eb_store_debt_repay','eb_store_debt_item','eb_store_debt','eb_user_card_holder','eb_user_card','eb_store_order_cart_info','eb_store_order','eb_user_money','eb_user_bill','eb_user_recharge','eb_member_exclusive_service_change','eb_member_exclusive_service','eb_yeji_commission','eb_refund_side_effect_once','eb_refund_side_effect_outbox','eb_fuyou_payment_attempt'
];
$existing = array_flip($pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema='ruihao'")->fetchAll(PDO::FETCH_COLUMN));
$counts = [];
foreach ($tables as $table) if (isset($existing[$table])) $counts[$table] = (int)$pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
if (!$execute) {
    echo json_encode(['mode'=>'dry-run','tables'=>$counts,'total_rows'=>array_sum($counts)], JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(0);
}
$pdo->exec("CREATE TABLE IF NOT EXISTS mig_jsj_20260917_cleanup_audit (id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,executed_at datetime NOT NULL,table_name varchar(128) NOT NULL,deleted_rows bigint unsigned NOT NULL,run_key varchar(64) NOT NULL,KEY idx_run(run_key)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
$pdo->beginTransaction();
try {
    foreach ($counts as $table => $before) {
        if ($before === 0) continue;
        $deleted = $pdo->exec("DELETE FROM `{$table}`");
        $audit = $pdo->prepare('INSERT INTO mig_jsj_20260917_cleanup_audit(executed_at,table_name,deleted_rows,run_key) VALUES(NOW(),?,?,?)');
        $audit->execute([$table, $deleted, 'JSJ-20260917-R1']);
    }
    // Cashier/inventory projections held in product counters must not outlive the deleted facts.
    $pdo->exec('UPDATE eb_store_product SET sales=0,stock=0,defective_stock=0');
    $pdo->exec('UPDATE eb_store_product_attr_value SET sales=0,stock=0,sum_stock=0,defective_stock=0');
    $pdo->exec("UPDATE eb_user SET now_money=0,give_money=0,ben_money=0 WHERE is_del=0");
    // These map rows reference deleted order/recharge facts; keep member/store/product/card-number maps.
    $pdo->exec("DELETE FROM mig_rh_source_map WHERE target_type IN ('store_order_type11','user_recharge')");
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
} finally {
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
}
echo json_encode(['mode'=>'executed','tables'=>$counts,'total_rows'=>array_sum($counts)], JSON_UNESCAPED_UNICODE) . PHP_EOL;
