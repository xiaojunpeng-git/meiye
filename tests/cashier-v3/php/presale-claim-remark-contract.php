<?php
declare(strict_types=1);

/** Check note input and old idempotency fingerprints without writing a claim or stock. */
require __DIR__ . '/../../../后端代码/vendor/autoload.php';

use app\services\cashier\v3\presale\CashierV3PresaleClaimServices;

$service = new CashierV3PresaleClaimServices();
$method = new ReflectionMethod($service, 'claimCommand');
$method->setAccessible(true);
$base = ['idempotency_key' => 'claim-remark-test-001', 'claimable_line_id' => 'claimable-001', 'quantity' => 2, 'business_date' => '2026-10-04'];
$plain = $method->invoke($service, $base);
$expectedOld = hash('sha256', json_encode(['claimable-001', 2, '2026-10-04'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
if ($plain['fingerprint'] !== $expectedOld || $plain['remark'] !== '') throw new RuntimeException('OLD_CLAIM_REPLAY_CHANGED');
$noted = $method->invoke($service, $base + ['remark' => '  先领两件  ']);
if ($noted['remark'] !== '先领两件' || $noted['fingerprint'] === $plain['fingerprint']) throw new RuntimeException('CLAIM_REMARK_NOT_IN_REQUEST');
try {
    $method->invoke($service, $base + ['remark' => str_repeat('字', 501)]);
    throw new RuntimeException('LONG_REMARK_ACCEPTED');
} catch (InvalidArgumentException $error) {
    if ($error->getMessage() !== 'presale_claim_remark_invalid') throw $error;
}

$root = dirname(__DIR__, 3);
$files = [
    'store route' => $root . '/后端代码/route/store.php',
    'permission' => $root . '/后端代码/app/http/middleware/store/StoreCkeckRoleMiddleware.php',
    'service' => $root . '/后端代码/app/services/cashier/v3/presale/CashierV3PresaleClaimServices.php',
    'migration' => $root . '/后端代码/database/upgrades/2026-10-04-R9-预售赠送领用备注/01-正式升级.sql',
    'view' => $root . '/前端代码/cashier-v3/src/views/PresaleClaimView.vue',
];
foreach ($files as $name => $path) if (!is_file($path)) throw new RuntimeException('MISSING_' . $name);
if (strpos(file_get_contents($files['store route']), 'PresaleClaim/remark') === false) throw new RuntimeException('REMARK_ROUTE_MISSING');
if (strpos(file_get_contents($files['permission']), 'presale_claim.create') === false) throw new RuntimeException('REMARK_PERMISSION_MISSING');
if (strpos(file_get_contents($files['service']), 'CLAIM_REMARK_HISTORY_TABLE') === false) throw new RuntimeException('REMARK_HISTORY_WRITE_MISSING');
if (strpos(file_get_contents($files['migration']), 'uk_claim_version') === false) throw new RuntimeException('REMARK_AUDIT_MISSING');
$view = file_get_contents($files['view']);
if (strpos($view, 'updateStorePresaleClaimRemark') === false) throw new RuntimeException('REMARK_EDIT_UI_MISSING');
if (strpos($view, 'class="presale-detail-table__remark">{{ row.remark || \'-\' }}</td>') === false
    || preg_match('/class="presale-detail-table__actions"[^>]*>.*?@click="openRemark\(row\)">修改备注</s', $view) !== 1) {
    throw new RuntimeException('REMARK_BUTTON_NOT_IN_ACTION_COLUMN');
}
echo "PRESALE_CLAIM_REMARK_CONTRACT_OK\n";
