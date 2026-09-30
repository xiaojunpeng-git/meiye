<?php
declare(strict_types=1);

/**
 * JSJ 直读重导的只读验收门禁。
 * 来源数量必须实时读取 jsj；目标库不得依赖旧冻结表或副本，避免把旧快照的空值误判为本轮缺失。
 */
$args = array_fill_keys(array_slice($argv, 1), true);
if (!isset($args['--verify'], $args['--allow-ruihao'], $args['--after-import'], $args['--direct-jsj'])) {
    fwrite(STDERR, "Refusing to verify without --verify --allow-ruihao --after-import --direct-jsj\n");
    exit(2);
}
$cutoff = getenv('RH_JSJ_CUTOFF') ?: '2026-09-17 00:00:00';
$env = parse_ini_file(getenv('RH_ENV_FILE') ?: '/www/wwwroot/rh.cc3798.com/.env', true, INI_SCANNER_RAW);
$db = $env['DATABASE'] ?? [];
$target = getenv('RH_TARGET_DATABASE') ?: (string)($db['DATABASE'] ?? '');
if ($target !== 'ruihao') throw new RuntimeException("target guard failed: {$target}");
$pdo = new PDO('mysql:host=' . $db['HOSTNAME'] . ';port=' . $db['HOSTPORT'] . ';dbname=ruihao;charset=utf8mb4', $db['USERNAME'], $db['PASSWORD'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_general_ci');
$scalar = static fn(string $sql): int => (int)$pdo->query($sql)->fetchColumn();
$day = substr($cutoff, 0, 10);
$source = [
    'ordinary_rights' => $scalar("SELECT COUNT(*) FROM jsj.bd_CardDetail cd JOIN jsj.bd_ProjectAndTimes pt ON pt.CardDetailId=cd.Id JOIN jsj.bd_ProjectSet ps ON ps.Id=pt.ProjectId WHERE COALESCE(cd.fstate,0)<>-1 AND (cd.EffectiveDate>=DATE('{$day}') OR cd.EffectiveDate IS NULL) AND cd.pflag<>'0' AND COALESCE(pt.ShiFouUse,'')='' AND pt.Quantity-COALESCE(pt.UseQuantity,0)>0 AND cd.CardClassId<>'0091000112'"),
    'project_gifts' => $scalar("SELECT COUNT(*) FROM jsj.SS_SendProject g JOIN jsj.bd_ProjectSet ps ON ps.Id=g.ProjectId WHERE g.StartDate<=DATE('{$day}') AND g.EffectiveDate>=DATE('{$day}') AND g.Times-COALESCE(g.ConsumeQuantity,0)>0 AND g.Fstate='0'"),
    'long_cards' => $scalar("SELECT COUNT(*) FROM jsj.bd_CardDetail WHERE CardClassId='0091000112'"),
    'card_debts' => $scalar("SELECT COUNT(*) FROM jsj.bd_CardDetail WHERE ABS(COALESCE(NonePay,0))>=0.001"),
    'recharge_debts' => $scalar("SELECT COUNT(*) FROM jsj.SS_CustomerAddMoney r JOIN jsj.bd_CardDetail cd ON cd.Id=r.CardDetailId WHERE COALESCE(r.flag,0)<>-1 AND COALESCE(r.AddMoney,0)<>0 AND ABS(COALESCE(r.NonePay,0))>=0.001"),
];
$targetChecks = [
    'ordinary_rights' => $scalar("SELECT COUNT(*) FROM eb_store_order_cart_info WHERE cart_id LIKE 'RH-LINE-%'"),
    'project_gifts' => $scalar("SELECT COUNT(*) FROM eb_store_order_cart_info WHERE cart_id LIKE 'RH-PG-%'"),
    'goods_claimable' => $scalar("SELECT COUNT(*) FROM eb_cashier_v3_presale_claimable_line WHERE source_checkout_request_id='JSJ-20260917-R1'"),
    'long_card_states' => $scalar("SELECT COUNT(*) FROM eb_cashier_v3_card_rule_state WHERE receipt_id LIKE 'RH-TIME-102-%'"),
    'long_card_components' => $scalar("SELECT COUNT(*) FROM eb_cashier_v3_card_rule_component c JOIN eb_cashier_v3_card_rule_state s ON s.id=c.rule_state_id WHERE s.receipt_id LIKE 'RH-TIME-102-%'"),
    'debts' => $scalar("SELECT COUNT(*) FROM eb_store_debt WHERE debt_no LIKE 'RHD%'") ,
    'card_debt_authority' => $scalar("SELECT COUNT(*) FROM eb_cashier_v3_debt_authority d JOIN eb_store_debt b ON b.id=d.debt_id WHERE b.debt_no LIKE 'RHD%' AND b.remark LIKE 'source_kind=CARD;%'") ,
    'recharge_debt_authority' => $scalar("SELECT COUNT(*) FROM eb_cashier_v3_recharge_debt_authority d JOIN eb_store_debt b ON b.id=d.debt_id WHERE b.debt_no LIKE 'RHD%' AND b.remark LIKE 'source_kind=RECH;%'") ,
    'duplicate_nonempty_phone_groups' => $scalar("SELECT COUNT(*) FROM (SELECT phone FROM eb_user WHERE phone<>'' AND is_del=0 GROUP BY phone HAVING COUNT(*)>1) x"),
    'balance_invariant_errors' => $scalar("SELECT COUNT(*) FROM eb_user u JOIN mig_jsj_20260917_member_resolution m ON m.target_uid=u.uid WHERE ABS(COALESCE(u.now_money,0)-COALESCE(u.ben_money,0)-COALESCE(u.give_money,0))>0.001"),
    'positive_rights_zero_paid' => $scalar("SELECT COUNT(*) FROM eb_store_order_cart_info WHERE cart_id LIKE 'RH-LINE-%' AND total_price>0 AND pay_price=0"),
    'rights_amount_mismatch' => $scalar("SELECT COUNT(*) FROM eb_store_order_cart_info WHERE cart_id LIKE 'RH-LINE-%' AND total_price<>pay_price"),
    'invalid_project_link' => $scalar("SELECT COUNT(*) FROM eb_store_order_cart_info c LEFT JOIN eb_store_product p ON p.id=c.product_id WHERE (c.cart_id LIKE 'RH-LINE-%' OR c.cart_id LIKE 'RH-PG-%' OR c.cart_id LIKE 'RH-TIME-PROJECT-%') AND (p.id IS NULL OR p.is_del<>0 OR p.type<>1 OR p.product_type<>6)"),
    'project_cross_store' => $scalar("SELECT COUNT(*) FROM eb_store_order_cart_info c JOIN eb_store_order o ON o.id=c.oid JOIN eb_store_product p ON p.id=c.product_id WHERE (c.cart_id LIKE 'RH-LINE-%' OR c.cart_id LIKE 'RH-PG-%' OR c.cart_id LIKE 'RH-TIME-PROJECT-%') AND p.relation_id<>o.store_id"),
    'project_snapshot_missing' => $scalar("SELECT COUNT(*) FROM eb_store_order_cart_info c JOIN eb_store_product p ON p.id=c.product_id WHERE (c.cart_id LIKE 'RH-LINE-%' OR c.cart_id LIKE 'RH-PG-%' OR c.cart_id LIKE 'RH-TIME-PROJECT-%') AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(c.cart_info,'$.productInfo.store_name')),'')=''"),
    'project_snapshot_mismatch' => $scalar("SELECT COUNT(*) FROM eb_store_order_cart_info c JOIN eb_store_product p ON p.id=c.product_id WHERE (c.cart_id LIKE 'RH-LINE-%' OR c.cart_id LIKE 'RH-PG-%' OR c.cart_id LIKE 'RH-TIME-PROJECT-%') AND JSON_UNQUOTE(JSON_EXTRACT(c.cart_info,'$.productInfo.store_name'))<>p.store_name"),
    'order_store_mismatch' => $scalar("SELECT COUNT(*) FROM eb_store_order o JOIN eb_user u ON u.uid=o.uid WHERE o.order_id LIKE 'RH-%' AND o.store_id<>u.belong_store_id"),
    'goods_store_mismatch' => $scalar("SELECT COUNT(*) FROM eb_cashier_v3_presale_claimable_line g JOIN eb_user u ON u.uid=g.member_id WHERE g.source_checkout_request_id='JSJ-20260917-R1' AND g.store_id<>u.belong_store_id"),
];
$targetExpected = ['ordinary_rights' => $source['ordinary_rights'], 'project_gifts' => $source['project_gifts'], 'long_card_states' => $source['long_cards'], 'long_card_components' => $source['long_cards'], 'debts' => $source['card_debts'] + $source['recharge_debts'], 'card_debt_authority' => $source['card_debts'], 'recharge_debt_authority' => $source['recharge_debts']];
$checks = [];
foreach ($targetExpected as $name => $expected) $checks[$name] = ['expected' => $expected, 'actual' => $targetChecks[$name], 'passed' => $expected === $targetChecks[$name]];
foreach (['duplicate_nonempty_phone_groups','balance_invariant_errors','positive_rights_zero_paid','rights_amount_mismatch','invalid_project_link','project_cross_store','project_snapshot_missing','project_snapshot_mismatch','order_store_mismatch','goods_store_mismatch'] as $name) $checks[$name] = ['expected' => 0, 'actual' => $targetChecks[$name], 'passed' => $targetChecks[$name] === 0];
$old = [];
foreach (['eb_store_service_record','eb_cashier_v3_service_order','eb_cashier_v3_entitlement_writeoff_fact','eb_cashier_v3_entitlement_service_fact','eb_cashier_v3_reservation','eb_cashier_v3_performance_fact','eb_inventory_stock','eb_inventory_batch'] as $table) $old[$table] = $scalar("SELECT COUNT(*) FROM `{$table}`");
$old['eb_store_debt_without_RHD'] = $scalar("SELECT COUNT(*) FROM eb_store_debt WHERE debt_no NOT LIKE 'RHD%'");
foreach ($old as $name => $actual) $checks['old_' . $name] = ['expected' => 0, 'actual' => $actual, 'passed' => $actual === 0];
$failed = array_filter($checks, static fn(array $check): bool => !$check['passed']);
$result = ['target' => $target, 'source' => $source, 'target_counts' => $targetChecks, 'checks' => $checks, 'passed' => $failed === []];
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
exit($failed === [] ? 0 : 1);
