<?php
declare(strict_types=1);

// Verify idempotence and preservation on the explicitly identified local DB.
// All exercised metadata mutations are rolled back; no position grant is added.
require '/var/www/html/vendor/autoload.php';
$app = new \think\App('/var/www/html/');
$app->env->load('/var/www/html/.env'); $app->initialize();
set_exception_handler(static function (Throwable $error): void {
    file_put_contents('php://stderr', $error->getMessage() . "\n" . $error->getTraceAsString() . "\n"); exit(1);
});
use think\facade\Db;
use app\services\organization\StorePermissionCatalogServices;
$config = Db::connect()->getConfig();
if (getenv('R53_LOCAL_ONLY') !== '1' || ($config['hostname'] ?? '') !== 'mysql'
    || ($config['database'] ?? '') !== 'ruihao') throw new RuntimeException('local database guard');
$catalog = new StorePermissionCatalogServices();
$before = Db::name($catalog::TABLE)->where('id', 1)->find();
$grants = Db::name('job_position_channel_rule')->order('id')->select()->toArray();
$audits = (int)Db::name('store_v3_permission_audit')->count();
Db::startTrans();
try {
    $rows = json_decode($before['rules_json'], true);
    $without = array_values(array_filter($rows, static fn(array $row): bool => (int)$row['id'] !== 301051));
    Db::name($catalog::TABLE)->where('id', 1)->update(['rules_json' => json_encode($without)]);
    $catalog->initializeCardToBalancePermission();
    $installed = Db::name($catalog::TABLE)->where('id', 1)->find();
    $afterRows = json_decode($installed['rules_json'], true);
    $preserved = array_values(array_filter($afterRows, static fn(array $row): bool => (int)$row['id'] !== 301051));
    if ($preserved !== $without || count($afterRows) !== count($without) + 1) throw new RuntimeException('metadata rewritten');
    if ((int)$installed['revision'] !== (int)$before['revision'] + 1) throw new RuntimeException('missing audit revision');
    $catalog->initializeCardToBalancePermission();
    if (Db::name($catalog::TABLE)->where('id', 1)->find() !== $installed) throw new RuntimeException('non-idempotent install');
    if (Db::name('job_position_channel_rule')->order('id')->select()->toArray() !== $grants) throw new RuntimeException('grants changed');
    if ((int)Db::name('store_v3_permission_audit')->count() !== $audits + 1) throw new RuntimeException('audit count invalid');
    $catalog->snapshot();
    $changed = $catalog->grantCardToBalanceToExistingCashierPositions();
    $afterGrants = Db::name('job_position_channel_rule')->order('id')->select()->toArray();
    $count = 0;
    foreach ($grants as $index => $original) {
        $after = $afterGrants[$index];
        if ($after === $original) continue;
        $count++;
        if ($original['channel'] !== 'store_v3' || (int)$original['status'] !== 1
            || !in_array('cashier.v3.cashier', $catalog::explicitFeatures(array_map('intval', explode(',', $original['rules']))), true)
            || $after['rules'] !== rtrim($original['rules'], ',') . ',301051'
            || (int)$after['version'] !== (int)$original['version'] + 1
            || array_diff_key($after, array_flip(['rules', 'version', 'update_time']))
                !== array_diff_key($original, array_flip(['rules', 'version', 'update_time']))) {
            throw new RuntimeException('grant backfill widened or changed unrelated fields');
        }
    }
    if ($count !== $changed || $catalog->grantCardToBalanceToExistingCashierPositions() !== 0
        || Db::name('job_position_channel_rule')->order('id')->select()->toArray() !== $afterGrants) {
        throw new RuntimeException('grant backfill not idempotent');
    }
} finally { Db::rollback(); }
if (Db::name($catalog::TABLE)->where('id', 1)->find() !== $before
    || (int)Db::name('store_v3_permission_audit')->count() !== $audits) throw new RuntimeException('rollback failed');
echo "PASS R53 local permission install and authorized cashier-only backfill; preservation, idempotence, audit and rollback\n";
