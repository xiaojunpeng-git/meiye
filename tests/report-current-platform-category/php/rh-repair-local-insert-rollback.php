<?php

declare(strict_types=1);

// Run inside the local app container; this fixture must never connect to RH.
$backend = getenv('BACKEND_ROOT');
if ($backend !== '/var/www/html' || getenv('RH_LOCAL_REPAIR_TEST') !== '1') {
    throw new RuntimeException('local app container and explicit test flag required');
}
putenv('RH_REPAIR_LIB_ONLY=1');
require $backend . '/database/upgrades/2026-10-13-瑞昊商品分类与卡项分摊修复/02-修复.php';

$config = parse_ini_file($backend . '/.env', true, INI_SCANNER_RAW)['DATABASE'] ?? [];
if (($config['HOSTNAME'] ?? '') !== 'mysql' || ($config['DATABASE'] ?? '') !== 'ruihao') {
    throw new RuntimeException('unexpected local test database');
}
$db = new PDO('mysql:host=mysql;port=' . $config['HOSTPORT'] . ';dbname=ruihao;charset=utf8mb4',
    $config['USERNAME'], $config['PASSWORD'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$ddl = file_get_contents($backend . '/database/upgrades/2026-10-13-瑞昊商品分类与卡项分摊修复/01-审计表.sql');
$db->exec(str_replace('CREATE TABLE IF NOT EXISTS', 'CREATE TEMPORARY TABLE IF NOT EXISTS', $ddl));

$saleId = 'CFS-RH-REPAIR-LOCAL-CONTRACT';
$sale = [
    'fact_id' => $saleId, 'tenant_id' => '0', 'organization_id' => '1',
    'store_id' => 126, 'member_id' => 1, 'order_id' => 'RH-REPAIR-LOCAL-ORDER',
    'source_line_id' => 'RH-REPAIR-LOCAL-LINE', 'business_date' => '2026-10-11',
    'occurred_at' => 1791676800, 'settled_at' => 1791676800,
    'business_event_no' => 'RH-REPAIR-LOCAL-EVENT',
    'command_idempotency_key' => 'RH-REPAIR-LOCAL-COMMAND',
    'sale_amount_cents' => 168000,
];
$plan = ['products' => [], 'cards' => [[
    'sale' => $sale, 'initial_cash_cents' => 70000,
    'categories' => [[
        'receipt_id' => 'RH-REPAIR-LOCAL-RECEIPT', 'issue_no' => 1,
        'product_id' => 97181, 'category_id' => 26332,
        'category_name' => '卡项', 'weight' => 268000,
        'sale_cents' => 168000, 'cash_cents' => 70000,
    ]],
    'items' => [[
        'receipt_id' => 'RH-REPAIR-LOCAL-RECEIPT', 'issue_no' => 1,
        'product_id' => 97181, 'category_id' => 26332,
        'category_name' => '卡项', 'name' => '测试项目', 'count' => 1,
        'weight' => 268000, 'sale_cents' => 168000, 'cash_cents' => 70000,
    ]],
]]];

$db->beginTransaction();
try {
    $result = rhApply($db, $plan, str_repeat('a', 64), 'local-rollback-only');
    if ($result !== ['products' => 0, 'card_sales' => 1, 'category_facts' => 1, 'item_facts' => 1]) {
        throw new RuntimeException('local insert count mismatch');
    }
    foreach (['category', 'item'] as $kind) {
        $table = 'eb_cashier_v3_card_sale_' . $kind . '_allocation_fact';
        $row = $db->query("SELECT sale_amount_cents,cash_performance_amount_cents FROM $table
            WHERE sale_fact_id='CFS-RH-REPAIR-LOCAL-CONTRACT'")->fetch();
        if (!$row || (int)$row['sale_amount_cents'] !== 168000
            || (int)$row['cash_performance_amount_cents'] !== 70000) {
            throw new RuntimeException($kind . ' fact amount mismatch');
        }
    }
    $audit = $db->query("SELECT after_json FROM mig_rh_20261013_category_and_card_repair
        WHERE source_id='CFS-RH-REPAIR-LOCAL-CONTRACT'")->fetchColumn();
    if (!$audit || count(json_decode($audit, true, 512, JSON_THROW_ON_ERROR)['item_natural_keys']) !== 1) {
        throw new RuntimeException('repair audit missing natural keys');
    }
    echo "PASS local category/item facts and audit inserted with conserved amounts\n";
} finally {
    $db->rollBack();
}

foreach (['category', 'item'] as $kind) {
    $table = 'eb_cashier_v3_card_sale_' . $kind . '_allocation_fact';
    if ((int)$db->query("SELECT COUNT(*) FROM $table
        WHERE sale_fact_id='CFS-RH-REPAIR-LOCAL-CONTRACT'")->fetchColumn() !== 0) {
        throw new RuntimeException('local insert did not roll back');
    }
}
echo "PASS local fact insertion rolled back\n";
