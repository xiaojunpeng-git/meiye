<?php
declare(strict_types=1);

$root = dirname(__DIR__, 3);
$provider = (string)file_get_contents($root . '/后端代码/app/services/query/provider/MemberUnifiedQueryProvider.php');
$failures = [];
foreach ([
    "Db::name('cashier_v3_entitlement_service_fact')",
    "->where('service_status', 'completed')",
    'COUNT(DISTINCT business_date) AS visit_count',
    'MAX(business_date) AS latest_date',
    "->where('tenant_id', \$tenantId)",
    "->whereIn('store_id', \$visible)",
] as $needle) {
    if (strpos($provider, $needle) === false) $failures[] = 'missing V3 visit authority: ' . $needle;
}
foreach (["Db::name('store_order_writeoff')", 'COUNT(DISTINCT {$businessDay}) AS visit_count'] as $forbidden) {
    $visitStart = strpos($provider, 'protected function visitSummaries');
    $visitEnd = strpos($provider, 'protected function serviceCraftsmenSummary', $visitStart);
    $visitMethod = substr($provider, $visitStart, $visitEnd - $visitStart);
    if (strpos($visitMethod, $forbidden) !== false) $failures[] = 'legacy visit authority remains: ' . $forbidden;
}
if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}
echo "member visit fact contract: PASS\n";
