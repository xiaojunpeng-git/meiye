<?php
declare(strict_types=1);

/** 只在本地事务测试来源门禁，不触发收款/结账，不修改历史订单。 */
$backend = getenv('BACKEND_ROOT') ?: dirname(__DIR__, 3) . '/后端代码';
require rtrim($backend, '/\\') . '/vendor/autoload.php';
use app\services\cashier\v3\config\CashierV3BusinessConfigServices;
use app\services\cashier\v3\settlement\CashierV3CheckoutBusinessSourceSelectionServices;
use think\facade\Db;
use think\exception\ValidateException;
(new \think\App(rtrim($backend, '/\\') . '/'))->initialize();
if (getenv('CHECKOUT_REFUND_SOURCE_LOCAL_TEST') !== '1'
    || config('database.connections.mysql.hostname') !== 'mysql'
    || config('database.connections.mysql.database') !== 'ruihao') throw new RuntimeException('Local Docker test only');
$config = new CashierV3BusinessConfigServices();
$service = new CashierV3CheckoutBusinessSourceSelectionServices();
$k = Db::name('cashier_v3_business_source')->where('parent_id', 0)->where('name', 'like', 'K%')->find();
$a = Db::name('cashier_v3_business_source')->where('parent_id', 0)->where('status', 1)->where('name', 'like', 'A%')->find();
$seed = Db::name('cashier_v3_checkout_business_source_selection')->where('id', '>', 0)->find();
if (!$k || !$a || !$seed) throw new RuntimeException('Local source fixtures required');
$checks = 0;
function checkCheckoutRefund(bool $ok, string $message): void {
    global $checks;
    if (!$ok) throw new RuntimeException($message);
    $checks++; echo 'PASS ' . $message . PHP_EOL;
}
/** 必须由明确的退款来源提示拒绝，不把任意程序异常当作门禁通过。 */
function rejectsCheckoutRefund(callable $action, string $message): void {
    try { $action(); } catch (ValidateException $error) {
        checkCheckoutRefund(strpos($error->getMessage(), 'K退款顾客仅用于退款统计') !== false, $message); return;
    }
    throw new RuntimeException('Expected rejection: ' . $message);
}
$request = 'CKR-' . bin2hex(random_bytes(20));
$tenant = (string)$seed['tenant_id']; $store = (int)$seed['store_id'];
$source = ['primarySourceId' => (int)$k['id'], 'primarySourceNameSnapshot' => 'A伪造名称'];
checkCheckoutRefund(!in_array((int)$k['id'], array_column($config->checkoutCatalog()['sources'], 'id'), true), 'Checkout catalog excludes K');
checkCheckoutRefund(in_array((int)$k['id'], array_column($config->sourceTree(true), 'id'), true), 'Platform/report source catalog retains K');
rejectsCheckoutRefund(function () use ($config, $k) { $config->assertCheckoutSourceAllowed((int)$k['id'], 'A伪造名称'); }, 'Canonical K ID rejects forged label');
rejectsCheckoutRefund(function () use ($config) { $config->assertCheckoutSourceAllowed(999999999, 'K退款顾客'); }, 'Old K snapshot rejects even if catalog row is missing');
Db::startTrans();
try {
    rejectsCheckoutRefund(function () use ($service, $request, $tenant, $store, $source) {
        $service->captureSaleSnapshotInTx($request, $tenant, $store, 1, $source);
    }, 'Final sale snapshot cannot capture K');
    checkCheckoutRefund(Db::name('cashier_v3_checkout_business_source_selection')->where('checkout_request_id', $request)->count() === 0, 'Rejected snapshot writes nothing');
    $save = new ReflectionMethod($service, 'saveSelectionInTx'); $save->setAccessible(true);
    rejectsCheckoutRefund(function () use ($save, $service, $request, $tenant, $store, $k) {
        $save->invoke($service, 'sale', $request, $tenant, $store, 1, ['primarySourceId' => (int)$k['id']], false);
    }, 'Old selection command rejects K');
    // 普通来源的历史名称照常保存，不扩展成来源全量实时配置校验。
    $ordinary = $service->captureSaleSnapshotInTx($request, $tenant, $store, 1,
        ['primarySourceId' => (int)$a['id'], 'primarySourceNameSnapshot' => 'A历史快照名称']);
    checkCheckoutRefund($ordinary['primarySourceNameSnapshot'] === 'A历史快照名称', 'Ordinary historical snapshot stays intact');
    $locked = $service->lockResolvedForSettlementInTx('sale', $request, $tenant, $store);
    checkCheckoutRefund($locked['primarySourceId'] === (int)$a['id'], 'Ordinary settlement source still resolves');
    // 模拟本次尚未结账的旧选择行，确认最终锁定不会绕过新门禁；读回仍不改历史。
    Db::name('cashier_v3_checkout_business_source_selection')->where('checkout_request_id', $request)
        ->update(['primary_source_id' => (int)$k['id'], 'primary_source_name_snapshot' => $k['name']]);
    rejectsCheckoutRefund(function () use ($service, $request, $tenant, $store) {
        $service->lockResolvedForSettlementInTx('sale', $request, $tenant, $store);
    }, 'Final settlement rejects stale K selection');
    checkCheckoutRefund($service->read('sale', $request, $tenant, $store)['primarySourceId'] === (int)$k['id'], 'Read-only historical projection is not rewritten');
    checkCheckoutRefund($service->read('sale', $request, $tenant, $store + 999999)['primarySourceId'] === 0, 'Source remains scoped to authorized store');
} finally { Db::rollback(); }
checkCheckoutRefund(Db::name('cashier_v3_checkout_business_source_selection')->where('checkout_request_id', $request)->count() === 0, 'All test selection rows rolled back');
echo 'checkout refund source integration: ' . $checks . " PASS\n";
